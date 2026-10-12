<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Db;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Notifications\Mailer;
use EmbedForms\Payments\Checkout;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Processor;
use EmbedForms\Schema\FormSchema;

/**
 * Donations received by cash, check, wire and the like, entered by an
 * administrator: an entry with the donor's answers and one approved payment
 * on the 'offline' processor, so they count in totals, exports and the
 * campaign donor list like any other. Nothing is ever sent to a processor
 * for them.
 */
final class OfflineDonations {

  public const METHODS = ['check', 'cash', 'wire', 'daf', 'stock', 'other'];

  public static function methodLabel(string $method): string {
    $labels = [
      'check' => __('Check', 'embed-forms'),
      'cash' => __('Cash', 'embed-forms'),
      'wire' => __('Bank transfer', 'embed-forms'),
      'daf' => __('Donor-advised fund', 'embed-forms'),
      'stock' => __('Stock', 'embed-forms'),
      'other' => __('Other', 'embed-forms'),
    ];
    return $labels[$method] ?? $method;
  }

  /**
   * The entry's offline payment, if it has one.
   */
  public static function paymentOf(int $entryId): ?array {
    foreach (Payments::forEntry($entryId) as $payment) {
      if ($payment['gateway'] === Processor::OFFLINE && $payment['kind'] === 'charge') {
        return $payment;
      }
    }
    return NULL;
  }

  /**
   * @param array $details
   *   amount (normalized), method, reference, date (Y-m-d), note, notify.
   *
   * @return int
   *   The new entry id.
   */
  public static function record(array $form, array $values, array $details): int {
    $values = self::withAmount($form['schema'], $values, $details['amount']);
    $entryId = Entries::create($form, $values, [
      'status' => 'paid',
      'email' => \EmbedForms\Submit\SubmissionHandler::payerEmail($form, $values),
      'amount' => $details['amount'],
    ]);
    if ($entryId <= 0) {
      return 0;
    }
    $received = self::receivedAt($details['date']);
    Db::wpdb()->update(Db::table('entries'), ['created_at' => $received, 'is_read' => 1], ['id' => $entryId]);
    $paymentId = Payments::create([
      'entry_id' => $entryId,
      'kind' => 'charge',
      'status' => 'approved',
      'amount' => $details['amount'],
      'gateway' => Processor::OFFLINE,
      'method' => $details['method'],
      'refnum' => mb_substr($details['reference'], 0, 64),
      'created_at' => $received,
    ]);
    Notes::add($entryId, trim(sprintf(
      __('Offline donation of %1$s (%2$s%3$s) received %4$s, entered by %5$s. %6$s', 'embed-forms'),
      Money::format($details['amount']),
      self::methodLabel($details['method']),
      $details['reference'] !== '' ? ' ' . $details['reference'] : '',
      $details['date'],
      self::userName(),
      $details['note']
    )));

    $entry = Entries::find($entryId);
    $payment = Payments::find($paymentId);
    /**
     * An offline donation was entered (after embed_forms_entry_submitted).
     */
    do_action('embed_forms_entry_submitted', $entry, $form);
    do_action('embed_forms_offline_donation_added', $payment, $entry, $form);
    do_action('embed_forms_entry_changed', (int) $form['id']);

    if (!empty($details['notify']) && $entry) {
      $pricing = self::pricing($form['schema'], $details['amount']);
      try {
        Mailer::entrySubmitted($form, $entry, Checkout::tags($payment, $pricing, NULL), ['payment_summary' => Checkout::summaryHtml($pricing, $payment, NULL)]);
      }
      catch (\Throwable $e) {
        error_log('[embed-forms] offline donation email failed for entry ' . $entryId . ': ' . $e->getMessage());
      }
    }
    return $entryId;
  }

  /**
   * Changes an entry's offline payment.
   *
   * @return string[]
   *   What changed, for the history.
   */
  public static function update(array $entry, array $payment, array $details): array {
    $changed = [];
    if ($details['amount'] !== (string) $payment['amount']) {
      $changed[] = sprintf(__('amount %1$s → %2$s', 'embed-forms'), Money::format((string) $payment['amount']), Money::format($details['amount']));
    }
    if ($details['method'] !== $payment['method']) {
      $changed[] = sprintf(__('method %s', 'embed-forms'), self::methodLabel($details['method']));
    }
    if ($details['reference'] !== $payment['refnum']) {
      $changed[] = sprintf(__('reference "%s"', 'embed-forms'), $details['reference']);
    }
    $received = self::receivedAt($details['date']);
    if (substr($received, 0, 10) !== substr(self::receivedAt(self::localDate($payment['created_at'])), 0, 10)) {
      $changed[] = sprintf(__('received %s', 'embed-forms'), $details['date']);
    }
    else {
      $received = $payment['created_at'];
    }
    if (!$changed) {
      return [];
    }
    Payments::update($payment['id'], [
      'amount' => $details['amount'],
      'method' => $details['method'],
      'refnum' => mb_substr($details['reference'], 0, 64),
      'created_at' => $received,
    ]);
    Db::wpdb()->update(Db::table('entries'), ['amount' => $details['amount'], 'created_at' => $received, 'updated_at' => Db::now()], ['id' => $entry['id']]);
    return $changed;
  }

  /**
   * The form's (first) amount field holds the donation, so the entry reads
   * like an online one.
   */
  public static function withAmount(array $schema, array $values, string $amount): array {
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if ($field['type'] === 'amount') {
        $values[$id] = $amount;
        break;
      }
    }
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if ($field['type'] === 'frequency') {
        $values[$id] = 'once';
      }
      elseif ($field['type'] === 'product') {
        unset($values[$id]);
      }
    }
    return $values;
  }

  /**
   * Checks the posted payment details.
   *
   * @return array{0: array, 1: array<string, string>}
   *   The details and errors keyed by input.
   */
  public static function details(array $post): array {
    $errors = [];
    $amount = Money::normalize(str_replace(['$', ',', ' '], '', (string) ($post['amount'] ?? '')));
    if ($amount === NULL || $amount === '0.00') {
      $errors['amount'] = __('Enter the amount received, like 180 or 180.00.', 'embed-forms');
    }
    $method = (string) ($post['method'] ?? '');
    $date = (string) ($post['date'] ?? '');
    $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$parsed || $parsed->format('Y-m-d') !== $date) {
      $errors['date'] = __('Enter the date the donation was received.', 'embed-forms');
    }
    return [[
      'amount' => (string) $amount,
      'method' => in_array($method, self::METHODS, TRUE) ? $method : 'other',
      'reference' => sanitize_text_field((string) ($post['reference'] ?? '')),
      'date' => $date,
      'note' => sanitize_textarea_field((string) ($post['note'] ?? '')),
      'notify' => !empty($post['notify']),
    ], $errors];
  }

  /**
   * Noon on that day in the site's time zone, as UTC.
   */
  private static function receivedAt(string $date): string {
    $local = new \DateTimeImmutable($date . ' 12:00:00', wp_timezone());
    return $local->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
  }

  public static function localDate(string $utc): string {
    return wp_date('Y-m-d', strtotime($utc . ' UTC'));
  }

  private static function pricing(array $schema, string $amount): array {
    $label = __('Donation', 'embed-forms');
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if ($field['type'] === 'amount') {
        $label = $field['label'] ?: $label;
        break;
      }
    }
    return [
      'cents' => Money::toCents($amount) ?? 0,
      'total' => $amount,
      'lines' => [['field' => '', 'label' => $label, 'amount' => $amount]],
      'frequency' => 'once',
      'recurring_times' => 0,
    ];
  }

  public static function userName(): string {
    $user = wp_get_current_user();
    return $user && $user->exists() ? $user->display_name : __('an administrator', 'embed-forms');
  }

}
