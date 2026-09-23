<?php

namespace EmbedForms\Payments;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Db\Subscriptions;
use Usaepay\GatewayClient;
use Usaepay\WordPress\Gateway;

/**
 * Refunds from the entry screen and subscription cancellation. An unsettled
 * sale is voided (in full); a settled one is refunded in full or in part.
 * Refunds inherit the sale's orderid at USAePay, so a marker on the sale's
 * row makes sure one whose answer was lost is found instead of repeated.
 */
final class Refunds {

  /**
   * @return array{ok: bool, message: string}
   */
  public static function refund(int $paymentId, ?string $amount = NULL): array {
    $payment = Payments::find($paymentId);
    if (!$payment || !in_array($payment['kind'], ['charge', 'renewal'], TRUE) || !in_array($payment['status'], ['approved', 'partially_refunded'], TRUE)) {
      return ['ok' => FALSE, 'message' => __('That payment cannot be refunded.', 'embed-forms')];
    }
    $paid = Money::toCents((string) $payment['amount']) ?? 0;
    $already = Money::toCents((string) $payment['refunded_amount']) ?? 0;
    $left = $paid - $already;
    $cents = $amount === NULL || trim($amount) === '' ? $left : Money::toCents($amount);
    if ($cents === NULL || $cents <= 0 || $cents > $left) {
      return ['ok' => FALSE, 'message' => sprintf(__('Enter an amount up to %s.', 'embed-forms'), Money::format($left))];
    }
    $reference = (string) $payment['transaction_key'];
    $mode = (string) ($payment['mode'] ?: Charger::mode());
    try {
      $client = Charger::client($mode);
      $transaction = $client->getTransaction($reference);
    }
    catch (\Throwable $e) {
      return ['ok' => FALSE, 'message' => sprintf(__('USAePay could not be asked about the payment: %s', 'embed-forms'), $e->getMessage())];
    }
    $status = (string) ($transaction['status_code'] ?? '');
    $unsettled = $status === 'P' || $status === 'A';
    if ($unsettled && ($cents !== $paid || $already > 0)) {
      return ['ok' => FALSE, 'message' => __('This payment has not settled yet, so it can only be voided in full. Refund the full amount, or wait until it settles (usually the next day) for a partial refund.', 'embed-forms')];
    }
    $refundAmount = Money::fromCents($cents);
    [$read, $write] = Payments::markerStore($paymentId);
    $saleOrderId = trim((string) ($transaction['orderid'] ?? $payment['orderid']));
    if ($unsettled) {
      $call = static fn(GatewayClient $c) => $c->void($reference);
      $types = GatewayClient::TYPES_CHARGE;
    }
    else {
      $call = static fn(GatewayClient $c) => $c->refund($reference, $refundAmount);
      $types = GatewayClient::TYPES_REFUND;
    }
    if ($unsettled || $saleOrderId === '') {
      // A void answers at once and cannot happen twice.
      try {
        $response = $call($client);
        $result = Gateway::approved($response)
          ? ['outcome' => 'approved', 'response' => $response, 'reconciled' => FALSE]
          : ['outcome' => 'declined', 'response' => $response, 'gateway' => Gateway::failure($response)['gateway']];
      }
      catch (\Throwable $e) {
        $result = ['outcome' => 'failed', 'gateway' => $e->getMessage()];
      }
    }
    else {
      $result = Charger::run($mode, $read, $write, $saleOrderId, $refundAmount, $call, $types);
    }

    if ($result['outcome'] !== 'approved') {
      $why = (string) ($result['gateway'] ?? '');
      Notes::add($payment['entry_id'], sprintf(__('Refund of %1$s failed: %2$s', 'embed-forms'), Money::format($refundAmount), $why));
      return ['ok' => FALSE, 'message' => $result['outcome'] === 'unresolved'
        ? sprintf(__('USAePay could not confirm whether the refund went through (%s). Try again later: it will be looked up first, never sent twice.', 'embed-forms'), $why)
        : sprintf(__('USAePay refused the refund: %s', 'embed-forms'), $why)];
    }
    $response = $result['response'];
    $write(NULL);
    $refundKey = Gateway::transactionReference($response);
    Payments::create([
      'entry_id' => $payment['entry_id'],
      'subscription_id' => $payment['subscription_id'],
      'parent_id' => $paymentId,
      'kind' => 'refund',
      'status' => $unsettled ? 'voided' : 'approved',
      'amount' => $refundAmount,
      'mode' => $mode,
      'method' => $payment['method'],
      'orderid' => $saleOrderId,
      'transaction_key' => $refundKey,
      'refnum' => (string) ($response['refnum'] ?? ''),
    ]);
    $newRefunded = $already + $cents;
    Payments::update($paymentId, [
      'refunded_amount' => Money::fromCents($newRefunded),
      'status' => $unsettled ? 'voided' : ($newRefunded >= $paid ? 'refunded' : 'partially_refunded'),
    ]);
    Notes::add($payment['entry_id'], sprintf(
      $unsettled ? __('Voided %1$s before settlement (USAePay %2$s).', 'embed-forms') : __('Refunded %1$s (USAePay %2$s).', 'embed-forms'),
      Money::format($refundAmount),
      $refundKey ?: $reference
    ) . (!empty($result['reconciled']) ? ' ' . __('An earlier refund request had gone through; recorded without refunding again.', 'embed-forms') : ''));

    // The entry is refunded once none of its money is left.
    $open = array_filter(Payments::forEntry($payment['entry_id']), static fn($p) => in_array($p['kind'], ['charge', 'renewal'], TRUE) && in_array($p['status'], ['approved', 'partially_refunded'], TRUE));
    if (!$open) {
      Entries::setStatus($payment['entry_id'], 'refunded');
    }
    do_action('embed_forms_payment_refunded', Payments::find($paymentId), $refundAmount);
    return ['ok' => TRUE, 'message' => sprintf($unsettled ? __('%s voided: the payment had not settled, so nothing reaches the card.', 'embed-forms') : __('%s refunded.', 'embed-forms'), Money::format($refundAmount))];
  }

  public static function cancelSubscription(int $subscriptionId, string $why = ''): bool {
    $subscription = Subscriptions::find($subscriptionId);
    if (!$subscription || !in_array($subscription['status'], ['active', 'failing'], TRUE)) {
      return FALSE;
    }
    Subscriptions::update($subscriptionId, ['status' => 'cancelled', 'next_charge' => NULL, 'cancelled_at' => gmdate('Y-m-d H:i:s')]);
    Notes::add($subscription['entry_id'], trim(__('Recurring payment cancelled. No further charges will be made.', 'embed-forms') . ' ' . $why), $subscriptionId);
    do_action('embed_forms_subscription_cancelled', Subscriptions::find($subscriptionId));
    return TRUE;
  }

}
