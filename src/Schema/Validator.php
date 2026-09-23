<?php

namespace EmbedForms\Schema;

/**
 * Server-side check of a submission against the form it was made on. Only
 * fields that the conditional logic shows are read; everything else the
 * browser sent is dropped. The result holds the clean values to store and a
 * payer-facing message per field that failed.
 */
final class Validator {

  private const TEXT_MAX = 1000;

  private const TEXTAREA_MAX = 10000;

  /**
   * @return array{values: array<string, mixed>, errors: array<string, string>}
   */
  public static function validate(array $schema, array $input): array {
    $inputs = FormSchema::inputs($schema);
    // Clean first so conditions see the same values that will be stored.
    $clean = [];
    foreach ($inputs as $id => $field) {
      $clean[$id] = self::clean($field, $input[$id] ?? NULL);
    }
    $visible = Conditions::visibility($schema, $clean);

    $values = [];
    $errors = [];
    foreach ($inputs as $id => $field) {
      if (empty($visible[$id])) {
        continue;
      }
      $value = $clean[$id];
      $error = self::check($field, $value);
      if ($error !== NULL) {
        $errors[$id] = $error;
      }
      $values[$id] = $value;
    }
    return ['values' => $values, 'errors' => $errors];
  }

  public static function isEmpty(mixed $value): bool {
    if (is_array($value)) {
      foreach ($value as $part) {
        if (!self::isEmpty($part)) {
          return FALSE;
        }
      }
      return TRUE;
    }
    return $value === NULL || trim((string) $value) === '';
  }

  private static function clean(array $field, mixed $raw): mixed {
    switch (Fields::valueShape($field['type'])) {
      case 'list':
        $list = is_array($raw) ? $raw : ($raw === NULL || $raw === '' ? [] : [$raw]);
        return array_values(array_unique(array_filter(array_map(static fn($v) => self::line($v, 255), $list), static fn($v) => $v !== '')));

      case 'object':
        $out = [];
        foreach (Fields::parts($field) as $part) {
          $out[$part] = self::line(is_array($raw) ? ($raw[$part] ?? '') : '', 255);
        }
        return $out;

      default:
        if ($field['type'] === 'amount' && ($field['amount_mode'] ?? '') === 'fixed') {
          return (string) $field['fixed_amount'];
        }
        if ($field['type'] === 'product' && empty($field['quantity'])) {
          return '1';
        }
        if ($field['type'] === 'amount') {
          $line = self::line($raw, 20);
          return $line === '' ? '' : (\EmbedForms\Payments\Money::normalize($line) ?? $line);
        }
        if ($field['type'] === 'textarea') {
          return self::multiline($raw, (int) ($field['max'] ?? self::TEXTAREA_MAX));
        }
        return self::line($raw, (int) ($field['max'] ?? self::TEXT_MAX));
    }
  }

  private static function check(array $field, mixed $value): ?string {
    $type = $field['type'];
    if ($type === 'name' || $type === 'address') {
      if (!empty($field['required'])) {
        foreach (Fields::requiredParts($field) as $part) {
          if (($value[$part] ?? '') === '') {
            return __('Please fill in every part of this field.', 'embed-forms');
          }
        }
      }
      return NULL;
    }
    if ($type === 'amount') {
      return self::checkAmount($field, (string) $value);
    }
    if ($type === 'product') {
      if (!preg_match('/^\d{1,4}$/', (string) $value) && (string) $value !== '') {
        return __('Please enter a quantity.', 'embed-forms');
      }
      $quantity = (int) $value;
      if ($quantity > (int) ($field['max_quantity'] ?? 1)) {
        return sprintf(__('Please enter %s or less.', 'embed-forms'), $field['max_quantity']);
      }
      return !empty($field['required']) && $quantity < 1 ? __('Please choose at least one.', 'embed-forms') : NULL;
    }
    if (self::isEmpty($value)) {
      return !empty($field['required']) ? __('This field is required.', 'embed-forms') : NULL;
    }
    switch ($type) {
      case 'frequency':
        return in_array($value, $field['frequencies'] ?? [], TRUE) ? NULL : __('Please choose one of the options.', 'embed-forms');

      case 'email':
        return filter_var($value, FILTER_VALIDATE_EMAIL) ? NULL : __('Please enter a valid email address.', 'embed-forms');

      case 'phone':
        $digits = preg_replace('/\D/', '', (string) $value);
        return preg_match('/^[0-9+().\-\s]+(\s*(x|ext\.?)\s*\d+)?$/i', (string) $value) && strlen($digits) >= 7 && strlen($digits) <= 20
          ? NULL : __('Please enter a valid phone number.', 'embed-forms');

      case 'number':
        if (!is_numeric($value)) {
          return __('Please enter a number.', 'embed-forms');
        }
        if (isset($field['min']) && (float) $value < (float) $field['min']) {
          return sprintf(__('Please enter %s or more.', 'embed-forms'), $field['min']);
        }
        if (isset($field['max']) && (float) $value > (float) $field['max']) {
          return sprintf(__('Please enter %s or less.', 'embed-forms'), $field['max']);
        }
        return NULL;

      case 'date':
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $value);
        return $date && $date->format('Y-m-d') === $value ? NULL : __('Please enter a valid date.', 'embed-forms');

      case 'select':
      case 'radio':
        return in_array($value, array_column($field['options'] ?? [], 'value'), TRUE) ? NULL : __('Please choose one of the options.', 'embed-forms');

      case 'checkbox':
        $allowed = array_column($field['options'] ?? [], 'value');
        foreach ($value as $choice) {
          if (!in_array($choice, $allowed, TRUE)) {
            return __('Please choose from the options.', 'embed-forms');
          }
        }
        return NULL;
    }
    return NULL;
  }

  private static function checkAmount(array $field, string $value): ?string {
    if ($value === '' || $value === '0.00') {
      return !empty($field['required']) ? __('Please choose or enter an amount.', 'embed-forms') : NULL;
    }
    $cents = \EmbedForms\Payments\Money::toCents($value);
    if ($cents === NULL) {
      return __('Please enter an amount, like 25 or 25.50.', 'embed-forms');
    }
    $mode = $field['amount_mode'] ?? 'choices';
    if ($mode === 'fixed') {
      return NULL;
    }
    $choices = array_column($field['amounts'] ?? [], 'amount');
    $isChoice = in_array($value, $choices, TRUE);
    if ($mode === 'choices' && !$isChoice && empty($field['allow_other'])) {
      return __('Please choose one of the amounts.', 'embed-forms');
    }
    if (!$isChoice) {
      $min = \EmbedForms\Payments\Money::toCents($field['min'] ?? '');
      $max = \EmbedForms\Payments\Money::toCents($field['max'] ?? '');
      if ($min !== NULL && $cents < $min) {
        return sprintf(__('The minimum amount is %s.', 'embed-forms'), \EmbedForms\Payments\Money::format($min));
      }
      if ($max !== NULL && $max > 0 && $cents > $max) {
        return sprintf(__('The maximum amount is %s.', 'embed-forms'), \EmbedForms\Payments\Money::format($max));
      }
    }
    return NULL;
  }

  private static function line(mixed $value, int $max): string {
    if (!is_scalar($value)) {
      return '';
    }
    $value = preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '';
    return mb_substr(trim(strip_tags($value)), 0, $max);
  }

  private static function multiline(mixed $value, int $max): string {
    if (!is_scalar($value)) {
      return '';
    }
    $value = str_replace(["\r\n", "\r"], "\n", (string) $value);
    $value = preg_replace('/[\x00-\x09\x0B-\x1F\x7F]/u', ' ', $value) ?? '';
    return mb_substr(trim(strip_tags($value)), 0, $max);
  }

}
