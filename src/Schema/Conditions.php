<?php

namespace EmbedForms\Schema;

/**
 * Which fields are shown for a set of answers. The same rules run in the
 * browser (assets/form.js, visibility()); keep the two in step.
 *
 * - A field's own conditions show or hide it.
 * - A hidden section hides every field up to the next section or page break;
 *   a hidden page hides every field up to the next page break.
 * - A hidden field counts as empty in other fields' rules, so chains of
 *   conditions settle; evaluation repeats until nothing changes.
 * - Comparisons are case-insensitive and trimmed; checkbox values match when
 *   any chosen option matches; name and address parts are joined by spaces.
 */
final class Conditions {

  private const MAX_PASSES = 10;

  /**
   * @return array<string, bool>
   *   Field id => visible, for every field in the schema.
   */
  public static function visibility(array $schema, array $values): array {
    $fields = $schema['fields'] ?? [];
    $visible = array_fill_keys(array_column($fields, 'id'), TRUE);
    for ($pass = 0; $pass < self::MAX_PASSES; $pass++) {
      $next = self::pass($fields, $values, $visible);
      if ($next === $visible) {
        break;
      }
      $visible = $next;
    }
    return $visible;
  }

  private static function pass(array $fields, array $values, array $visible): array {
    $effective = [];
    foreach ($fields as $field) {
      $effective[$field['id']] = $visible[$field['id']] ? ($values[$field['id']] ?? NULL) : NULL;
    }
    $out = [];
    $pageShown = TRUE;
    $sectionShown = TRUE;
    foreach ($fields as $field) {
      $own = empty($field['conditions']) || self::applies($field['conditions'], $effective);
      if ($field['type'] === 'page') {
        $pageShown = $own;
        $sectionShown = TRUE;
        $out[$field['id']] = $own;
        continue;
      }
      if ($field['type'] === 'section') {
        $sectionShown = $own;
        $out[$field['id']] = $pageShown && $own;
        continue;
      }
      $out[$field['id']] = $pageShown && $sectionShown && $own;
    }
    return $out;
  }

  /**
   * Whether the field these conditions belong to is shown.
   */
  public static function applies(array $conditions, array $values): bool {
    $results = array_map(static fn(array $rule) => self::rule($rule, $values[$rule['field']] ?? NULL), $conditions['rules'] ?? []);
    if (!$results) {
      return TRUE;
    }
    $matched = ($conditions['match'] ?? 'all') === 'any' ? in_array(TRUE, $results, TRUE) : !in_array(FALSE, $results, TRUE);
    return ($conditions['action'] ?? 'show') === 'hide' ? !$matched : $matched;
  }

  public static function rule(array $rule, mixed $value): bool {
    $candidates = self::candidates($value);
    $target = self::lower((string) ($rule['value'] ?? ''));
    $empty = !array_filter($candidates, static fn(string $v) => $v !== '');
    switch ($rule['op']) {
      case 'empty':
        return $empty;

      case 'notempty':
        return !$empty;

      case 'is':
        return in_array($target, $candidates, TRUE) || ($target === '' && $empty);

      case 'isnot':
        return !(in_array($target, $candidates, TRUE) || ($target === '' && $empty));

      case 'contains':
        foreach ($candidates as $candidate) {
          if ($target !== '' && str_contains($candidate, $target)) {
            return TRUE;
          }
        }
        return FALSE;

      case 'notcontains':
        foreach ($candidates as $candidate) {
          if ($target !== '' && str_contains($candidate, $target)) {
            return FALSE;
          }
        }
        return TRUE;

      case 'gt':
      case 'lt':
        $number = $candidates[0] ?? '';
        if (!is_numeric($number) || !is_numeric($target)) {
          return FALSE;
        }
        return $rule['op'] === 'gt' ? (float) $number > (float) $target : (float) $number < (float) $target;
    }
    return FALSE;
  }

  /**
   * @return string[]
   */
  private static function candidates(mixed $value): array {
    if ($value === NULL) {
      return [''];
    }
    if (is_array($value)) {
      if (array_is_list($value)) {
        return $value ? array_map(static fn($v) => self::lower(is_scalar($v) ? (string) $v : ''), $value) : [''];
      }
      $joined = implode(' ', array_filter(array_map(static fn($v) => is_scalar($v) ? trim((string) $v) : '', $value), static fn($v) => $v !== ''));
      return [self::lower($joined)];
    }
    return [self::lower(is_scalar($value) ? (string) $value : '')];
  }

  private static function lower(string $value): string {
    return mb_strtolower(trim($value));
  }

}
