<?php

namespace EmbedForms\Payments;

use EmbedForms\Schema\FormSchema;

/**
 * Who is paying, read from the answers: the first email, name, phone and
 * address fields. In the shape USAePay Payments' Gateway::metadata() takes,
 * so the console shows the payer and the address goes to AVS.
 */
final class Payer {

  public static function fromValues(array $schema, array $values): array {
    $payer = [];
    foreach (FormSchema::inputs($schema) as $id => $field) {
      $value = $values[$id] ?? NULL;
      if ($value === NULL || $value === '' || $value === []) {
        continue;
      }
      switch ($field['type']) {
        case 'email':
          $payer['email'] = $payer['email'] ?? (string) $value;
          break;

        case 'phone':
          $payer['phone'] = $payer['phone'] ?? (string) $value;
          break;

        case 'name':
          if (!isset($payer['first_name']) && is_array($value)) {
            $payer['first_name'] = (string) ($value['first'] ?? '');
            $payer['last_name'] = (string) ($value['last'] ?? '');
          }
          break;

        case 'address':
          if (!isset($payer['address']) && is_array($value)) {
            $payer['address'] = (string) ($value['line1'] ?? '');
            $payer['address2'] = (string) ($value['line2'] ?? '');
            $payer['city'] = (string) ($value['city'] ?? '');
            $payer['state'] = (string) ($value['state'] ?? '');
            $payer['postcode'] = (string) ($value['postcode'] ?? '');
            $payer['country'] = (string) ($value['country'] ?? '');
          }
          break;
      }
    }
    return $payer;
  }

  public static function name(array $payer): string {
    return trim(($payer['first_name'] ?? '') . ' ' . ($payer['last_name'] ?? ''));
  }

}
