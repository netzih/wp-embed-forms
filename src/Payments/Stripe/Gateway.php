<?php

namespace EmbedForms\Payments\Stripe;

use EmbedForms\Payments\Lock;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Payer;
use EmbedForms\Settings;

/**
 * Charges, renewals and refunds at Stripe, each sent at most once.
 *
 * Every request carries an idempotency key (the payment's orderid) and is
 * stored on its row before it is sent (the marker). A later attempt for the
 * same row sends the stored request again, unchanged and with the same key,
 * so Stripe answers with the first result instead of charging again, even
 * when the payer has since entered a different card. Stripe keeps keys for
 * 24 hours; a marker older than that is looked up by its orderid in the
 * metadata before anything new is sent.
 *
 * Results have the shape of Charger::run() plus the row columns: outcome
 * (approved, declined, failed, unresolved, or action when the card needs
 * 3D Secure in the browser), payer and gateway messages, reconciled, amount,
 * columns, card_reference, customer_reference, client_secret, note.
 */
final class Gateway {

  private const STALE_AFTER = 23 * HOUR_IN_SECONDS;

  public static function client(string $account, string $mode): Client {
    /**
     * Replace the Stripe client, e.g. in a test site. Return NULL to use the
     * real one.
     */
    $client = apply_filters('embed_forms_stripe_client', NULL, $account, $mode);
    if ($client instanceof Client) {
      return $client;
    }
    return new Client(Settings::stripeKey($account, $mode, 'secret'));
  }

  /**
   * The first payment of a submission.
   *
   * @param array $row
   *   The payment row (pending). Its transaction_key holds the PaymentIntent
   *   when an earlier attempt stopped for 3D Secure.
   * @param array $args
   *   payment_method (from Stripe.js), amount, recurring, payer, description,
   *   metadata, read, write.
   */
  public static function firstPayment(array $row, array $args): array {
    $mode = (string) $row['mode'];
    $account = (string) $row['account'];
    try {
      $client = self::client($account, $mode);
    }
    catch (\InvalidArgumentException $e) {
      return self::result('failed', self::notConfigured(), $e->getMessage());
    }

    // Back from 3D Secure: the PaymentIntent exists, read where it got to.
    if (str_starts_with((string) $row['transaction_key'], 'pi_')) {
      try {
        $intent = $client->request('GET', 'payment_intents/' . rawurlencode($row['transaction_key']), ['expand' => ['latest_charge']]);
      }
      catch (StripeError | AmbiguousError $e) {
        return self::result('unresolved', __('The card processor could not confirm the payment. Please wait a minute and submit again: you will not be charged twice.', 'embed-forms'), $e->getMessage());
      }
      return self::fromIntent($intent, $account, $mode, FALSE);
    }

    $pm = trim((string) $args['payment_method']);
    $marker = ($args['read'])();
    $replaying = is_array($marker) && ($marker['orderid'] ?? '') === $row['orderid'] && isset($marker['request']);
    if (!$replaying && !preg_match('/^pm_[A-Za-z0-9]+$/', $pm)) {
      return self::result('failed', __('Please enter your card details.', 'embed-forms'), 'no payment method');
    }

    $request = NULL;
    if (!$replaying) {
      $cents = Money::toCents($args['amount']) ?? 0;
      $params = [
        'amount' => $cents,
        'currency' => 'usd',
        'payment_method' => $pm,
        'payment_method_types' => ['card'],
        'confirm' => TRUE,
        'description' => mb_substr($args['description'], 0, 500),
        'metadata' => $args['metadata'] + ['orderid' => $row['orderid']],
        'expand' => ['latest_charge'],
      ];
      if ($args['recurring']) {
        // A card can only be charged again from a customer it is saved on.
        try {
          $customer = $client->request('POST', 'customers', self::customerParams($args['payer'], $args['metadata']));
        }
        catch (StripeError | AmbiguousError $e) {
          return self::result('failed', __('The payment could not be processed right now. Please try again in a moment.', 'embed-forms'), 'customer: ' . $e->getMessage());
        }
        $params['customer'] = (string) $customer['id'];
        $params['setup_future_usage'] = 'off_session';
      }
      $request = ['method' => 'POST', 'path' => 'payment_intents', 'params' => $params];
    }

    $sent = self::run($client, $args['read'], $args['write'], $row['orderid'], $args['amount'], $request, static fn(Client $c) => self::searchIntent($c, $row['orderid']));
    if (isset($sent['result'])) {
      return $sent['result'];
    }
    return self::fromIntent($sent['response'], $account, $mode, $sent['reconciled']);
  }

  /**
   * One installment of a subscription, off session against its saved card.
   */
  public static function renewal(array $sub, string $orderId, string $amount, array $metadata, string $description, callable $read, callable $write): array {
    $mode = (string) $sub['mode'];
    $account = (string) $sub['account'];
    try {
      $client = self::client($account, $mode);
    }
    catch (\InvalidArgumentException $e) {
      return self::result('failed', self::notConfigured(), $e->getMessage());
    }
    $request = ['method' => 'POST', 'path' => 'payment_intents', 'params' => [
      'amount' => Money::toCents($amount) ?? 0,
      'currency' => 'usd',
      'customer' => (string) $sub['customer_reference'],
      'payment_method' => (string) $sub['card_reference'],
      'payment_method_types' => ['card'],
      'off_session' => TRUE,
      'confirm' => TRUE,
      'description' => mb_substr($description, 0, 500),
      'metadata' => $metadata + ['orderid' => $orderId],
      'expand' => ['latest_charge'],
    ]];
    $sent = self::run($client, $read, $write, $orderId, $amount, $request, static fn(Client $c) => self::searchIntent($c, $orderId));
    if (isset($sent['result'])) {
      return $sent['result'];
    }
    $result = self::fromIntent($sent['response'], $account, $mode, $sent['reconciled']);
    if ($result['outcome'] === 'action') {
      // Nobody is there to authenticate an off-session charge.
      return self::result('declined', '', __('The bank asked the cardholder to authenticate this payment, which cannot be done for a recurring charge.', 'embed-forms'));
    }
    return $result;
  }

  /**
   * Refund part or all of a payment. The key is fixed by what was refunded
   * before, so a repeat of the same refund is recognised.
   */
  public static function refund(array $payment, int $cents, int $alreadyCents, callable $read, callable $write): array {
    $mode = (string) $payment['mode'];
    $account = (string) $payment['account'];
    try {
      $client = self::client($account, $mode);
    }
    catch (\InvalidArgumentException $e) {
      return self::result('failed', '', $e->getMessage());
    }
    $intent = (string) $payment['transaction_key'];
    $key = $payment['orderid'] . '-refund-' . $alreadyCents . '-' . $cents;
    $request = ['method' => 'POST', 'path' => 'refunds', 'params' => [
      'payment_intent' => $intent,
      'amount' => $cents,
      'metadata' => ['orderid' => $key],
    ]];
    $lookup = static function (Client $c) use ($intent, $key): ?array {
      $list = $c->request('GET', 'refunds', ['payment_intent' => $intent, 'limit' => 100]);
      foreach ((array) ($list['data'] ?? []) as $refund) {
        if (($refund['metadata']['orderid'] ?? '') === $key) {
          return $refund;
        }
      }
      return NULL;
    };
    $sent = self::run($client, $read, $write, $key, Money::fromCents($cents), $request, $lookup);
    if (isset($sent['result'])) {
      return $sent['result'];
    }
    $refund = $sent['response'];
    $status = (string) ($refund['status'] ?? '');
    if (in_array($status, ['failed', 'canceled'], TRUE)) {
      return self::result('declined', '', 'refund ' . $status . ': ' . (string) ($refund['failure_reason'] ?? ''));
    }
    return self::result('approved', '', '', [
      'reconciled' => $sent['reconciled'],
      'amount' => Money::fromCents((int) ($refund['amount'] ?? $cents)),
      'columns' => ['transaction_key' => (string) ($refund['id'] ?? ''), 'refnum' => (string) ($refund['charge'] ?? '')],
      'response' => $refund,
    ]);
  }

  /**
   * Send a request at most once (see the class comment).
   *
   * @param array|null $request
   *   method, path, params; NULL to send the stored one again.
   * @param callable $lookup
   *   fn(Client): ?array, the object made by the request, for markers older
   *   than Stripe keeps idempotency keys.
   *
   * @return array
   *   ['response' => array, 'reconciled' => bool] once Stripe answered with
   *   success, or ['result' => array] with a declined, failed or unresolved
   *   result.
   */
  public static function run(Client $client, callable $read, callable $write, string $key, ?string $amount, ?array $request, callable $lookup): array {
    $lockName = 'ef_stripe_' . md5($key);
    $lock = Lock::acquire($lockName, 120);
    if ($lock === NULL) {
      return ['result' => self::result('unresolved', __('This payment is already being processed. Please wait a moment before trying again.', 'embed-forms'), 'busy: ' . $key)];
    }
    try {
      $marker = $read();
      $reconciled = FALSE;
      if (is_array($marker) && ($marker['orderid'] ?? '') === $key && is_array($marker['request'] ?? NULL)) {
        if (time() - (int) ($marker['sent_at'] ?? 0) > self::STALE_AFTER) {
          try {
            $found = $lookup($client);
          }
          catch (StripeError | AmbiguousError $e) {
            return ['result' => self::result('unresolved', self::unconfirmed(), 'lookup: ' . $e->getMessage())];
          }
          if ($found !== NULL) {
            return ['response' => $found, 'reconciled' => TRUE];
          }
          if ($request === NULL) {
            // Never arrived; the stored request goes out again.
            $request = $marker['request'];
          }
          $write(['orderid' => $key, 'sent_at' => time(), 'amount' => $amount, 'request' => $request]);
        }
        else {
          $request = $marker['request'];
        }
      }
      elseif ($request !== NULL) {
        $write(['orderid' => $key, 'sent_at' => time(), 'amount' => $amount, 'request' => $request]);
      }
      else {
        return ['result' => self::result('failed', __('The payment could not be processed right now. Please try again in a moment.', 'embed-forms'), 'nothing to send')];
      }

      try {
        $response = $client->request($request['method'], $request['path'], $request['params'], $key);
        return ['response' => $response, 'reconciled' => $client->replayed];
      }
      catch (AmbiguousError $e) {
        return ['result' => self::result('unresolved', __('The card processor did not respond. Please wait a minute and submit again: you will not be charged twice.', 'embed-forms'), $e->getMessage())];
      }
      catch (StripeError $e) {
        if ($e->isIdempotencyConflict()) {
          return ['result' => self::result('unresolved', self::unconfirmed(), $e->getMessage())];
        }
        if ($e->isCardError()) {
          return ['result' => self::result('declined', $e->getMessage(), $e->detail(), ['response' => $e->paymentIntent() ?? []])];
        }
        $payer = $e->status === 401 || $e->type() === 'authentication_error'
          ? self::notConfigured()
          : __('The payment could not be processed. Please check the card details and try again.', 'embed-forms');
        return ['result' => self::result('failed', $payer, $e->detail())];
      }
    }
    finally {
      Lock::release($lockName, $lock);
    }
  }

  /**
   * A result from a PaymentIntent in whatever state it reached.
   */
  public static function fromIntent(array $intent, string $account, string $mode, bool $reconciled): array {
    $status = (string) ($intent['status'] ?? '');
    $charge = is_array($intent['latest_charge'] ?? NULL) ? $intent['latest_charge'] : [];
    $card = $charge['payment_method_details']['card'] ?? [];
    $columns = [
      'transaction_key' => (string) ($intent['id'] ?? ''),
      'refnum' => (string) ($charge['id'] ?? (is_string($intent['latest_charge'] ?? NULL) ? $intent['latest_charge'] : '')),
      'auth_code' => '',
      'card_brand' => self::brand((string) ($card['brand'] ?? '')),
      'card_last4' => (string) ($card['last4'] ?? ''),
    ];
    $extra = [
      'reconciled' => $reconciled,
      'columns' => $columns,
      'response' => $intent,
      'card_reference' => is_array($intent['payment_method'] ?? NULL) ? (string) ($intent['payment_method']['id'] ?? '') : (string) ($intent['payment_method'] ?? ''),
      'customer_reference' => is_array($intent['customer'] ?? NULL) ? (string) ($intent['customer']['id'] ?? '') : (string) ($intent['customer'] ?? ''),
    ];
    switch ($status) {
      case 'succeeded':
        $extra['amount'] = Money::fromCents((int) ($intent['amount_received'] ?? $intent['amount'] ?? 0));
        $extra['note'] = self::note($columns, $account, $mode);
        return self::result('approved', '', '', $extra);

      case 'requires_action':
        return self::result('action', __('Please complete the check from your bank to finish the payment.', 'embed-forms'), 'requires_action', $extra + ['client_secret' => (string) ($intent['client_secret'] ?? '')]);

      case 'processing':
        return self::result('unresolved', __('Your bank is still processing the payment. Please wait a minute and submit again: you will not be charged twice.', 'embed-forms'), 'processing', $extra);

      case 'requires_payment_method':
        $error = $intent['last_payment_error'] ?? [];
        $message = (string) ($error['message'] ?? __('Your card was declined.', 'embed-forms'));
        return self::result('declined', $message, trim($message . ' ' . (string) ($error['decline_code'] ?? $error['code'] ?? '')), $extra);

      default:
        return self::result('failed', __('The payment could not be processed. Please try again.', 'embed-forms'), 'payment intent ' . $status, $extra);
    }
  }

  public static function note(array $columns, string $account, string $mode): string {
    $parts = [sprintf(__('Stripe payment %s', 'embed-forms'), $columns['transaction_key'])];
    if ($columns['refnum'] !== '') {
      $parts[] = sprintf(__('charge %s', 'embed-forms'), $columns['refnum']);
    }
    if ($columns['card_last4'] !== '') {
      $parts[] = sprintf(__('%1$s ending in %2$s', 'embed-forms'), $columns['card_brand'] ?: __('Card', 'embed-forms'), $columns['card_last4']);
    }
    $parts[] = sprintf(__('account %s', 'embed-forms'), Settings::stripeAccounts()[$account]['label'] ?? $account);
    if ($mode === 'sandbox') {
      $parts[] = __('TEST MODE', 'embed-forms');
    }
    return implode(', ', $parts);
  }

  private static function searchIntent(Client $client, string $orderId): ?array {
    $found = $client->request('GET', 'payment_intents/search', ['query' => "metadata['orderid']:'" . str_replace("'", "\\'", $orderId) . "'", 'expand' => ['data.latest_charge']]);
    $data = (array) ($found['data'] ?? []);
    return $data ? $data[0] : NULL;
  }

  private static function customerParams(array $payer, array $metadata): array {
    return array_filter([
      'email' => (string) ($payer['email'] ?? ''),
      'name' => Payer::name($payer),
      'phone' => (string) ($payer['phone'] ?? ''),
      'metadata' => $metadata,
    ], static fn($v) => $v !== '' && $v !== []);
  }

  private static function brand(string $brand): string {
    $names = ['visa' => 'Visa', 'mastercard' => 'Mastercard', 'amex' => 'American Express', 'discover' => 'Discover', 'diners' => 'Diners Club', 'jcb' => 'JCB', 'unionpay' => 'UnionPay'];
    return $names[$brand] ?? ucfirst($brand);
  }

  private static function notConfigured(): string {
    return __('The payment system is not configured correctly, so no charge was made. Please contact us.', 'embed-forms');
  }

  private static function unconfirmed(): string {
    return __('An earlier attempt to make this payment may have gone through, and the card processor could not confirm it yet. Nothing was charged now. Please wait a minute and submit again.', 'embed-forms');
  }

  private static function result(string $outcome, string $payer, string $gateway, array $extra = []): array {
    return $extra + [
      'outcome' => $outcome,
      'payer' => $payer,
      'gateway' => $gateway,
      'reconciled' => FALSE,
      'amount' => NULL,
      'columns' => ['transaction_key' => '', 'refnum' => '', 'auth_code' => '', 'card_brand' => '', 'card_last4' => ''],
      'card_reference' => '',
      'customer_reference' => '',
      'client_secret' => '',
      'note' => '',
      'response' => [],
    ];
  }

}
