<?php

namespace EmbedForms\Db;

/**
 * Recurring payments charged by this site against a saved card (a USAePay
 * saved card, or a Stripe customer and payment method). Nothing is
 * scheduled at the processor.
 */
final class Subscriptions {

  public const STATUSES = ['active', 'failing', 'cancelled', 'completed'];

  public static function find(int $id): ?array {
    $db = Db::wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM ' . Db::table('subscriptions') . ' WHERE id = %d', $id), ARRAY_A);
    return $row ? self::hydrate($row) : NULL;
  }

  /**
   * @return array[]
   */
  public static function forEntry(int $entryId): array {
    $db = Db::wpdb();
    $rows = $db->get_results($db->prepare('SELECT * FROM ' . Db::table('subscriptions') . ' WHERE entry_id = %d ORDER BY id ASC', $entryId), ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  /**
   * Subscriptions of one processor whose next charge is due, oldest first.
   * Rows from before processors were recorded (gateway '') are USAePay's.
   *
   * @return array[]
   */
  public static function due(string $gateway, string $mode, string $now, int $limit = 50): array {
    $db = Db::wpdb();
    $gateways = $gateway === 'usaepay' ? ['usaepay', ''] : [$gateway, $gateway];
    $rows = $db->get_results($db->prepare(
      'SELECT * FROM ' . Db::table('subscriptions') . " WHERE status IN ('active', 'failing') AND gateway IN (%s, %s) AND mode = %s AND next_charge IS NOT NULL AND next_charge <= %s ORDER BY next_charge ASC LIMIT %d",
      $gateways[0], $gateways[1], $mode, $now, $limit
    ), ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  /**
   * @return array[]
   */
  public static function unresolved(): array {
    $rows = Db::wpdb()->get_results('SELECT * FROM ' . Db::table('subscriptions') . " WHERE marker_json IS NOT NULL AND marker_json <> '' ORDER BY id DESC LIMIT 200", ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  /**
   * @param array{status?: string, search?: string, limit?: int, offset?: int} $args
   *
   * @return array{rows: array[], total: int}
   */
  public static function query(array $args = []): array {
    $db = Db::wpdb();
    $table = Db::table('subscriptions');
    $entries = Db::table('entries');
    $where = ['1=1'];
    $params = [];
    if (!empty($args['status']) && in_array($args['status'], self::STATUSES, TRUE)) {
      $where[] = 's.status = %s';
      $params[] = $args['status'];
    }
    if (!empty($args['search'])) {
      $where[] = 'e.payer_email LIKE %s';
      $params[] = '%' . $db->esc_like((string) $args['search']) . '%';
    }
    $w = implode(' AND ', $where);
    $count = "SELECT COUNT(*) FROM {$table} s LEFT JOIN {$entries} e ON e.id = s.entry_id WHERE {$w}";
    $total = (int) $db->get_var($params ? $db->prepare($count, ...$params) : $count);
    $sql = "SELECT s.*, e.payer_email FROM {$table} s LEFT JOIN {$entries} e ON e.id = s.entry_id WHERE {$w} ORDER BY s.id DESC LIMIT %d OFFSET %d";
    $rows = $db->get_results($db->prepare($sql, ...array_merge($params, [max(1, (int) ($args['limit'] ?? 25)), max(0, (int) ($args['offset'] ?? 0))])), ARRAY_A);
    return ['rows' => array_map([self::class, 'hydrate'], (array) $rows), 'total' => $total];
  }

  public static function create(array $row): int {
    $db = Db::wpdb();
    $now = Db::now();
    $db->insert(Db::table('subscriptions'), $row + ['created_at' => $now, 'updated_at' => $now]);
    return (int) $db->insert_id;
  }

  public static function update(int $id, array $row): void {
    $row['updated_at'] = Db::now();
    Db::wpdb()->update(Db::table('subscriptions'), $row, ['id' => $id]);
  }

  /**
   * @return array{0: callable, 1: callable}
   */
  public static function markerStore(int $id): array {
    $read = static function () use ($id): ?array {
      $db = Db::wpdb();
      $json = $db->get_var($db->prepare('SELECT marker_json FROM ' . Db::table('subscriptions') . ' WHERE id = %d', $id));
      $marker = is_string($json) && $json !== '' ? json_decode($json, TRUE) : NULL;
      return is_array($marker) ? $marker : NULL;
    };
    $write = static function (?array $marker) use ($id): void {
      Db::wpdb()->update(Db::table('subscriptions'), ['marker_json' => $marker === NULL ? NULL : wp_json_encode($marker), 'updated_at' => Db::now()], ['id' => $id]);
    };
    return [$read, $write];
  }

  private static function hydrate(array $row): array {
    foreach (['id', 'entry_id', 'form_id', 'interval_length', 'recurring_times', 'payments_made', 'failed_attempts', 'installment_index'] as $key) {
      $row[$key] = (int) $row[$key];
    }
    $marker = !empty($row['marker_json']) ? json_decode((string) $row['marker_json'], TRUE) : NULL;
    $row['marker'] = is_array($marker) ? $marker : NULL;
    $row['gateway'] = ($row['gateway'] ?? '') ?: 'usaepay';
    $row['account'] = (string) ($row['account'] ?? '');
    return $row;
  }

}
