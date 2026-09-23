<?php

namespace EmbedForms\Schema;

/**
 * The field types a form can hold and what each one is. Shared by the
 * schema normalizer, the server-side validator, the builder palette and the
 * entry screens, so a type added here is known everywhere.
 */
final class Fields {

  /**
   * Types that collect a value, keyed by type, with the shape of the value:
   * 'string', 'list' (several choices), or 'object' (named parts).
   */
  public const INPUTS = [
    'text' => 'string',
    'textarea' => 'string',
    'email' => 'string',
    'phone' => 'string',
    'number' => 'string',
    'select' => 'string',
    'radio' => 'string',
    'checkbox' => 'list',
    'date' => 'string',
    'name' => 'object',
    'address' => 'object',
    'hidden' => 'string',
  ];

  /**
   * Types that only lay the form out.
   */
  public const LAYOUT = ['html', 'section', 'page'];

  public const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

  public const NAME_PARTS = ['prefix', 'first', 'middle', 'last', 'suffix'];

  public const ADDRESS_PARTS = ['line1', 'line2', 'city', 'state', 'postcode', 'country'];

  public static function all(): array {
    return array_merge(array_keys(self::INPUTS), self::LAYOUT);
  }

  public static function isInput(string $type): bool {
    return isset(self::INPUTS[$type]);
  }

  public static function valueShape(string $type): ?string {
    return self::INPUTS[$type] ?? NULL;
  }

  /**
   * The parts an object-valued field is made of, as configured on the field
   * (a name field without middle name, an address without line 2).
   *
   * @return string[]
   */
  public static function parts(array $field): array {
    $all = $field['type'] === 'name' ? self::NAME_PARTS : ($field['type'] === 'address' ? self::ADDRESS_PARTS : []);
    $enabled = isset($field['parts']) && is_array($field['parts']) ? array_values(array_intersect($all, $field['parts'])) : [];
    if ($enabled) {
      return $enabled;
    }
    return $field['type'] === 'name' ? ['first', 'last'] : ($field['type'] === 'address' ? ['line1', 'line2', 'city', 'state', 'postcode', 'country'] : []);
  }

  /**
   * Parts that must be filled when the field is required.
   *
   * @return string[]
   */
  public static function requiredParts(array $field): array {
    $parts = self::parts($field);
    $optional = ['prefix', 'middle', 'suffix', 'line2'];
    return array_values(array_diff($parts, $optional));
  }

}
