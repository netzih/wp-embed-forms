<?php

namespace EmbedForms\Security;

/**
 * The signed token the public form page hands the browser and the submit
 * endpoint checks. Forms run in third-party iframes where WordPress cookies
 * (and so nonces) are unavailable; this proves the submission started from a
 * page this site rendered for that form, not too long ago and not
 * implausibly fast.
 */
final class Token {

  public const MAX_AGE = DAY_IN_SECONDS;

  /**
   * Humans need at least this long to fill in a form.
   */
  public const MIN_AGE = 2;

  public static function issue(int $formId, string $secret, ?int $now = NULL): string {
    $payload = self::encode(wp_json_encode(['f' => $formId, 't' => $now ?? time(), 'n' => bin2hex(random_bytes(6))]));
    return $payload . '.' . self::encode(hash_hmac('sha256', $payload, $secret, TRUE));
  }

  /**
   * @return string|null
   *   NULL when valid, otherwise why not: 'invalid', 'expired' or 'too_fast'.
   */
  public static function check(string $token, int $formId, string $secret, ?int $now = NULL): ?string {
    $now = $now ?? time();
    $parts = explode('.', $token);
    if (count($parts) !== 2) {
      return 'invalid';
    }
    [$payload, $signature] = $parts;
    if (!hash_equals(self::encode(hash_hmac('sha256', $payload, $secret, TRUE)), $signature)) {
      return 'invalid';
    }
    $data = json_decode((string) self::decode($payload), TRUE);
    if (!is_array($data) || (int) ($data['f'] ?? 0) !== $formId) {
      return 'invalid';
    }
    $age = $now - (int) ($data['t'] ?? 0);
    if ($age > self::MAX_AGE) {
      return 'expired';
    }
    if ($age < self::MIN_AGE) {
      return 'too_fast';
    }
    return NULL;
  }

  public static function secret(): string {
    return hash('sha256', wp_salt('nonce') . '|embed-forms');
  }

  private static function encode(string $bytes): string {
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
  }

  private static function decode(string $text): string|false {
    return base64_decode(strtr($text, '-_', '+/'), TRUE);
  }

}
