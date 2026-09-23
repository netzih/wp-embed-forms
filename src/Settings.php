<?php

namespace EmbedForms;

/**
 * Site-wide settings (Embed Forms > Settings). USAePay credentials are not
 * here: they live in Settings > USAePay of the USAePay Payments plugin.
 */
final class Settings {

  public const OPTION = 'embed_forms_settings';

  private static ?array $values = NULL;

  public static function defaults(): array {
    return [
      'turnstile_site_key' => '',
      'turnstile_secret_key' => '',
      'from_name' => '',
      'from_email' => '',
      'rate_limit' => 20,
      'failed_payment_limit' => 5,
    ];
  }

  public static function all(): array {
    if (self::$values === NULL) {
      $stored = get_option(self::OPTION, []);
      self::$values = array_merge(self::defaults(), is_array($stored) ? $stored : []);
    }
    return self::$values;
  }

  public static function get(string $key): mixed {
    return self::all()[$key] ?? NULL;
  }

  public static function forget(): void {
    self::$values = NULL;
  }

  public static function turnstileConfigured(): bool {
    return self::get('turnstile_site_key') !== '' && self::get('turnstile_secret_key') !== '';
  }

  public static function sanitize(mixed $input): array {
    $input = is_array($input) ? $input : [];
    $current = self::all();
    $out = self::defaults();
    $out['turnstile_site_key'] = sanitize_text_field((string) ($input['turnstile_site_key'] ?? ''));
    // An empty secret field keeps the stored secret (it is never printed).
    $secret = sanitize_text_field((string) ($input['turnstile_secret_key'] ?? ''));
    $out['turnstile_secret_key'] = $secret !== '' ? $secret : (string) $current['turnstile_secret_key'];
    if (!empty($input['turnstile_clear_secret'])) {
      $out['turnstile_secret_key'] = '';
    }
    $out['from_name'] = sanitize_text_field((string) ($input['from_name'] ?? ''));
    $out['from_email'] = sanitize_email((string) ($input['from_email'] ?? ''));
    $out['rate_limit'] = max(0, min(1000, (int) ($input['rate_limit'] ?? 20)));
    $out['failed_payment_limit'] = max(0, min(100, (int) ($input['failed_payment_limit'] ?? 5)));
    self::$values = NULL;
    return $out;
  }

}
