<?php

namespace EmbedForms\Payments;

/**
 * Amounts as decimal strings with two places ("12.50"), the form USAePay
 * takes. Arithmetic is done in integer cents so totals never drift.
 */
final class Money {

  /**
   * "$1,234.5" or "12" or 12.5 -> cents; NULL when it is not an amount.
   */
  public static function toCents(mixed $value): ?int {
    if (is_int($value) || is_float($value)) {
      $value = (string) $value;
    }
    if (!is_string($value)) {
      return NULL;
    }
    $value = str_replace([',', '$', ' '], '', trim($value));
    if (!preg_match('/^\d{1,9}(\.\d{0,2})?$/', $value)) {
      return NULL;
    }
    [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
    return (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
  }

  public static function fromCents(int $cents): string {
    return intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
  }

  public static function normalize(mixed $value): ?string {
    $cents = self::toCents($value);
    return $cents === NULL ? NULL : self::fromCents($cents);
  }

  public static function format(int|string $amount): string {
    $cents = is_int($amount) ? $amount : (self::toCents($amount) ?? 0);
    return '$' . number_format($cents / 100, 2);
  }

}
