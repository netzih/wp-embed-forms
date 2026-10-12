<?php

namespace EmbedForms\Campaign;

use EmbedForms\Db\Db;
use EmbedForms\Db\Forms;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Processor;

/**
 * A campaign's running total, donor count and recent donors, from the
 * form's payments: approved charges and renewals less refunds, in the
 * processor's current mode (so test payments count only while the form is
 * in test mode), plus offline donations, plus the campaign's offsets.
 * Cached for a minute and dropped whenever a payment or entry changes.
 */
final class Totals {

  public const RECENT = 50;

  private const CACHE_TTL = 60;

  /**
   * @return array{raised: string, goal: ?string, percent: ?float, donors: int, recent: array[]}
   */
  public static function forForm(array $form): array {
    $key = self::cacheKey((int) $form['id']);
    $cached = get_transient($key);
    if (is_array($cached)) {
      return $cached;
    }
    $data = self::compute($form);
    set_transient($key, $data, self::CACHE_TTL);
    return $data;
  }

  public static function flush(int $formId): void {
    delete_transient(self::cacheKey($formId));
  }

  public static function flushForEntry(int $entryId): void {
    $db = Db::wpdb();
    $formId = (int) $db->get_var($db->prepare('SELECT form_id FROM ' . Db::table('entries') . ' WHERE id = %d', $entryId));
    if ($formId) {
      self::flush($formId);
    }
  }

  private static function compute(array $form): array {
    $db = Db::wpdb();
    $campaign = $form['settings']['campaign'];
    [$where, $params] = self::where($form);
    $entries = Db::table('entries');
    $payments = Db::table('payments');

    $sums = $db->get_row($db->prepare(
      "SELECT COALESCE(SUM(p.amount - p.refunded_amount), 0) AS raised, COUNT(DISTINCT e.id) AS donors
       FROM {$payments} p JOIN {$entries} e ON e.id = p.entry_id WHERE {$where}",
      ...$params
    ), ARRAY_A);
    $raisedCents = (Money::toCents((string) ($sums['raised'] ?? '0')) ?? 0) + (Money::toCents($campaign['offset_amount']) ?? 0);
    $donors = (int) ($sums['donors'] ?? 0) + (int) $campaign['offset_donors'];

    $rows = $db->get_results($db->prepare(
      "SELECT e.id, e.form_version, e.data_json, e.amount, MIN(p.created_at) AS first_paid
       FROM {$payments} p JOIN {$entries} e ON e.id = p.entry_id WHERE {$where}
       GROUP BY e.id, e.form_version, e.data_json, e.amount ORDER BY first_paid DESC, e.id DESC LIMIT %d",
      ...array_merge($params, [self::RECENT])
    ), ARRAY_A);

    $schemas = [];
    $secret = wp_salt('auth');
    $recent = [];
    foreach ((array) $rows as $row) {
      $version = (int) $row['form_version'];
      $schemas[$version] ??= Forms::schemaAt($form, $version);
      $data = json_decode((string) $row['data_json'], TRUE);
      $recent[] = Donors::publicDonor($schemas[$version], [
        'id' => (int) $row['id'],
        'data' => is_array($data) ? $data : [],
        'amount' => $row['amount'],
        'first_paid' => $row['first_paid'],
      ], $campaign, $secret);
    }

    $goalCents = Money::toCents($campaign['goal']);
    return [
      'raised' => Money::fromCents($raisedCents),
      'goal' => $goalCents ? Money::fromCents($goalCents) : NULL,
      'percent' => $goalCents ? round(min(100, $raisedCents / $goalCents * 100), 1) : NULL,
      'donors' => $donors,
      'recent' => $recent,
    ];
  }

  /**
   * Payments that count: approved (or partly refunded) charges and
   * renewals of this form's live entries, in the current mode or offline.
   *
   * @return array{0: string, 1: array}
   */
  private static function where(array $form): array {
    ['gateway' => $gateway] = Processor::forForm($form);
    $mode = Processor::mode($gateway);
    $where = "e.form_id = %d AND e.status NOT IN ('spam', 'trash', 'refunded')
      AND p.kind IN ('charge', 'renewal') AND p.status IN ('approved', 'partially_refunded')
      AND (p.gateway = 'offline' OR p.mode = %s" . ($mode === 'live' ? " OR p.mode = ''" : '') . ')';
    return [$where, [(int) $form['id'], $mode]];
  }

  private static function cacheKey(int $formId): string {
    return 'embed_forms_campaign_' . $formId;
  }

}
