<?php

namespace EmbedForms\Payments;

use EmbedForms\Settings;

/**
 * Which processor and account take a form's payments: USAePay (through the
 * USAePay Payments plugin, the default or one of its additional accounts)
 * or Stripe (an account under Embed Forms > Settings).
 *
 * A payment or subscription row records its gateway and account when it is
 * created, and every later request for it (renewal, refund, lookup) goes to
 * those, never to the form's current choice.
 */
final class Processor {

  public const USAEPAY = 'usaepay';

  public const STRIPE = 'stripe';

  /**
   * @return array{gateway: string, account: string}
   */
  public static function forForm(array $form): array {
    $payment = $form['settings']['payment'] ?? [];
    if (($payment['processor'] ?? '') === self::STRIPE) {
      return ['gateway' => self::STRIPE, 'account' => (string) ($payment['stripe_account'] ?? '')];
    }
    return ['gateway' => self::USAEPAY, 'account' => (string) ($payment['usaepay_account'] ?? '') ?: 'default'];
  }

  public static function usaepayActive(): bool {
    return class_exists('\Usaepay\WordPress\Plugin');
  }

  /**
   * USAePay Payments can charge accounts other than its default one.
   */
  public static function usaepayHasAccounts(): bool {
    return self::usaepayActive() && method_exists('\Usaepay\WordPress\Settings', 'accounts');
  }

  public static function isDefaultAccount(string $account): bool {
    return $account === '' || $account === 'default';
  }

  /**
   * The live/sandbox mode of a processor.
   */
  public static function mode(string $gateway): string {
    if ($gateway === self::STRIPE) {
      return Settings::stripeMode();
    }
    return self::usaepayActive() ? \Usaepay\WordPress\Plugin::instance()->settings()->mode() : 'sandbox';
  }

  /**
   * Whether server-side requests (charges, refunds, renewals) can be sent
   * for this gateway and account in this mode.
   */
  public static function serverReady(string $gateway, string $account, ?string $mode = NULL): bool {
    $mode = $mode ?? self::mode($gateway);
    if ($gateway === self::STRIPE) {
      return $account !== '' && Settings::stripeKey($account, $mode, 'secret') !== '';
    }
    if (!self::usaepayActive()) {
      return FALSE;
    }
    $settings = \Usaepay\WordPress\Plugin::instance()->settings();
    if (!self::usaepayHasAccounts()) {
      return self::isDefaultAccount($account) && $settings->hasApiCredentials($mode);
    }
    return $settings->hasAccount($account) && $settings->hasApiCredentials($mode, $account);
  }

  /**
   * Whether a form's checkout can take payments: the server credentials
   * plus the browser key.
   */
  public static function checkoutReady(array $form): bool {
    ['gateway' => $gateway, 'account' => $account] = self::forForm($form);
    $mode = self::mode($gateway);
    if (!self::serverReady($gateway, $account, $mode)) {
      return FALSE;
    }
    if ($gateway === self::STRIPE) {
      return Settings::stripeKey($account, $mode, 'publishable') !== '';
    }
    $settings = \Usaepay\WordPress\Plugin::instance()->settings();
    return self::usaepayHasAccounts() ? $settings->isConfigured($mode, $account) : $settings->isConfigured($mode);
  }

  /**
   * For the admin: "USAePay (Default account)", "Stripe (Camp)".
   */
  public static function label(string $gateway, string $account): string {
    if ($gateway === self::STRIPE) {
      $accounts = Settings::stripeAccounts();
      return sprintf(__('Stripe (%s)', 'embed-forms'), $accounts[$account]['label'] ?? ($account !== '' ? $account : __('no account', 'embed-forms')));
    }
    if (self::usaepayHasAccounts()) {
      return sprintf(__('USAePay (%s)', 'embed-forms'), \Usaepay\WordPress\Plugin::instance()->settings()->accountLabel($account));
    }
    return 'USAePay';
  }

  /**
   * Choices for the form editor: id => label, per gateway.
   *
   * @return array{usaepay: array<string, string>, stripe: array<string, string>}
   */
  public static function accountChoices(): array {
    $usaepay = [];
    if (self::usaepayHasAccounts()) {
      $usaepay = \Usaepay\WordPress\Plugin::instance()->settings()->accounts();
    }
    elseif (self::usaepayActive()) {
      $usaepay = ['default' => __('Default account', 'embed-forms')];
    }
    return [
      self::USAEPAY => $usaepay,
      self::STRIPE => array_map(static fn(array $a) => $a['label'], Settings::stripeAccounts()),
    ];
  }

  /**
   * Orderids and idempotency keys carry a short site prefix, so two sites on
   * one merchant account never match each other's requests. Same prefix as
   * USAePay Payments' Gateway::orderId().
   */
  public static function orderId(string $id): string {
    if (class_exists('\Usaepay\WordPress\Gateway')) {
      return \Usaepay\WordPress\Gateway::orderId($id);
    }
    $prefix = apply_filters('usaepay_payments_orderid_prefix', substr(md5((string) home_url()), 0, 6));
    $prefix = preg_replace('/[^A-Za-z0-9]/', '', (string) $prefix);
    return ($prefix !== '' ? $prefix . '-' : '') . $id;
  }

}
