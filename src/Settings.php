<?php

namespace EmbedForms;

/**
 * Site-wide settings (Embed Forms > Settings), including the Stripe
 * accounts forms can charge to. USAePay credentials are not here: they live
 * in Settings > USAePay of the USAePay Payments plugin.
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
      'stripe_mode' => 'test',
      'stripe_accounts' => [],
    ];
  }

  public const STRIPE_KEYS = ['test_publishable_key', 'test_secret_key', 'live_publishable_key', 'live_secret_key'];

  /**
   * 'live' or 'sandbox' (Stripe test mode), in the words payment rows use.
   */
  public static function stripeMode(): string {
    return self::get('stripe_mode') === 'live' ? 'live' : 'sandbox';
  }

  /**
   * Stripe accounts by id.
   *
   * @return array<string, array{id: string, label: string, test_publishable_key: string, test_secret_key: string, live_publishable_key: string, live_secret_key: string}>
   */
  public static function stripeAccounts(): array {
    $out = [];
    foreach ((array) self::get('stripe_accounts') as $account) {
      if (is_array($account) && !empty($account['id'])) {
        $out[(string) $account['id']] = $account + array_fill_keys(self::STRIPE_KEYS, '') + ['label' => (string) $account['id']];
      }
    }
    return $out;
  }

  /**
   * One key of a Stripe account for a mode ('live' or 'sandbox').
   */
  public static function stripeKey(string $account, string $mode, string $kind): string {
    $prefix = $mode === 'live' ? 'live_' : 'test_';
    return trim((string) (self::stripeAccounts()[$account][$prefix . $kind . '_key'] ?? ''));
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
    $out['stripe_mode'] = ($input['stripe_mode'] ?? '') === 'live' ? 'live' : 'test';
    $out['stripe_accounts'] = self::sanitizeStripeAccounts($input['stripe_accounts'] ?? [], self::stripeAccounts());
    self::$values = NULL;
    return $out;
  }

  /**
   * Rows of the Stripe accounts table. A saved row keeps its id (payments
   * refer to it); a new one gets an id from its label. A blank secret key
   * keeps the stored one, which is never printed back.
   *
   * @param array<string, array> $current
   */
  public static function sanitizeStripeAccounts(mixed $rows, array $current): array {
    $out = [];
    $taken = [];
    foreach (is_array($rows) ? $rows : [] as $row) {
      if (!is_array($row) || !empty($row['remove'])) {
        continue;
      }
      $label = sanitize_text_field((string) ($row['label'] ?? ''));
      $id = sanitize_key((string) ($row['id'] ?? ''));
      $existing = $id !== '' && isset($current[$id]) ? $current[$id] : NULL;
      $account = ['id' => '', 'label' => $label];
      foreach (['test', 'live'] as $mode) {
        $account[$mode . '_publishable_key'] = sanitize_text_field((string) ($row[$mode . '_publishable_key'] ?? ''));
        $secret = sanitize_text_field((string) ($row[$mode . '_secret_key'] ?? ''));
        $account[$mode . '_secret_key'] = $secret !== '' ? $secret : (string) ($existing[$mode . '_secret_key'] ?? '');
        if (!empty($row[$mode . '_clear_secret'])) {
          $account[$mode . '_secret_key'] = '';
        }
      }
      if ($label === '' && $account['test_publishable_key'] === '' && $account['live_publishable_key'] === '') {
        continue;
      }
      if ($existing === NULL) {
        $base = sanitize_key(str_replace(' ', '-', strtolower($label))) ?: 'stripe';
        $base = ctype_digit($base) ? 'stripe-' . $base : $base;
        $id = $base;
        for ($n = 2; isset($taken[$id]) || isset($current[$id]); $n++) {
          $id = $base . '-' . $n;
        }
      }
      if (isset($taken[$id])) {
        continue;
      }
      $taken[$id] = TRUE;
      $account['id'] = $id;
      $account['label'] = $label !== '' ? $label : $id;
      $out[] = $account;
    }
    return $out;
  }

}
