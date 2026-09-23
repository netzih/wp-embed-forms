<?php

namespace EmbedForms\Db;

/**
 * Form submissions. The answers are stored as JSON under 'data', keyed by
 * field id, next to the form version they were made on.
 */
final class Entries {

  public const STATUSES = ['submitted', 'pending_payment', 'paid', 'failed', 'refunded', 'spam', 'trash'];

  public static function find(int $id): ?array {
    $db = Db::wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM ' . Db::table('entries') . ' WHERE id = %d', $id), ARRAY_A);
    return $row ? self::hydrate($row) : NULL;
  }

  public static function create(array $form, array $values, array $meta = []): int {
    $db = Db::wpdb();
    $now = Db::now();
    $db->insert(Db::table('entries'), [
      'form_id' => (int) $form['id'],
      'form_version' => (int) $form['version'],
      'status' => $meta['status'] ?? 'submitted',
      'data_json' => wp_json_encode($values),
      'payer_email' => mb_substr((string) ($meta['email'] ?? ''), 0, 190),
      'amount' => $meta['amount'] ?? NULL,
      'ip' => (string) ($meta['ip'] ?? ''),
      'user_agent' => mb_substr((string) ($meta['user_agent'] ?? ''), 0, 255),
      'source_url' => (string) ($meta['source_url'] ?? ''),
      'created_at' => $now,
      'updated_at' => $now,
    ]);
    return (int) $db->insert_id;
  }

  public static function update(int $id, array $row): void {
    $row['updated_at'] = Db::now();
    if (isset($row['data'])) {
      $row['data_json'] = wp_json_encode($row['data']);
      unset($row['data']);
    }
    Db::wpdb()->update(Db::table('entries'), $row, ['id' => $id]);
  }

  public static function setStatus(int $id, string $status): void {
    if (in_array($status, self::STATUSES, TRUE)) {
      self::update($id, ['status' => $status]);
    }
  }

  public static function delete(int $id): void {
    Db::wpdb()->delete(Db::table('entries'), ['id' => $id]);
  }

  /**
   * @param array $args
   *   form_id, status ('' = all but spam and trash), search, from, to
   *   (Y-m-d), orderby, order, limit, offset.
   *
   * @return array{rows: array[], total: int}
   */
  public static function query(array $args): array {
    $db = Db::wpdb();
    $table = Db::table('entries');
    [$where, $params] = self::where($args);
    $orderby = in_array($args['orderby'] ?? '', ['id', 'created_at', 'status', 'amount', 'payer_email'], TRUE) ? $args['orderby'] : 'id';
    $order = strtoupper((string) ($args['order'] ?? '')) === 'ASC' ? 'ASC' : 'DESC';
    $limit = max(1, (int) ($args['limit'] ?? 20));
    $offset = max(0, (int) ($args['offset'] ?? 0));

    $count = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
    $total = (int) $db->get_var($params ? $db->prepare($count, ...$params) : $count);
    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
    $rows = $db->get_results($db->prepare($sql, ...array_merge($params, [$limit, $offset])), ARRAY_A);
    return ['rows' => array_map([self::class, 'hydrate'], (array) $rows), 'total' => $total];
  }

  /**
   * Every matching entry, in batches, for exports.
   *
   * @return \Generator<array>
   */
  public static function each(array $args, int $batch = 500): \Generator {
    $offset = 0;
    do {
      $page = self::query(['limit' => $batch, 'offset' => $offset, 'orderby' => 'id', 'order' => 'ASC'] + $args);
      foreach ($page['rows'] as $row) {
        yield $row;
      }
      $offset += $batch;
    } while (count($page['rows']) === $batch);
  }

  /**
   * @return array<string, int>
   *   Status => count for one form.
   */
  public static function counts(int $formId): array {
    $db = Db::wpdb();
    $rows = $db->get_results($db->prepare('SELECT status, COUNT(*) AS n FROM ' . Db::table('entries') . ' WHERE form_id = %d GROUP BY status', $formId), ARRAY_A);
    $out = [];
    foreach ((array) $rows as $row) {
      $out[$row['status']] = (int) $row['n'];
    }
    return $out;
  }

  /**
   * Entries that count against a form's entry limit (not spam or trash).
   */
  public static function countActive(int $formId): int {
    $db = Db::wpdb();
    return (int) $db->get_var($db->prepare('SELECT COUNT(*) FROM ' . Db::table('entries') . " WHERE form_id = %d AND status NOT IN ('spam', 'trash', 'failed')", $formId));
  }

  private static function where(array $args): array {
    $db = Db::wpdb();
    $where = ['1=1'];
    $params = [];
    if (!empty($args['form_id'])) {
      $where[] = 'form_id = %d';
      $params[] = (int) $args['form_id'];
    }
    $status = (string) ($args['status'] ?? '');
    if ($status !== '' && in_array($status, self::STATUSES, TRUE)) {
      $where[] = 'status = %s';
      $params[] = $status;
    }
    else {
      $where[] = "status NOT IN ('spam', 'trash')";
    }
    if (!empty($args['search'])) {
      $like = '%' . $db->esc_like((string) $args['search']) . '%';
      $where[] = '(data_json LIKE %s OR payer_email LIKE %s OR id = %d)';
      array_push($params, $like, $like, (int) $args['search']);
    }
    if (!empty($args['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['from'])) {
      $where[] = 'created_at >= %s';
      $params[] = $args['from'] . ' 00:00:00';
    }
    if (!empty($args['to']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $args['to'])) {
      $where[] = 'created_at <= %s';
      $params[] = $args['to'] . ' 23:59:59';
    }
    return [implode(' AND ', $where), $params];
  }

  private static function hydrate(array $row): array {
    $data = json_decode((string) $row['data_json'], TRUE);
    $row['data'] = is_array($data) ? $data : [];
    $row['id'] = (int) $row['id'];
    $row['form_id'] = (int) $row['form_id'];
    $row['form_version'] = (int) $row['form_version'];
    unset($row['data_json']);
    return $row;
  }

}
