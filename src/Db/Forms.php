<?php

namespace EmbedForms\Db;

use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\FormSettings;

/**
 * Forms and their saved versions. A form row returned from here has its
 * schema and settings decoded and normalized under 'schema' and 'settings'.
 */
final class Forms {

  public const STATUSES = ['draft', 'live', 'closed'];

  public static function find(int $id): ?array {
    $db = Db::wpdb();
    $row = $db->get_row($db->prepare('SELECT * FROM ' . Db::table('forms') . ' WHERE id = %d', $id), ARRAY_A);
    return $row ? self::hydrate($row) : NULL;
  }

  /**
   * A form by its public reference: the uuid used in embed codes, or the
   * slug used in share links.
   */
  public static function findPublic(string $reference): ?array {
    $db = Db::wpdb();
    $table = Db::table('forms');
    $row = preg_match('/^[a-f0-9]{32}$/', $reference)
      ? $db->get_row($db->prepare("SELECT * FROM {$table} WHERE uuid = %s", $reference), ARRAY_A)
      : NULL;
    if (!$row) {
      $row = $db->get_row($db->prepare("SELECT * FROM {$table} WHERE slug = %s", $reference), ARRAY_A);
    }
    return $row ? self::hydrate($row) : NULL;
  }

  /**
   * @return array[]
   */
  public static function all(array $args = []): array {
    $db = Db::wpdb();
    $table = Db::table('forms');
    $where = '1=1';
    $params = [];
    if (!empty($args['status']) && in_array($args['status'], self::STATUSES, TRUE)) {
      $where .= ' AND status = %s';
      $params[] = $args['status'];
    }
    if (!empty($args['search'])) {
      $where .= ' AND title LIKE %s';
      $params[] = '%' . $db->esc_like((string) $args['search']) . '%';
    }
    $sql = "SELECT * FROM {$table} WHERE {$where} ORDER BY updated_at DESC";
    $rows = $db->get_results($params ? $db->prepare($sql, ...$params) : $sql, ARRAY_A);
    return array_map([self::class, 'hydrate'], (array) $rows);
  }

  public static function create(string $title, array $schema = ['fields' => []], array $settings = []): int {
    $db = Db::wpdb();
    $now = Db::now();
    $schema = FormSchema::normalize($schema);
    $settings = FormSettings::normalize($settings);
    $db->insert(Db::table('forms'), [
      'uuid' => bin2hex(random_bytes(16)),
      'slug' => self::uniqueSlug(sanitize_title($title) ?: 'form'),
      'title' => $title,
      'status' => 'draft',
      'schema_json' => wp_json_encode($schema),
      'settings_json' => wp_json_encode($settings),
      'version' => 1,
      'created_at' => $now,
      'updated_at' => $now,
    ]);
    $id = (int) $db->insert_id;
    if ($id > 0) {
      self::saveVersion($id, 1, $schema);
    }
    return $id;
  }

  /**
   * Save changes. A changed schema becomes a new version, so entries keep
   * rendering against the fields they were submitted with.
   *
   * @param array $changes
   *   Any of title, slug, status, schema, settings.
   */
  public static function update(int $id, array $changes): void {
    $form = self::find($id);
    if (!$form) {
      throw new \InvalidArgumentException('Form not found.');
    }
    $row = ['updated_at' => Db::now()];
    if (isset($changes['title'])) {
      $row['title'] = mb_substr(trim((string) $changes['title']), 0, 255);
    }
    if (isset($changes['slug'])) {
      $slug = sanitize_title((string) $changes['slug']);
      if ($slug === '') {
        throw new \InvalidArgumentException(__('The link name cannot be empty.', 'embed-forms'));
      }
      if ($slug !== $form['slug']) {
        $row['slug'] = self::uniqueSlug($slug, $id);
      }
    }
    if (isset($changes['status']) && in_array($changes['status'], self::STATUSES, TRUE)) {
      $row['status'] = $changes['status'];
    }
    if (isset($changes['settings'])) {
      $row['settings_json'] = wp_json_encode(FormSettings::normalize($changes['settings']));
    }
    if (isset($changes['schema'])) {
      $schema = FormSchema::normalize($changes['schema']);
      if (wp_json_encode($schema) !== wp_json_encode($form['schema'])) {
        $row['schema_json'] = wp_json_encode($schema);
        $row['version'] = (int) $form['version'] + 1;
        self::saveVersion($id, $row['version'], $schema);
      }
    }
    Db::wpdb()->update(Db::table('forms'), $row, ['id' => $id]);
  }

  public static function delete(int $id): void {
    $db = Db::wpdb();
    $db->delete(Db::table('forms'), ['id' => $id]);
    $db->delete(Db::table('form_versions'), ['form_id' => $id]);
  }

  public static function duplicate(int $id): int {
    $form = self::find($id);
    if (!$form) {
      throw new \InvalidArgumentException('Form not found.');
    }
    return self::create(sprintf(__('%s (copy)', 'embed-forms'), $form['title']), $form['schema'], $form['settings']);
  }

  /**
   * The schema an entry was submitted against; the current one when that
   * version is missing.
   */
  public static function schemaAt(array $form, int $version): array {
    if ($version === (int) $form['version']) {
      return $form['schema'];
    }
    $db = Db::wpdb();
    $json = $db->get_var($db->prepare('SELECT schema_json FROM ' . Db::table('form_versions') . ' WHERE form_id = %d AND version = %d', (int) $form['id'], $version));
    $schema = is_string($json) ? json_decode($json, TRUE) : NULL;
    return is_array($schema) ? $schema : $form['schema'];
  }

  private static function saveVersion(int $formId, int $version, array $schema): void {
    Db::wpdb()->insert(Db::table('form_versions'), [
      'form_id' => $formId,
      'version' => $version,
      'schema_json' => wp_json_encode($schema),
      'created_at' => Db::now(),
      'created_by' => get_current_user_id(),
    ]);
  }

  private static function uniqueSlug(string $slug, int $exceptId = 0): string {
    $db = Db::wpdb();
    $table = Db::table('forms');
    // A 32-character hex slug would be read as a uuid.
    if (preg_match('/^[a-f0-9]{32}$/', $slug)) {
      $slug .= '-form';
    }
    $candidate = $slug;
    $n = 2;
    while ((int) $db->get_var($db->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s AND id <> %d", $candidate, $exceptId)) > 0) {
      $candidate = $slug . '-' . $n++;
    }
    return $candidate;
  }

  private static function hydrate(array $row): array {
    $schema = json_decode((string) $row['schema_json'], TRUE);
    $row['schema'] = is_array($schema) ? $schema : ['fields' => []];
    $row['settings'] = FormSettings::normalize(json_decode((string) $row['settings_json'], TRUE));
    $row['id'] = (int) $row['id'];
    $row['version'] = (int) $row['version'];
    unset($row['schema_json'], $row['settings_json']);
    return $row;
  }

}
