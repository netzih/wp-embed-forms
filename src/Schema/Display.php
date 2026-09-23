<?php

namespace EmbedForms\Schema;

/**
 * Stored answers as text for emails, entry screens and CSV exports.
 */
final class Display {

  /**
   * One answer as plain text (choice labels rather than values).
   */
  public static function text(array $field, mixed $value): string {
    if ($value === NULL) {
      return '';
    }
    switch ($field['type']) {
      case 'select':
      case 'radio':
        return self::label($field, (string) $value);

      case 'checkbox':
        return implode(', ', array_map(static fn($v) => self::label($field, (string) $v), is_array($value) ? $value : [$value]));

      case 'name':
        return trim(implode(' ', array_filter(array_map(static fn($p) => (string) ($value[$p] ?? ''), Fields::parts($field)))));

      case 'address':
        if (!is_array($value)) {
          return '';
        }
        $cityLine = trim(implode(' ', array_filter([
          trim((string) ($value['city'] ?? '')) !== '' ? trim((string) $value['city']) . ',' : '',
          (string) ($value['state'] ?? ''),
          (string) ($value['postcode'] ?? ''),
        ])), ', ');
        $lines = array_filter([(string) ($value['line1'] ?? ''), (string) ($value['line2'] ?? ''), $cityLine, (string) ($value['country'] ?? '')], static fn($l) => trim($l) !== '');
        return implode("\n", $lines);

      case 'amount':
        return $value === '' ? '' : \EmbedForms\Payments\Money::format((string) $value);

      case 'product':
        $quantity = (int) $value;
        return $quantity > 0 ? sprintf('%d × %s', $quantity, \EmbedForms\Payments\Money::format((string) ($field['price'] ?? '0'))) : '';

      case 'frequency':
        return Fields::frequencyLabel((string) $value);

      default:
        return is_scalar($value) ? (string) $value : '';
    }
  }

  /**
   * Label/answer pairs for the answers an entry holds, in form order.
   * Fields the entry has no answer for (hidden by conditions) are left out.
   *
   * @return array<int, array{id: string, label: string, value: string}>
   */
  public static function rows(array $schema, array $data, bool $includeEmpty = FALSE): array {
    $rows = [];
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if (!array_key_exists($id, $data)) {
        continue;
      }
      $text = self::text($field, $data[$id]);
      if ($text === '' && !$includeEmpty) {
        continue;
      }
      $rows[] = ['id' => $id, 'label' => $field['label'] !== '' ? $field['label'] : $id, 'value' => $text];
    }
    return $rows;
  }

  private static function label(array $field, string $value): string {
    foreach ($field['options'] ?? [] as $option) {
      if ((string) $option['value'] === $value) {
        return (string) $option['label'];
      }
    }
    return $value;
  }

}
