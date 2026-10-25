<?php

namespace EmbedForms\Orders;

/**
 * A tracking number's carrier page, recognised by the number's shape
 * (UPS, USPS, FedEx). A pasted https link is used as it is.
 */
final class Tracking {

  public static function clean(string $number): string {
    $number = trim($number);
    if (preg_match('#^https://\S+$#i', $number)) {
      return mb_substr($number, 0, 190);
    }
    return mb_substr(strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $number)), 0, 64);
  }

  /**
   * @return array{carrier: string, url: string}
   */
  public static function link(string $number): array {
    $number = self::clean($number);
    if ($number === '') {
      return ['carrier' => '', 'url' => ''];
    }
    if (preg_match('#^https://#i', $number)) {
      return ['carrier' => '', 'url' => $number];
    }
    if (preg_match('/^1Z[0-9A-Z]{16}$/', $number)) {
      return ['carrier' => 'UPS', 'url' => 'https://www.ups.com/track?tracknum=' . $number];
    }
    if (preg_match('/^(9\d{19,21}|\d{20}|\d{22}|[A-Z]{2}\d{9}US)$/', $number)) {
      return ['carrier' => 'USPS', 'url' => 'https://tools.usps.com/go/TrackConfirmAction?tLabels=' . $number];
    }
    if (preg_match('/^(\d{12}|\d{15})$/', $number)) {
      return ['carrier' => 'FedEx', 'url' => 'https://www.fedex.com/fedextrack/?trknbr=' . $number];
    }
    return ['carrier' => '', 'url' => ''];
  }

}
