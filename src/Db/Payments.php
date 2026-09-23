<?php

namespace EmbedForms\Db;

/**
 * Charges, renewals and refunds. A charge's marker_json holds the
 * charge-at-most-once marker (Reconcile) while a request is in flight, and
 * later the marker of a refund of it.
 */
final class Payments {

  public static function find(int $id): ?array {
    $db = Db::wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM ' . Db::table('payments') . ' WHERE id = %d', $id), ARRAY_A);
    return $row ? self::hydrate($row) : NULL;
  }

  /**
   * @return array[]
   */
  public static function forEntry(int $entryId): array {
    $db = Db::wpdb();
    $rows = $db->get_results($db->prepare('SELECT * FROM ' . Db::table('payments') . ' WHERE entry_id = %d ORDER BY id ASC', $entryId), ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  public static function findByTransaction(string $transactionKey): ?array {
    if ($transactionKey === '') {
      return NULL;
    }
    $db = Db::wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM ' . Db::table('payments') . ' WHERE transaction_key = %s LIMIT 1', $transactionKey), ARRAY_A);
    return $row ? self::hydrate($row) : NULL;
  }

  /**
   * Rows with a request in flight whose answer was never recorded.
   *
   * @return array[]
   */
  public static function unresolved(): array {
    $rows = Db::wpdb()->get_results('SELECT * FROM ' . Db::table('payments') . " WHERE marker_json IS NOT NULL AND marker_json <> '' ORDER BY id DESC LIMIT 200", ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  public static function create(array $row): int {
    $db = Db::wpdb();
    $now = Db::now();
    $db->insert(Db::table('payments'), $row + ['created_at' => $now, 'updated_at' => $now]);
    return (int) $db->insert_id;
  }

  public static function update(int $id, array $row): void {
    $row['updated_at'] = Db::now();
    Db::wpdb()->update(Db::table('payments'), $row, ['id' => $id]);
  }

  /**
   * Marker store callables for Reconcile::once(), on one payment row. Reads
   * go to the database every time (no caching), as Reconcile requires.
   *
   * @return array{0: callable, 1: callable}
   */
  public static function markerStore(int $id): array {
    $read = static function () use ($id): ?array {
      $db = Db::wpdb();
      $json = $db->get_var($db->prepare('SELECT marker_json FROM ' . Db::table('payments') . ' WHERE id = %d', $id));
      $marker = is_string($json) && $json !== '' ? json_decode($json, TRUE) : NULL;
      return is_array($marker) ? $marker : NULL;
    };
    $write = static function (?array $marker) use ($id): void {
      Db::wpdb()->update(Db::table('payments'), ['marker_json' => $marker === NULL ? NULL : wp_json_encode($marker), 'updated_at' => Db::now()], ['id' => $id]);
    };
    return [$read, $write];
  }

  private static function hydrate(array $row): array {
    foreach (['id', 'entry_id'] as $key) {
      $row[$key] = (int) $row[$key];
    }
    $row['subscription_id'] = $row['subscription_id'] === NULL ? NULL : (int) $row['subscription_id'];
    $row['parent_id'] = $row['parent_id'] === NULL ? NULL : (int) $row['parent_id'];
    $marker = !empty($row['marker_json']) ? json_decode((string) $row['marker_json'], TRUE) : NULL;
    $row['marker'] = is_array($marker) ? $marker : NULL;
    // Rows from before processors were recorded are USAePay's.
    $row['gateway'] = ($row['gateway'] ?? '') ?: 'usaepay';
    $row['account'] = (string) ($row['account'] ?? '');
    return $row;
  }

}
