<?php

namespace EmbedForms\Sheet;

use EmbedForms\Schema\Display;
use EmbedForms\Schema\Fields;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;

/**
 * The columns and cells of a form's shared entries sheet, and changing one
 * cell. Answers can be changed; the entry's number, date, status, amount,
 * payment answers and transaction cannot (they record what was charged).
 * Pure PHP so it can be unit tested.
 */
final class Grid {

  /**
   * Payment answers describe what was charged and cannot be changed.
   */
  private const PAYMENT_TYPES = ['amount', 'product', 'frequency', 'total', 'payment'];

  /**
   * Field types changed with a text box, and the box to use.
   */
  private const TEXT_EDITORS = [
    'text' => 'text',
    'textarea' => 'textarea',
    'email' => 'email',
    'phone' => 'text',
    'number' => 'number',
    'date' => 'date',
    'display_name' => 'text',
    'name' => 'text',
    'address' => 'text',
  ];

  /**
   * @param array<string, array> $inputs
   *   Input fields keyed by id (FormSchema::inputs()).
   *
   * @return array<int, array{key: string, label: string, field: ?string, part: ?string, edit: ?string, options: array, numeric: bool}>
   */
  public static function columns(array $inputs): array {
    $column = static fn(string $key, string $label, ?string $field = NULL, ?string $part = NULL, ?string $edit = NULL, array $options = [], bool $numeric = FALSE) => compact('key', 'label', 'field', 'part', 'edit', 'options', 'numeric');
    $columns = [
      $column('id', 'Entry', NULL, NULL, NULL, [], TRUE),
      $column('date', 'Date'),
      $column('status', 'Status'),
      $column('email', 'Email'),
      $column('amount', 'Amount', NULL, NULL, NULL, [], TRUE),
    ];
    foreach ($inputs as $id => $field) {
      $label = $field['label'] !== '' ? $field['label'] : $id;
      $type = $field['type'];
      if ($type === 'name' || $type === 'address') {
        foreach (Fields::parts($field) as $part) {
          $columns[] = $column('f:' . $id . ':' . $part, $label . ' (' . $part . ')', $id, $part, 'text');
        }
        continue;
      }
      if ($type === 'select' || $type === 'radio') {
        $options = array_map(static fn($o) => [(string) $o['value'], (string) $o['label']], $field['options'] ?? []);
        $columns[] = $column('f:' . $id, $label, $id, NULL, 'select', $options);
        continue;
      }
      $columns[] = $column('f:' . $id, $label, $id, NULL, in_array($type, self::PAYMENT_TYPES, TRUE) ? NULL : (self::TEXT_EDITORS[$type] ?? NULL), [], $type === 'number');
    }
    $columns[] = $column('transaction', 'Transaction');
    $columns[] = $column('card', 'Card');
    // Headings unique, for downloads.
    $seen = [];
    foreach ($columns as &$c) {
      $label = $c['label'];
      for ($n = 2; isset($seen[strtolower($c['label'])]); $n++) {
        $c['label'] = $label . ' (' . $n . ')';
      }
      $seen[strtolower($c['label'])] = TRUE;
    }
    unset($c);
    return $columns;
  }

  /**
   * One cell as text, plus the stored value of a choice cell.
   *
   * @param ?array $payment
   *   The entry's charge, if any.
   *
   * @return array{text: string, value: string}
   */
  public static function cell(array $column, array $entry, array $inputs, string $date, ?array $payment): array {
    switch ($column['key']) {
      case 'id':
        $text = (string) $entry['id'];
        break;

      case 'date':
        $text = $date;
        break;

      case 'status':
        $text = (string) $entry['status'];
        break;

      case 'email':
        $text = (string) $entry['payer_email'];
        break;

      case 'amount':
        $text = $entry['amount'] === NULL || $entry['amount'] === '' ? '' : number_format((float) $entry['amount'], 2, '.', '');
        break;

      case 'transaction':
        $text = self::transaction($payment);
        break;

      case 'card':
        $text = $payment && ($payment['card_last4'] ?? '') !== '' ? trim(($payment['card_brand'] ?? '') . ' •••• ' . $payment['card_last4']) : '';
        break;

      default:
        $value = $entry['data'][$column['field']] ?? NULL;
        if ($column['part'] !== NULL) {
          $text = is_array($value) ? (string) ($value[$column['part']] ?? '') : '';
          return ['text' => $text, 'value' => $text];
        }
        $text = isset($inputs[$column['field']]) ? Display::text($inputs[$column['field']], $value) : (is_scalar($value) ? (string) $value : '');
        return ['text' => $text, 'value' => is_scalar($value) ? (string) $value : $text];
    }
    return ['text' => $text, 'value' => $text];
  }

  private static function transaction(?array $payment): string {
    if (!$payment) {
      return '';
    }
    if (($payment['gateway'] ?? '') === 'offline') {
      return trim('Offline ' . ($payment['method'] ?? '') . ' ' . ($payment['refnum'] ?? ''));
    }
    $ref = (string) (($payment['refnum'] ?? '') ?: ($payment['transaction_key'] ?? ''));
    return $ref !== '' && ($payment['mode'] ?? '') === 'sandbox' ? $ref . ' (test)' : $ref;
  }

  /**
   * Change one cell of an entry's answers.
   *
   * @param array $schema
   *   The schema the entry was made on.
   *
   * @return array{ok: bool, values?: array, message?: string}
   *   The entry's new answers, or why the change was refused.
   */
  public static function change(array $schema, array $data, array $column, string $value): array {
    if ($column['edit'] === NULL || $column['field'] === NULL) {
      return ['ok' => FALSE, 'message' => 'This column cannot be changed here.'];
    }
    $inputs = FormSchema::inputs($schema);
    if (!isset($inputs[$column['field']])) {
      return ['ok' => FALSE, 'message' => 'This entry was made before this field was added, so it cannot be filled in here.'];
    }
    if ($column['edit'] === 'select' && $value !== '' && !in_array($value, array_column($column['options'], 0), TRUE)) {
      return ['ok' => FALSE, 'message' => 'Choose one of the listed answers.'];
    }
    $input = $data;
    if ($column['part'] !== NULL) {
      $current = is_array($data[$column['field']] ?? NULL) ? $data[$column['field']] : [];
      $current[$column['part']] = $value;
      $input[$column['field']] = $current;
    }
    else {
      $input[$column['field']] = $value;
    }
    $result = Validator::validate($schema, $input, ['required' => FALSE, 'skip' => ['amount', 'product', 'frequency']]);
    if (isset($result['errors'][$column['field']])) {
      return ['ok' => FALSE, 'message' => $result['errors'][$column['field']]];
    }
    if (!array_key_exists($column['field'], $result['values'])) {
      return ['ok' => FALSE, 'message' => 'This question is hidden for this entry by the form\'s conditions.'];
    }
    // Only this answer changes; the others stay exactly as stored.
    $values = $data;
    $values[$column['field']] = $result['values'][$column['field']];
    return ['ok' => TRUE, 'values' => $values];
  }

}
