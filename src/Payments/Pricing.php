<?php

namespace EmbedForms\Payments;

use EmbedForms\Schema\FormSchema;

/**
 * What a submission costs, from the validated answers only (fields the
 * conditions hide have no answer, so they add nothing). The browser shows
 * the same sum (assets/payments.js, total()); the charge always uses this
 * one. Pure PHP so it can be unit tested.
 */
final class Pricing {

  /**
   * @param array $values
   *   Validated answers (Validator::validate()['values']).
   *
   * @return array{cents: int, total: string, lines: array<int, array{field: string, label: string, amount: string}>, frequency: string, recurring_times: int}
   */
  public static function compute(array $schema, array $values): array {
    $cents = 0;
    $lines = [];
    $frequency = 'once';
    $recurringTimes = 0;
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if (!array_key_exists($id, $values)) {
        continue;
      }
      $value = $values[$id];
      $line = 0;
      if ($field['type'] === 'amount') {
        $line = Money::toCents((string) $value) ?? 0;
      }
      elseif ($field['type'] === 'product') {
        $line = max(0, (int) $value) * (Money::toCents((string) ($field['price'] ?? '0')) ?? 0);
      }
      elseif ($field['type'] === 'frequency') {
        if (in_array($value, $field['frequencies'] ?? [], TRUE)) {
          $frequency = (string) $value;
          $recurringTimes = (int) ($field['recurring_times'] ?? 0);
        }
        continue;
      }
      else {
        continue;
      }
      if ($line > 0) {
        $cents += $line;
        $lines[] = ['field' => $id, 'label' => $field['label'] !== '' ? $field['label'] : $id, 'amount' => Money::fromCents($line)];
      }
    }
    return [
      'cents' => $cents,
      'total' => Money::fromCents($cents),
      'lines' => $lines,
      'frequency' => $frequency,
      'recurring_times' => $frequency === 'once' ? 0 : $recurringTimes,
    ];
  }

}
