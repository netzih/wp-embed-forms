<?php

namespace EmbedForms\Schema;

/**
 * Turns a form definition from the builder (or the JSON editor) into the
 * canonical shape every other part of the plugin reads: known keys only,
 * the right types, unique field ids. Pure PHP so it can be unit tested.
 *
 * Canonical schema: ['fields' => [field, ...]]; a field:
 *   id, type, label, required, placeholder, help, width ('full'|'half'),
 *   default, prefill (URL parameter name), options ([['label','value']]),
 *   parts (name/address), min, max, step, rows, content (html),
 *   conditions (['action' => 'show'|'hide', 'match' => 'all'|'any',
 *   'rules' => [['field', 'op', 'value']]]).
 */
final class FormSchema {

  public const OPERATORS = ['is', 'isnot', 'contains', 'notcontains', 'gt', 'lt', 'empty', 'notempty'];

  private const MAX_FIELDS = 200;

  /**
   * @throws \InvalidArgumentException
   *   With a message for the form editor when the definition cannot be used.
   */
  public static function normalize(mixed $schema): array {
    if (is_string($schema)) {
      $decoded = json_decode($schema, TRUE);
      if (!is_array($decoded)) {
        throw new \InvalidArgumentException('The form definition is not valid JSON.');
      }
      $schema = $decoded;
    }
    if (!is_array($schema)) {
      throw new \InvalidArgumentException('The form definition must be an object with a "fields" list.');
    }
    $fields = $schema['fields'] ?? [];
    if (!is_array($fields) || !array_is_list($fields)) {
      throw new \InvalidArgumentException('"fields" must be a list.');
    }
    if (count($fields) > self::MAX_FIELDS) {
      throw new \InvalidArgumentException(sprintf('A form can have at most %d fields.', self::MAX_FIELDS));
    }

    $out = [];
    $seen = [];
    foreach ($fields as $index => $field) {
      if (!is_array($field)) {
        throw new \InvalidArgumentException(sprintf('Field %d is not an object.', $index + 1));
      }
      $type = (string) ($field['type'] ?? '');
      if (!in_array($type, Fields::all(), TRUE)) {
        throw new \InvalidArgumentException(sprintf('Field %d has an unknown type "%s".', $index + 1, $type));
      }
      $id = self::id((string) ($field['id'] ?? ''));
      if ($id === '') {
        $id = self::uniqueId($type, $seen);
      }
      if (isset($seen[$id])) {
        throw new \InvalidArgumentException(sprintf('Two fields share the id "%s".', $id));
      }
      $seen[$id] = TRUE;
      $out[] = self::field($id, $type, $field);
    }

    // Rules may only point at input fields that exist.
    $inputs = [];
    foreach ($out as $field) {
      if (Fields::isInput($field['type'])) {
        $inputs[$field['id']] = TRUE;
      }
    }
    foreach ($out as &$field) {
      if (!empty($field['conditions'])) {
        $field['conditions']['rules'] = array_values(array_filter(
          $field['conditions']['rules'],
          static fn(array $rule) => isset($inputs[$rule['field']]) && $rule['field'] !== $field['id']
        ));
        if (!$field['conditions']['rules']) {
          unset($field['conditions']);
        }
      }
    }
    unset($field);

    return ['fields' => $out];
  }

  /**
   * Input fields in form order.
   *
   * @return array<string, array>
   */
  public static function inputs(array $schema): array {
    $inputs = [];
    foreach ($schema['fields'] ?? [] as $field) {
      if (Fields::isInput($field['type'])) {
        $inputs[$field['id']] = $field;
      }
    }
    return $inputs;
  }

  public static function find(array $schema, string $id): ?array {
    foreach ($schema['fields'] ?? [] as $field) {
      if ($field['id'] === $id) {
        return $field;
      }
    }
    return NULL;
  }

  private static function field(string $id, string $type, array $in): array {
    $field = [
      'id' => $id,
      'type' => $type,
      'label' => self::text($in['label'] ?? '', 255),
    ];
    if ($type === 'html') {
      $field['content'] = (string) ($in['content'] ?? '');
    }
    if ($type === 'section' || $type === 'page') {
      $field['help'] = self::text($in['help'] ?? '', 2000);
    }
    if (Fields::isInput($type)) {
      $field['required'] = !empty($in['required']);
      $field['placeholder'] = self::text($in['placeholder'] ?? '', 255);
      $field['help'] = self::text($in['help'] ?? '', 2000);
      $field['width'] = ($in['width'] ?? 'full') === 'half' ? 'half' : 'full';
      $field['prefill'] = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) ($in['prefill'] ?? ''));
      $field['default'] = self::defaultValue($type, $in['default'] ?? '');
    }
    if (in_array($type, Fields::CHOICE_TYPES, TRUE)) {
      $field['options'] = self::options($in['options'] ?? []);
    }
    if ($type === 'name' || $type === 'address') {
      $field['parts'] = Fields::parts(['type' => $type, 'parts' => $in['parts'] ?? []]);
    }
    if ($type === 'number') {
      foreach (['min', 'max', 'step'] as $key) {
        if (isset($in[$key]) && $in[$key] !== '' && is_numeric($in[$key])) {
          $field[$key] = $in[$key] + 0;
        }
      }
    }
    if ($type === 'text' || $type === 'textarea') {
      if (isset($in['max']) && is_numeric($in['max']) && (int) $in['max'] > 0) {
        $field['max'] = (int) $in['max'];
      }
    }
    if ($type === 'textarea') {
      $field['rows'] = max(2, min(20, (int) ($in['rows'] ?? 4)));
    }
    $conditions = self::conditions($in['conditions'] ?? NULL);
    if ($conditions) {
      $field['conditions'] = $conditions;
    }
    return $field;
  }

  private static function options(mixed $options): array {
    $out = [];
    foreach (is_array($options) ? $options : [] as $option) {
      if (is_string($option)) {
        $option = ['label' => $option, 'value' => $option];
      }
      if (!is_array($option)) {
        continue;
      }
      $label = self::text($option['label'] ?? '', 255);
      $value = self::text($option['value'] ?? $label, 255);
      if ($label === '' && $value === '') {
        continue;
      }
      $out[] = ['label' => $label !== '' ? $label : $value, 'value' => $value !== '' ? $value : $label];
    }
    return $out;
  }

  private static function conditions(mixed $in): ?array {
    if (!is_array($in) || empty($in['rules']) || !is_array($in['rules'])) {
      return NULL;
    }
    $rules = [];
    foreach ($in['rules'] as $rule) {
      if (!is_array($rule)) {
        continue;
      }
      $field = self::id((string) ($rule['field'] ?? ''));
      $op = (string) ($rule['op'] ?? 'is');
      if ($field === '' || !in_array($op, self::OPERATORS, TRUE)) {
        continue;
      }
      $rules[] = ['field' => $field, 'op' => $op, 'value' => self::text($rule['value'] ?? '', 255)];
    }
    if (!$rules) {
      return NULL;
    }
    return [
      'action' => ($in['action'] ?? 'show') === 'hide' ? 'hide' : 'show',
      'match' => ($in['match'] ?? 'all') === 'any' ? 'any' : 'all',
      'rules' => $rules,
    ];
  }

  private static function defaultValue(string $type, mixed $value): mixed {
    $shape = Fields::valueShape($type);
    if ($shape === 'list') {
      return array_values(array_map(static fn($v) => self::text($v, 255), is_array($value) ? $value : ($value === '' ? [] : [$value])));
    }
    if ($shape === 'object') {
      return is_array($value) ? array_map(static fn($v) => self::text($v, 255), $value) : [];
    }
    return is_scalar($value) ? self::text($value, 2000) : '';
  }

  private static function id(string $id): string {
    return substr(preg_replace('/[^a-z0-9_]/', '', strtolower($id)), 0, 64);
  }

  private static function uniqueId(string $type, array $seen): string {
    $n = 1;
    while (isset($seen[$type . '_' . $n])) {
      $n++;
    }
    return $type . '_' . $n;
  }

  private static function text(mixed $value, int $max): string {
    if (!is_scalar($value)) {
      return '';
    }
    $value = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', (string) $value) ?? '');
    return mb_substr($value, 0, $max);
  }

}
