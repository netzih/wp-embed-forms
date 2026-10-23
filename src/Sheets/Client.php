<?php

namespace EmbedForms\Sheets;

use EmbedForms\Settings;

/**
 * The Google Sheets API as the service account saved under Embed Forms >
 * Settings. It signs in with a JWT signed by the account's private key
 * (OAuth 2.0 for server to server) and keeps the access token for most of
 * its hour.
 */
final class Client {

  public const SCOPE = 'https://www.googleapis.com/auth/spreadsheets';

  private const API = 'https://sheets.googleapis.com/v4/spreadsheets/';

  private const DEFAULT_TOKEN_URI = 'https://oauth2.googleapis.com/token';

  public function __construct(private array $account) {}

  /**
   * A client for the saved service account.
   *
   * @throws \RuntimeException
   *   When no service account is saved.
   */
  public static function fromSettings(): self {
    $account = Settings::googleServiceAccount();
    if (!$account) {
      throw new \RuntimeException(__('No Google service account is saved under Embed Forms > Settings.', 'embed-forms'));
    }
    return new self($account);
  }

  /**
   * The title of the spreadsheet's first tab.
   */
  public function firstTab(string $spreadsheet): string {
    $answer = $this->request('GET', $spreadsheet . '?fields=' . rawurlencode('sheets.properties.title'));
    $title = $answer['sheets'][0]['properties']['title'] ?? '';
    if ($title === '') {
      throw new \RuntimeException(__('The spreadsheet has no tabs.', 'embed-forms'));
    }
    return (string) $title;
  }

  /**
   * Cell values of a range, as rows.
   */
  public function get(string $spreadsheet, string $range): array {
    $answer = $this->request('GET', $spreadsheet . '/values/' . rawurlencode($range));
    return is_array($answer['values'] ?? NULL) ? $answer['values'] : [];
  }

  /**
   * Overwrite a range with rows, as written (no formulas run).
   */
  public function put(string $spreadsheet, string $range, array $rows): void {
    $this->request('PUT', $spreadsheet . '/values/' . rawurlencode($range) . '?valueInputOption=RAW', ['values' => $rows]);
  }

  /**
   * Add rows under the table on a tab, as written (no formulas run).
   */
  public function append(string $spreadsheet, string $range, array $rows): void {
    $this->request('POST', $spreadsheet . '/values/' . rawurlencode($range) . ':append?valueInputOption=RAW&insertDataOption=INSERT_ROWS', ['values' => $rows]);
  }

  /**
   * Sign in to Google afresh, to check that the saved key works.
   *
   * @throws \RuntimeException
   */
  public function verify(): void {
    delete_transient($this->tokenKey());
    $this->token();
  }

  private function tokenKey(): string {
    return 'embed_forms_gtoken_' . md5($this->account['client_email'] . "\n" . $this->account['private_key_id']);
  }

  private function request(string $method, string $path, ?array $body = NULL): array {
    /**
     * Replace Google's answer, e.g. with a fake one in a test site. Return
     * NULL to send the request; an array with 'status' and 'body' (decoded
     * JSON) otherwise.
     */
    $fake = apply_filters('embed_forms_sheets_response', NULL, $method, $path, $body);
    if (is_array($fake)) {
      return self::answer((int) ($fake['status'] ?? 200), is_array($fake['body'] ?? NULL) ? $fake['body'] : []);
    }
    $args = [
      'method' => $method,
      'timeout' => 20,
      'headers' => ['Authorization' => 'Bearer ' . $this->token()],
    ];
    if ($body !== NULL) {
      $args['headers']['Content-Type'] = 'application/json';
      $args['body'] = wp_json_encode($body);
    }
    $response = wp_remote_request(self::API . $path, $args);
    if (is_wp_error($response)) {
      throw new \RuntimeException('Google did not answer: ' . $response->get_error_message());
    }
    $decoded = json_decode((string) wp_remote_retrieve_body($response), TRUE);
    return self::answer((int) wp_remote_retrieve_response_code($response), is_array($decoded) ? $decoded : []);
  }

  private static function answer(int $status, array $body): array {
    if ($status >= 200 && $status < 300) {
      return $body;
    }
    $message = (string) ($body['error']['message'] ?? '');
    if ($status === 403 || $status === 404) {
      $message = trim(($message !== '' ? rtrim($message, '.') . '. ' : '') . __('Check the spreadsheet link, and that the spreadsheet is shared with the service account as Editor.', 'embed-forms'));
    }
    throw new \RuntimeException(sprintf('Google Sheets HTTP %d: %s', $status, $message !== '' ? $message : 'no details'));
  }

  /**
   * An access token, from the cache while it has a few minutes left.
   */
  private function token(): string {
    $key = $this->tokenKey();
    $cached = get_transient($key);
    if (is_string($cached) && $cached !== '') {
      return $cached;
    }
    $tokenUri = (string) ($this->account['token_uri'] ?: self::DEFAULT_TOKEN_URI);
    $response = wp_remote_post($tokenUri, [
      'timeout' => 20,
      'body' => [
        'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
        'assertion' => self::jwt($this->account, $tokenUri, time()),
      ],
    ]);
    if (is_wp_error($response)) {
      throw new \RuntimeException('Google sign-in did not answer: ' . $response->get_error_message());
    }
    $body = json_decode((string) wp_remote_retrieve_body($response), TRUE);
    $token = is_array($body) ? (string) ($body['access_token'] ?? '') : '';
    if ($token === '') {
      $reason = is_array($body) ? (string) ($body['error_description'] ?? $body['error'] ?? '') : '';
      throw new \RuntimeException('Google sign-in failed: ' . ($reason !== '' ? $reason : 'HTTP ' . wp_remote_retrieve_response_code($response)));
    }
    set_transient($key, $token, max(60, (int) ($body['expires_in'] ?? 3600) - 300));
    return $token;
  }

  /**
   * The signed assertion that trades for an access token.
   */
  public static function jwt(array $account, string $audience, int $now): string {
    $encode = static fn(string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $header = ['alg' => 'RS256', 'typ' => 'JWT'];
    if (!empty($account['private_key_id'])) {
      $header['kid'] = $account['private_key_id'];
    }
    $claims = [
      'iss' => $account['client_email'],
      'scope' => self::SCOPE,
      'aud' => $audience,
      'iat' => $now,
      'exp' => $now + 3600,
    ];
    $input = $encode((string) json_encode($header)) . '.' . $encode((string) json_encode($claims));
    $key = openssl_pkey_get_private((string) $account['private_key']);
    if (!$key || !openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
      throw new \RuntimeException(__('The service account\'s private key could not be read. Paste the JSON key again under Embed Forms > Settings.', 'embed-forms'));
    }
    return $input . '.' . $encode($signature);
  }

}
