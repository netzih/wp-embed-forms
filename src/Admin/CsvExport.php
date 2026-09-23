<?php

namespace EmbedForms\Admin;

use EmbedForms\Schema\Display;
use EmbedForms\Schema\Fields;

/**
 * Columns and rows of the entries CSV. Name and address fields get one
 * column per part so spreadsheets and CRM imports can map them. Pure PHP so
 * it can be unit tested.
 */
final class CsvExport {

  /**
   * @param array<string, array> $inputs
   *   Input fields keyed by id (FormSchema::inputs()).
   *
   * @return array<int, array{id: string, part: ?string, label: string}>
   */
  public static function columns(array $inputs): array {
    $columns = [];
    foreach ($inputs as $id => $field) {
      $label = $field['label'] !== '' ? $field['label'] : $id;
      if ($field['type'] === 'name' || $field['type'] === 'address') {
        foreach (Fields::parts($field) as $part) {
          $columns[] = ['id' => $id, 'part' => $part, 'label' => $label . ' (' . $part . ')'];
        }
        continue;
      }
      $columns[] = ['id' => $id, 'part' => NULL, 'label' => $label];
    }
    return $columns;
  }

  public static function row(array $entry, array $columns, array $inputs): array {
    $row = [
      (string) $entry['id'],
      (string) $entry['created_at'],
      (string) $entry['status'],
      (string) $entry['payer_email'],
      $entry['amount'] === NULL ? '' : (string) $entry['amount'],
    ];
    foreach ($columns as $column) {
      $value = $entry['data'][$column['id']] ?? NULL;
      if ($column['part'] !== NULL) {
        $row[] = self::cell(is_array($value) ? (string) ($value[$column['part']] ?? '') : '');
      }
      else {
        $row[] = self::cell(Display::text($inputs[$column['id']], $value));
      }
    }
    $row[] = self::cell((string) ($entry['source_url'] ?? ''));
    $row[] = (string) $entry['ip'];
    return $row;
  }

  /**
   * Spreadsheets run cells that start with = + - @ (or a tab/CR) as
   * formulas; prefix those with an apostrophe.
   */
  public static function cell(string $value): string {
    return preg_match('/^[=+\-@\t\r]/', $value) ? "'" . $value : $value;
  }

}
