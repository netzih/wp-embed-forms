<?php

namespace EmbedForms\Payments;

use Usaepay\AmbiguousGatewayException;
use Usaepay\DonorMessage;
use Usaepay\GatewayClient;
use Usaepay\GatewayException;
use Usaepay\ReconciliationInconclusiveException;
use Usaepay\WordPress\BusyException;
use Usaepay\WordPress\Gateway;
use Usaepay\WordPress\Reconcile;

/**
 * Every request to USAePay goes through run(): a USAePay Payments client
 * for the given mode and account and Reconcile::once(), so each charge or
 * refund is sent at most once, with every failure turned into one outcome
 * and payer-safe wording.
 */
final class Charger {

  public const INTEGRATION = 'Embed Forms';

  public static function gateway(): Gateway {
    return \Usaepay\WordPress\Plugin::instance()->gateway();
  }

  public static function mode(): string {
    return \Usaepay\WordPress\Plugin::instance()->settings()->mode();
  }

  /**
   * @param string $account
   *   An account id from Settings > USAePay; '' or 'default' for the
   *   default account.
   */
  public static function client(?string $mode = NULL, string $account = ''): GatewayClient {
    /**
     * Replace the USAePay client, e.g. with one on a fake transport in a
     * test site. Return NULL to use the real one.
     */
    $client = apply_filters('embed_forms_gateway_client', NULL, $mode ?? self::mode(), $account);
    if ($client instanceof GatewayClient) {
      return $client;
    }
    if (Processor::isDefaultAccount($account)) {
      return self::gateway()->client(self::INTEGRATION, $mode);
    }
    if (!Processor::usaepayHasAccounts()) {
      throw new GatewayException(__('This form uses an additional USAePay account, which needs a newer version of USAePay Payments.', 'embed-forms'));
    }
    return self::gateway()->client(self::INTEGRATION, $mode, $account);
  }

  /**
   * @param callable $call
   *   fn(GatewayClient $client): array, the request itself.
   *
   * @return array{outcome: string, response: array, payer: string, gateway: string, reconciled: bool, amount: ?string}
   *   outcome: 'approved', 'declined', 'failed' (nothing happened; safe to
   *   retry) or 'unresolved' (a request may have gone through; the marker
   *   stays and the next attempt looks it up first).
   */
  public static function run(string $mode, callable $read, callable $write, string $orderId, ?string $amount, callable $call, array $types = GatewayClient::TYPES_CHARGE, string $account = ''): array {
    $out = ['outcome' => 'failed', 'response' => [], 'payer' => '', 'gateway' => '', 'reconciled' => FALSE, 'amount' => $amount];
    try {
      $client = self::client($mode, $account);
    }
    catch (GatewayException | \InvalidArgumentException $e) {
      return ['payer' => __('The payment system is not configured correctly, so no charge was made. Please contact us.', 'embed-forms'), 'gateway' => $e->getMessage()] + $out;
    }
    try {
      $result = Reconcile::once($client, $read, $write, $orderId, $amount, $call, $types);
    }
    catch (ReconciliationInconclusiveException $e) {
      return ['outcome' => 'unresolved', 'payer' => __('An earlier attempt to make this payment may have gone through, and the card processor could not confirm it. Nothing was charged now. Please contact us before trying again.', 'embed-forms'), 'gateway' => $e->getMessage()] + $out;
    }
    catch (AmbiguousGatewayException $e) {
      return ['outcome' => 'unresolved', 'payer' => __('The card processor did not respond. Please wait a minute and submit again: you will not be charged twice.', 'embed-forms'), 'gateway' => $e->getMessage()] + $out;
    }
    catch (BusyException $e) {
      return ['outcome' => 'unresolved', 'payer' => Reconcile::busyMessage(), 'gateway' => $e->getMessage()] + $out;
    }
    catch (GatewayException $e) {
      return ['payer' => DonorMessage::donorText($e->getMessage()), 'gateway' => $e->getMessage()] + $out;
    }
    catch (\InvalidArgumentException $e) {
      return ['payer' => __('The payment could not be processed. Please check the card details and try again.', 'embed-forms'), 'gateway' => $e->getMessage()] + $out;
    }
    catch (\RuntimeException $e) {
      return ['payer' => __('The payment could not be processed right now. Please try again in a moment.', 'embed-forms'), 'gateway' => $e->getMessage()] + $out;
    }
    $response = $result['response'];
    if (!Gateway::approved($response)) {
      $failure = Gateway::failure($response);
      return ['outcome' => 'declined', 'response' => $response, 'payer' => $failure['donor'], 'gateway' => $failure['gateway']] + $out;
    }
    return ['outcome' => 'approved', 'response' => $response, 'reconciled' => (bool) $result['reconciled'], 'amount' => $result['amount']] + $out;
  }

  /**
   * Columns of a payment row from an approved response.
   */
  public static function recordColumns(array $response): array {
    $card = Gateway::card($response);
    return [
      'transaction_key' => Gateway::transactionReference($response),
      'refnum' => (string) ($response['refnum'] ?? ''),
      'auth_code' => (string) ($response['authcode'] ?? ''),
      'card_brand' => (string) ($card['brand'] ?? ''),
      'card_last4' => (string) ($card['last4'] ?? ''),
    ];
  }

  /**
   * run()'s result with the row columns, saved card and note, in the shape
   * Stripe\Gateway returns.
   */
  public static function withColumns(array $result, string $mode, string $account): array {
    $response = $result['response'];
    $approved = $result['outcome'] === 'approved';
    return $result + [
      'columns' => $approved ? self::recordColumns($response) : ['transaction_key' => '', 'refnum' => '', 'auth_code' => '', 'card_brand' => '', 'card_last4' => ''],
      'card_reference' => trim((string) ($response['savedcard']['key'] ?? '')),
      'customer_reference' => '',
      'client_secret' => '',
      'note' => $approved ? self::note($response, $mode, $account) : '',
    ];
  }

  public static function note(array $response, string $mode, string $account = ''): string {
    $parts = [sprintf(__('USAePay reference %s', 'embed-forms'), Gateway::transactionReference($response))];
    if (!empty($response['authcode'])) {
      $parts[] = sprintf(__('auth code %s', 'embed-forms'), $response['authcode']);
    }
    if (!empty($response['avs']['result'])) {
      $parts[] = sprintf(__('AVS: %s', 'embed-forms'), $response['avs']['result']);
    }
    if (!empty($response['cvc']['result'])) {
      $parts[] = sprintf(__('CVV: %s', 'embed-forms'), $response['cvc']['result']);
    }
    $card = Gateway::card($response);
    if (!empty($card['last4'])) {
      $parts[] = sprintf(__('%1$s ending in %2$s', 'embed-forms'), $card['brand'] ?: __('Card', 'embed-forms'), $card['last4']);
    }
    if (!Processor::isDefaultAccount($account)) {
      $parts[] = sprintf(__('account %s', 'embed-forms'), Processor::usaepayHasAccounts() ? \Usaepay\WordPress\Plugin::instance()->settings()->accountLabel($account) : $account);
    }
    if ($mode === 'sandbox') {
      $parts[] = __('SANDBOX', 'embed-forms');
    }
    return implode(', ', $parts);
  }

}
