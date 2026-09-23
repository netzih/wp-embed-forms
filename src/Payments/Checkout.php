<?php

namespace EmbedForms\Payments;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Schema\Fields;
use Usaepay\WordPress\Gateway;

/**
 * The payment of a submission: one charge of the card entered in the
 * browser (a USAePay payment key or a Stripe payment method), and for a
 * recurring choice a saved card and a subscription this site charges on
 * each renewal date. The form's processor and account are recorded on the
 * payment row and used for everything that follows.
 *
 * Retries are safe. The browser sends the same submission key with every
 * attempt of one page view, so a resubmit after a decline or a lost answer
 * reuses its entry; a payment row still in flight is reused with its
 * orderid, and the earlier request is looked up (USAePay: Reconcile) or
 * repeated under the same idempotency key (Stripe) before anything new is
 * sent.
 */
final class Checkout {

  /**
   * @return array{ok: bool, status: int, message: string, unresolved: bool, declined: bool, action: ?array, tags: array<string, string>, payment: ?array}
   */
  public static function pay(array $form, array $entry, array $values, array $pricing, array $request): array {
    ['gateway' => $gateway, 'account' => $account] = Processor::forForm($form);
    $key = trim((string) ($request['payment_key'] ?? ''));
    $method = ($request['payment_method'] ?? '') === 'applepay' ? 'applepay' : 'card';
    $recurring = $pricing['frequency'] !== 'once';
    if ($recurring && $method === 'applepay') {
      return self::fail(400, __('Apple Pay is for one-time payments. Please enter your card details for a recurring payment.', 'embed-forms'));
    }

    $total = $pricing['total'];
    $entryId = (int) $entry['id'];

    // A row still waiting for its answer is retried under its own orderid,
    // at the processor and account it was sent to.
    $row = NULL;
    $attempts = 0;
    foreach (Payments::forEntry($entryId) as $payment) {
      if ($payment['kind'] !== 'charge') {
        continue;
      }
      $attempts++;
      if ($payment['status'] === 'pending') {
        $row = $payment;
      }
    }
    if ($row !== NULL && ($row['gateway'] !== $gateway || !self::sameAccount($row['account'], $account))) {
      if ($row['marker'] !== NULL || str_starts_with((string) $row['transaction_key'], 'pi_')) {
        return ['unresolved' => TRUE] + self::fail(409, __('An earlier attempt to make this payment has not been confirmed yet. Nothing was charged now. Please contact us before trying again.', 'embed-forms'));
      }
      // Nothing was sent for it: set it aside and start again.
      Payments::update($row['id'], ['status' => 'failed', 'gateway_message' => 'processor changed before it was sent']);
      $row = NULL;
    }
    // A Stripe attempt in flight is finished from what is stored on its row
    // (the request, or the PaymentIntent waiting for 3D Secure).
    $resuming = $gateway === Processor::STRIPE && $row !== NULL && ($row['marker'] !== NULL || str_starts_with((string) $row['transaction_key'], 'pi_'));
    if ($key === '' && !$resuming) {
      return self::fail(400, __('Please enter your card details.', 'embed-forms'));
    }
    if ($row === NULL) {
      if (!Processor::serverReady($gateway, $account)) {
        return self::fail(503, __('This form cannot take payments right now. Please contact us.', 'embed-forms'));
      }
      $mode = Processor::mode($gateway);
      $orderId = Processor::orderId('ef-' . $entryId . '-' . ($attempts + 1));
      $paymentId = Payments::create([
        'entry_id' => $entryId,
        'kind' => 'charge',
        'status' => 'pending',
        'amount' => $total,
        'mode' => $mode,
        'gateway' => $gateway,
        'account' => $account,
        'method' => $method,
        'orderid' => $orderId,
      ]);
      $row = Payments::find($paymentId);
    }
    if (!$row) {
      return self::fail(500, __('The payment could not be started. Please try again.', 'embed-forms'));
    }
    $mode = (string) ($row['mode'] ?: Processor::mode($gateway));

    $payer = Payer::fromValues($form['schema'], $values);
    $description = $form['title'] . ($recurring ? ' (' . Fields::frequencyLabel($pricing['frequency']) . ')' : '');
    $invoice = 'EF' . $form['id'] . '-' . $entryId;
    [$read, $write] = Payments::markerStore($row['id']);

    if ($gateway === Processor::STRIPE) {
      $result = Stripe\Gateway::firstPayment($row, [
        'payment_method' => $key,
        'amount' => $total,
        'recurring' => $recurring,
        'payer' => $payer,
        'description' => $description,
        'metadata' => ['invoice' => $invoice, 'entry_id' => (string) $entryId, 'form_id' => (string) $form['id'], 'site' => home_url()],
        'read' => $read,
        'write' => $write,
      ]);
    }
    else {
      $metadata = Charger::gateway()->metadata($invoice, $description, $payer, ['orderid' => $row['orderid']]);
      $result = Charger::withColumns(
        Charger::run($mode, $read, $write, $row['orderid'], $total, static fn($client) => $client->saleWithPaymentKey($key, $total, $metadata, $recurring), \Usaepay\GatewayClient::TYPES_CHARGE, $row['account']),
        $mode,
        $row['account']
      );
    }
    $processor = $gateway === Processor::STRIPE ? 'Stripe' : 'USAePay';

    if ($result['outcome'] === 'action') {
      // 3D Secure: the browser shows the bank's check and submits again;
      // the PaymentIntent on the row is read back then.
      Payments::update($row['id'], ['transaction_key' => $result['columns']['transaction_key'], 'marker_json' => NULL, 'gateway_message' => 'waiting for 3D Secure']);
      return ['unresolved' => TRUE, 'action' => ['type' => 'stripe_3ds', 'client_secret' => $result['client_secret']]] + self::fail(402, $result['payer']);
    }
    if ($result['outcome'] === 'declined' || $result['outcome'] === 'failed') {
      Payments::update($row['id'], ['status' => $result['outcome'], 'gateway_message' => $result['gateway'], 'marker_json' => NULL] + array_filter(['transaction_key' => $result['columns']['transaction_key']]));
      Notes::add($entryId, sprintf(__('Payment of %1$s %2$s: %3$s', 'embed-forms'), Money::format($total), $result['outcome'] === 'declined' ? __('declined', 'embed-forms') : __('failed', 'embed-forms'), $result['gateway']));
      error_log('[embed-forms] payment ' . $result['outcome'] . ' for entry ' . $entryId . ': ' . $result['gateway']);
      return self::fail($result['outcome'] === 'declined' ? 402 : 502, $result['payer'], $result['outcome'] === 'declined');
    }
    if ($result['outcome'] === 'unresolved') {
      Payments::update($row['id'], ['gateway_message' => $result['gateway']]);
      Notes::add($entryId, sprintf(__('Payment of %1$s unresolved (%2$s). It will be looked up at %3$s before anything is charged again.', 'embed-forms'), Money::format($total), $result['gateway'], $processor));
      return ['unresolved' => TRUE] + self::fail(409, $result['payer']);
    }

    $paidAmount = Money::normalize($result['amount'] ?? $total) ?? $total;
    $columns = $result['columns'];
    $cardReference = $result['card_reference'];

    if ($recurring && ($cardReference === '' || ($gateway === Processor::STRIPE && $result['customer_reference'] === ''))) {
      // Approved but no saved card (or recovered from the listing, which
      // never carries one): there is nothing to renew from, so give the
      // money back and ask for the card again.
      $voided = self::undo($row, $columns['transaction_key']);
      Payments::update($row['id'], $columns + ['status' => $voided ? 'voided' : 'approved', 'marker_json' => NULL, 'gateway_message' => 'no saved card returned']);
      Notes::add($entryId, $voided
        ? sprintf(__('The first payment was approved but %s did not save the card, so it was voided. The payer was asked to try again.', 'embed-forms'), $processor)
        : sprintf(__('The first payment was approved but %1$s did not save the card, and voiding it failed. Void or refund it in the %1$s dashboard.', 'embed-forms'), $processor));
      return self::fail(402, __('Your card could not be saved for future payments, so nothing was charged. Please try again or use a different card.', 'embed-forms'), TRUE);
    }

    Payments::update($row['id'], $columns + ['status' => 'approved', 'amount' => $paidAmount, 'marker_json' => NULL, 'gateway_message' => '']);
    $note = sprintf(__('Paid %1$s: %2$s', 'embed-forms'), Money::format($paidAmount), $result['note']);
    if ($result['reconciled']) {
      $note .= ' ' . __('(an earlier attempt had gone through; recorded without charging again)', 'embed-forms');
    }
    Notes::add($entryId, $note);

    $subscription = NULL;
    if ($recurring) {
      $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
      $next = Schedule::installmentDate($now, 1, $pricing['frequency'], 1);
      $done = $pricing['recurring_times'] === 1;
      $subscriptionId = Subscriptions::create([
        'entry_id' => $entryId,
        'form_id' => (int) $form['id'],
        'status' => $done ? 'completed' : 'active',
        'mode' => $mode,
        'gateway' => $row['gateway'],
        'account' => $row['account'],
        'customer_reference' => $result['customer_reference'],
        'card_reference' => $cardReference,
        'card_brand' => $columns['card_brand'],
        'card_last4' => $columns['card_last4'],
        'amount' => $paidAmount,
        'interval_length' => 1,
        'interval_unit' => $pricing['frequency'],
        'recurring_times' => $pricing['recurring_times'],
        'payments_made' => 1,
        'schedule_start' => $now->format('Y-m-d H:i:s'),
        'installment_index' => 1,
        'next_charge' => $done ? NULL : $next->format('Y-m-d H:i:s'),
      ]);
      Payments::update($row['id'], ['subscription_id' => $subscriptionId]);
      $subscription = Subscriptions::find($subscriptionId);
      Notes::add($entryId, sprintf(
        __('Recurring payment set up: %1$s %2$s%3$s. Charged by this site; nothing is scheduled at %4$s.', 'embed-forms'),
        Money::format($paidAmount),
        strtolower(Fields::frequencyLabel($pricing['frequency'])),
        $pricing['recurring_times'] > 0 ? ' ' . sprintf(_n('(%d payment)', '(%d payments)', $pricing['recurring_times'], 'embed-forms'), $pricing['recurring_times']) : '',
        $processor
      ), $subscriptionId);
      do_action('embed_forms_subscription_created', $subscription, $entry, $form);
    }

    Entries::update($entryId, ['status' => 'paid', 'amount' => $paidAmount]);
    $payment = Payments::find($row['id']);
    do_action('embed_forms_payment_completed', $payment, $entry, $form);

    return [
      'ok' => TRUE,
      'status' => 200,
      'message' => '',
      'unresolved' => FALSE,
      'declined' => FALSE,
      'action' => NULL,
      'payment' => $payment,
      'tags' => self::tags($payment, $pricing, $subscription),
    ];
  }

  private static function sameAccount(string $a, string $b): bool {
    return $a === $b || (Processor::isDefaultAccount($a) && Processor::isDefaultAccount($b));
  }

  /**
   * Give back an approved first payment in full (void at USAePay, refund at
   * Stripe).
   */
  private static function undo(array $row, string $reference): bool {
    try {
      if ($row['gateway'] === Processor::STRIPE) {
        $client = Stripe\Gateway::client($row['account'], $row['mode']);
        $refund = $client->request('POST', 'refunds', ['payment_intent' => $reference], $row['orderid'] . '-undo');
        return !in_array((string) ($refund['status'] ?? ''), ['failed', 'canceled'], TRUE);
      }
      $void = Charger::client($row['mode'], $row['account'])->void($reference);
      return Gateway::approved($void);
    }
    catch (\Throwable $e) {
      error_log('[embed-forms] void after missing saved card failed: ' . $e->getMessage());
      return FALSE;
    }
  }

  /**
   * Merge tags for the emails and confirmation of a paid entry.
   */
  public static function tags(?array $payment, array $pricing, ?array $subscription): array {
    $frequency = $pricing['frequency'] ?? 'once';
    return [
      'payment_amount' => $payment ? Money::format((string) $payment['amount']) : '',
      'payment_frequency' => Fields::frequencyLabel($frequency),
      'transaction_id' => $payment ? (string) $payment['transaction_key'] : '',
      'card_brand' => $payment ? (string) $payment['card_brand'] : '',
      'card_last4' => $payment ? (string) $payment['card_last4'] : '',
      'next_payment_date' => $subscription && $subscription['next_charge'] ? wp_date(get_option('date_format'), strtotime($subscription['next_charge'] . ' UTC')) : '',
    ];
  }

  /**
   * An HTML table of what was paid, for {payment_summary}.
   */
  public static function summaryHtml(array $pricing, ?array $payment, ?array $subscription): string {
    if (!$payment) {
      return '';
    }
    $e = static fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
    $rows = '';
    foreach ($pricing['lines'] as $line) {
      $rows .= '<tr><td style="padding:6px 8px;border-bottom:1px solid #e5e5e5">' . $e($line['label']) . '</td><td style="padding:6px 8px;border-bottom:1px solid #e5e5e5;text-align:right">' . $e(Money::format($line['amount'])) . '</td></tr>';
    }
    $total = Money::format((string) $payment['amount']);
    if (($pricing['frequency'] ?? 'once') !== 'once') {
      $total .= ' ' . $e(strtolower(Fields::frequencyLabel($pricing['frequency'])));
    }
    $rows .= '<tr><td style="padding:6px 8px;font-weight:700">' . $e(__('Total', 'embed-forms')) . '</td><td style="padding:6px 8px;text-align:right;font-weight:700">' . $total . '</td></tr>';
    $card = trim($payment['card_brand'] . ' ' . ($payment['card_last4'] ? '•••• ' . $payment['card_last4'] : ''));
    $meta = [];
    if ($card !== '') {
      $meta[] = $e($card);
    }
    if ($payment['transaction_key'] !== '') {
      $meta[] = $e(sprintf(__('Reference %s', 'embed-forms'), $payment['transaction_key']));
    }
    if ($subscription && $subscription['next_charge']) {
      $meta[] = $e(sprintf(__('Next payment: %s', 'embed-forms'), wp_date(get_option('date_format'), strtotime($subscription['next_charge'] . ' UTC'))));
    }
    return '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;width:100%;max-width:480px;font-family:sans-serif;font-size:14px">' . $rows . '</table>'
      . ($meta ? '<p style="color:#50575e;font-size:13px">' . implode(' · ', $meta) . '</p>' : '');
  }

  private static function fail(int $status, string $message, bool $declined = FALSE): array {
    return ['ok' => FALSE, 'status' => $status, 'message' => $message, 'unresolved' => FALSE, 'declined' => $declined, 'action' => NULL, 'tags' => [], 'payment' => NULL];
  }

}
