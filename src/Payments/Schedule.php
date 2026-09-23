<?php

namespace EmbedForms\Payments;

/**
 * Installment dates, from USAePay Payments' shared schedule (anchored to the
 * signup day, clamped in short months). Uses Usaepay\WordPress\Schedule
 * when that plugin has it, its Gravity Forms copy otherwise.
 */
final class Schedule {

  public const RETRY_DAYS = 3;

  public const MAX_ATTEMPTS = 3;

  private static function impl(): string {
    return class_exists('\Usaepay\WordPress\Schedule') ? '\Usaepay\WordPress\Schedule' : '\Usaepay\WordPress\Modules\GravityForms\Schedule';
  }

  public static function installmentDate(\DateTimeImmutable $start, int $length, string $unit, int $index): \DateTimeImmutable {
    return (self::impl())::installmentDate($start, $length, $unit, $index);
  }

  /**
   * @return array{0: int, 1: \DateTimeImmutable}
   */
  public static function nextInstallmentAfter(\DateTimeImmutable $start, \DateTimeImmutable $now, int $length, string $unit, int $fromIndex): array {
    return (self::impl())::nextInstallmentAfter($start, $now, $length, $unit, $fromIndex);
  }

  public static function orderId(int $subscriptionId, \DateTimeImmutable $scheduled, int $attempt): string {
    return sprintf('ef-s%d-%s-%d', $subscriptionId, $scheduled->format('Y-m-d'), max(0, $attempt));
  }

  public static function utc(string $datetime): \DateTimeImmutable {
    return new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
  }

}
