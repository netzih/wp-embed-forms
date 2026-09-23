<?php

namespace EmbedForms\Payments;

/**
 * Installment dates, anchored to the signup day and clamped in short months
 * (the same arithmetic as USAePay Payments' shared schedule, kept here so
 * Stripe forms work without that plugin). Pure PHP.
 */
final class Schedule {

  public const RETRY_DAYS = 3;

  public const MAX_ATTEMPTS = 3;

  public const UNITS = ['day', 'week', 'month', 'year'];

  /**
   * Month and year steps keep the day of the month, clamped to the last day
   * when the target month is shorter (31 Jan + 1 month = 28/29 Feb).
   */
  public static function advance(\DateTimeImmutable $from, int $length, string $unit): \DateTimeImmutable {
    $length = max(1, $length);
    $unit = in_array($unit, self::UNITS, TRUE) ? $unit : 'month';
    if ($unit === 'day' || $unit === 'week') {
      return $from->modify('+' . ($unit === 'week' ? $length * 7 : $length) . ' days');
    }
    $months = $unit === 'year' ? $length * 12 : $length;
    $day = (int) $from->format('j');
    $firstOfTarget = $from->setDate((int) $from->format('Y'), (int) $from->format('n'), 1)->modify('+' . $months . ' months');
    $lastDay = (int) $firstOfTarget->format('t');
    return $firstOfTarget->setDate((int) $firstOfTarget->format('Y'), (int) $firstOfTarget->format('n'), min($day, $lastDay));
  }

  /**
   * Date of installment $index (1-based) counted from the start, so a "31st"
   * schedule returns to the 31st after a short month.
   */
  public static function installmentDate(\DateTimeImmutable $start, int $length, string $unit, int $index): \DateTimeImmutable {
    return self::advance($start, max(1, $length) * max(1, $index), $unit);
  }

  /**
   * The first installment at or after $fromIndex whose date is after $now.
   *
   * @return array{0: int, 1: \DateTimeImmutable}
   */
  public static function nextInstallmentAfter(\DateTimeImmutable $start, \DateTimeImmutable $now, int $length, string $unit, int $fromIndex): array {
    $index = max(1, $fromIndex);
    $date = self::installmentDate($start, $length, $unit, $index);
    $guard = 0;
    while ($date <= $now && $guard++ < 1000) {
      $index++;
      $date = self::installmentDate($start, $length, $unit, $index);
    }
    return [$index, $date];
  }

  public static function orderId(int $subscriptionId, \DateTimeImmutable $scheduled, int $attempt): string {
    return sprintf('ef-s%d-%s-%d', $subscriptionId, $scheduled->format('Y-m-d'), max(0, $attempt));
  }

  public static function utc(string $datetime): \DateTimeImmutable {
    return new \DateTimeImmutable($datetime, new \DateTimeZone('UTC'));
  }

}
