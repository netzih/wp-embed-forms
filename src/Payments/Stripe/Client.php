<?php

namespace EmbedForms\Payments\Stripe;

/**
 * A minimal Stripe API client on the WordPress HTTP API (no SDK, so the
 * plugin keeps no runtime dependencies).
 *
 * Errors: a StripeError for every answer Stripe gave (card declines,
 * invalid requests, bad keys); an AmbiguousError when no answer came back or
 * Stripe failed on its side, so the request may or may not have happened.
 */
final class Client {

  public const API = 'https://api.stripe.com/v1/';

  public const VERSION = '2024-06-20';

  private string $secret;

  /**
   * Whether the last answer was a replay of an earlier request with the
   * same idempotency key (Stripe's Idempotent-Replayed header).
   */
  public bool $replayed = FALSE;

  public function __construct(string $secret) {
    if ($secret === '') {
      throw new \InvalidArgumentException('Stripe secret key missing.');
    }
    $this->secret = $secret;
  }

  /**
   * @param string|null $idempotencyKey
   *   Stripe answers a repeat of the same key (within 24 hours) with the
   *   first answer instead of doing the request again.
   */
  public function request(string $method, string $path, array $params = [], ?string $idempotencyKey = NULL): array {
    $headers = [
      'Authorization' => 'Bearer ' . $this->secret,
      'Stripe-Version' => self::VERSION,
    ];
    if ($idempotencyKey !== NULL && $idempotencyKey !== '') {
      $headers['Idempotency-Key'] = $idempotencyKey;
    }
    $url = self::API . ltrim($path, '/');
    $body = self::encode($params);
    $args = ['method' => $method, 'headers' => $headers, 'timeout' => 45];
    if ($method === 'GET') {
      $url .= $body !== '' ? '?' . $body : '';
    }
    else {
      $headers['Content-Type'] = 'application/x-www-form-urlencoded';
      $args['headers'] = $headers;
      $args['body'] = $body;
    }

    $this->replayed = FALSE;
    /**
     * Replace Stripe's answer, e.g. with a fake one in a test site. Return
     * NULL to send the request; an array with 'status' and 'body' (decoded
     * JSON), and optionally 'replayed', otherwise.
     */
    $fake = apply_filters('embed_forms_stripe_response', NULL, $method, $path, $params, $idempotencyKey);
    if (is_array($fake)) {
      $this->replayed = !empty($fake['replayed']);
      return self::answer((int) ($fake['status'] ?? 200), is_array($fake['body'] ?? NULL) ? $fake['body'] : []);
    }

    $response = wp_remote_request($url, $args);
    if (is_wp_error($response)) {
      throw new AmbiguousError('Stripe did not answer: ' . $response->get_error_message());
    }
    $status = (int) wp_remote_retrieve_response_code($response);
    $this->replayed = strtolower((string) wp_remote_retrieve_header($response, 'idempotent-replayed')) === 'true';
    $decoded = json_decode((string) wp_remote_retrieve_body($response), TRUE);
    if (!is_array($decoded)) {
      throw new AmbiguousError('Stripe returned an unreadable answer (HTTP ' . $status . ').');
    }
    return self::answer($status, $decoded);
  }

  private static function answer(int $status, array $body): array {
    if ($status >= 200 && $status < 300) {
      return $body;
    }
    // 5xx: Stripe may have done part of the work; a repeat with the same
    // idempotency key finds out. 409: the same key is in use right now.
    if ($status >= 500 || $status === 409) {
      throw new AmbiguousError('Stripe error (HTTP ' . $status . '): ' . (string) ($body['error']['message'] ?? 'no message'));
    }
    throw new StripeError($status, is_array($body['error'] ?? NULL) ? $body['error'] : []);
  }

  /**
   * Stripe's form encoding: nested keys as a[b]=c, lists as a[0]=c,
   * booleans as true/false.
   */
  public static function encode(array $params): string {
    $flat = [];
    $walk = static function ($value, string $prefix) use (&$walk, &$flat): void {
      if (is_array($value)) {
        foreach ($value as $k => $v) {
          $walk($v, $prefix === '' ? (string) $k : $prefix . '[' . $k . ']');
        }
        return;
      }
      if ($value === NULL) {
        return;
      }
      $flat[] = rawurlencode($prefix) . '=' . rawurlencode(is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
    };
    $walk($params, '');
    return implode('&', $flat);
  }

}
