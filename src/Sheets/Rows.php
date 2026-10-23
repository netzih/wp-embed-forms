<?php

namespace EmbedForms\Sheets;

use EmbedForms\Admin\CsvExport;
use EmbedForms\Schema\Display;

/**
 * The header and rows a form's entries take in a Google Sheet: the CSV
 * export's columns (name and address split into parts), without the IP
 * address, plus the payment's transaction. Columns are matched to the
 * sheet's header row by name, so a column moved or added in the sheet stays
 * put and a field added to the form gets a new column at the end. Pure PHP
 * so it can be unit tested.
 */
final class Rows {

  /**
   * Column headings for a form, each unique.
   *
   * @param array<string, array> $inputs
   *   Input fields keyed by id (FormSchema::inputs()).
   *
   * @return string[]
   */
  public static function headers(array $inputs): array {
    $labels = array_merge(['Entry', 'Date', 'Status', 'Email', 'Amount'], array_column(CsvExport::columns($inputs), 'label'), ['Transaction', 'Submitted on']);
    $seen = [];
    $out = [];
    foreach ($labels as $label) {
      $label = trim((string) $label);
      $unique = $label;
      for ($n = 2; isset($seen[strtolower($unique)]); $n++) {
        $unique = $label . ' (' . $n . ')';
      }
      $seen[strtolower($unique)] = TRUE;
      $out[] = $unique;
    }
    return $out;
  }

  /**
   * One entry's cells, in the order of headers(). Values are written to the
   * sheet as they are (no formulas run); the entry id and amount as numbers.
   *
   * @param string $date
   *   When the entry was made, in the site's time zone.
   * @param ?array $payment
   *   The entry's charge, if any.
   */
  public static function values(array $entry, array $inputs, string $date, ?array $payment = NULL): array {
    $columns = CsvExport::columns($inputs);
    $row = [
      (int) $entry['id'],
      $date,
      (string) $entry['status'],
      (string) $entry['payer_email'],
      $entry['amount'] === NULL || $entry['amount'] === '' ? '' : (float) $entry['amount'],
    ];
    foreach ($columns as $column) {
      $value = $entry['data'][$column['id']] ?? NULL;
      $row[] = $column['part'] !== NULL
        ? (is_array($value) ? (string) ($value[$column['part']] ?? '') : '')
        : Display::text($inputs[$column['id']], $value);
    }
    $row[] = self::transaction($payment);
    $row[] = (string) ($entry['source_url'] ?? '');
    return $row;
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
   * The sheet's header row with any of our headings it lacks added at the
   * end. Blank trailing cells of the sheet's row are dropped first.
   *
   * @param array $existing
   *   Row 1 of the sheet as read.
   * @param string[] $ours
   *
   * @return string[]
   */
  public static function mergeHeader(array $existing, array $ours): array {
    $header = array_map(static fn($cell) => trim((string) $cell), $existing);
    while ($header && end($header) === '') {
      array_pop($header);
    }
    $have = array_flip(array_map('strtolower', $header));
    foreach ($ours as $heading) {
      if (!isset($have[strtolower($heading)])) {
        $header[] = $heading;
        $have[strtolower($heading)] = TRUE;
      }
    }
    return $header;
  }

  /**
   * Our cells placed under the sheet's header: a heading we do not have
   * stays blank.
   *
   * @param string[] $header
   *   The merged header row.
   * @param string[] $ours
   * @param array $values
   *   In the order of $ours.
   */
  public static function align(array $header, array $ours, array $values): array {
    $byHeading = [];
    foreach ($ours as $i => $heading) {
      $byHeading[strtolower($heading)] = $values[$i] ?? '';
    }
    $row = [];
    foreach ($header as $heading) {
      $row[] = $byHeading[strtolower($heading)] ?? '';
    }
    return $row;
  }

  /**
   * The column letter (A, B, …, Z, AA, …) of a 0-based index.
   */
  public static function columnLetter(int $index): string {
    $letter = '';
    for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
      $letter = chr(65 + ($n - 1) % 26) . $letter;
    }
    return $letter;
  }

  /**
   * A spreadsheet id from its id or any of its links, or ''.
   */
  public static function spreadsheetId(mixed $value): string {
    $value = is_scalar($value) ? trim((string) $value) : '';
    if (preg_match('#/spreadsheets/d/([A-Za-z0-9_-]{20,})#', $value, $m)) {
      return $m[1];
    }
    return preg_match('/^[A-Za-z0-9_-]{20,}$/', $value) ? $value : '';
  }

  /**
   * A1 notation for a range of a tab, the tab name quoted.
   */
  public static function range(string $tab, string $cells = ''): string {
    $quoted = "'" . str_replace("'", "''", $tab) . "'";
    return $cells === '' ? $quoted : $quoted . '!' . $cells;
  }

}
