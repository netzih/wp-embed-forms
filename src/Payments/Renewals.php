<?php

namespace EmbedForms\Payments;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Notifications\Mailer;
use EmbedForms\Schema\Fields;

/**
 * The hourly worker that charges due subscriptions against their saved
 * card, at the processor and account each subscription was made with (in
 * that processor's current live/sandbox mode). Installment dates stay anchored to the signup day; a declined
 * installment is retried every RETRY_DAYS, MAX_ATTEMPTS attempts in all,
 * then the subscription is cancelled.
 *
 * Nothing is charged twice: each attempt's orderid is stored on the
 * subscription before the request (Reconcile), and an approved charge is
 * recorded by its transaction key first, so a run that died half-way is
 * finished by the next one instead of charging the period again.
 */
final class Renewals {

  public const CRON = 'embed_forms_renewals';

  private const LOCK_TTL = 15 * 60;

  /**
   * @return array<string, int>
   */
  public function run(?\DateTimeImmutable $now = NULL): array {
    $summary = ['due' => 0, 'charged' => 0, 'recovered' => 0, 'declined' => 0, 'cancelled' => 0, 'completed' => 0, 'unresolved' => 0, 'skipped' => 0];
    $now = $now ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    $due = [];
    if (Processor::usaepayActive()) {
      $due = array_merge($due, Subscriptions::due(Processor::USAEPAY, Processor::mode(Processor::USAEPAY), $now->format('Y-m-d H:i:s')));
    }
    $due = array_merge($due, Subscriptions::due(Processor::STRIPE, Processor::mode(Processor::STRIPE), $now->format('Y-m-d H:i:s')));
    if (!$due) {
      return $summary;
    }
    $runLock = Lock::acquire('ef_renewals', self::LOCK_TTL);
    if ($runLock === NULL) {
      $summary['skipped']++;
      return $summary;
    }
    try {
      foreach ($due as $subscription) {
        $summary['due']++;
        $lock = Lock::acquire('ef_sub_' . $subscription['id'], self::LOCK_TTL);
        if ($lock === NULL) {
          $summary['skipped']++;
          continue;
        }
        try {
          $outcome = $this->charge((int) $subscription['id'], $now);
          $summary[$outcome] = ($summary[$outcome] ?? 0) + 1;
        }
        catch (\Throwable $e) {
          error_log('[embed-forms] renewal of subscription ' . $subscription['id'] . ' failed: ' . $e->getMessage());
          $summary['unresolved']++;
        }
        finally {
          Lock::release('ef_sub_' . $subscription['id'], $lock);
        }
      }
    }
    finally {
      Lock::release('ef_renewals', $runLock);
    }
    return $summary;
  }

  /**
   * Charge one due subscription.
   *
   * @return string
   *   charged, recovered, declined, cancelled, completed, unresolved or skipped.
   */
  public function charge(int $subscriptionId, \DateTimeImmutable $now): string {
    // Read again under the lock: another run may have just charged it.
    $sub = Subscriptions::find($subscriptionId);
    if (!$sub || !in_array($sub['status'], ['active', 'failing'], TRUE) || !$sub['next_charge'] || Schedule::utc($sub['next_charge']) > $now) {
      return 'skipped';
    }
    $entry = Entries::find($sub['entry_id']);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if (!$entry || !$form) {
      Subscriptions::update($subscriptionId, ['last_error' => 'entry or form missing']);
      return 'skipped';
    }

    $start = Schedule::utc($sub['schedule_start']);
    $scheduled = Schedule::installmentDate($start, $sub['interval_length'], $sub['interval_unit'], $sub['installment_index']);
    $attempt = $sub['failed_attempts'];
    $orderId = Processor::orderId(Schedule::orderId($subscriptionId, $scheduled, $attempt));
    $amount = Money::normalize((string) $sub['amount']) ?? '0.00';
    $payer = Payer::fromValues(Forms::schemaAt($form, $entry['form_version']), $entry['data']);
    if (empty($payer['email']) && $entry['payer_email'] !== '') {
      $payer['email'] = $entry['payer_email'];
    }
    $description = $form['title'] . ' (' . Fields::frequencyLabel($sub['interval_unit']) . ')';
    [$read, $write] = Subscriptions::markerStore($subscriptionId);
    $card = (string) $sub['card_reference'];

    if ($sub['gateway'] === Processor::STRIPE) {
      $result = Stripe\Gateway::renewal($sub, $orderId, $amount, ['invoice' => 'EF-S' . $subscriptionId, 'entry_id' => (string) $entry['id'], 'subscription_id' => (string) $subscriptionId, 'site' => home_url()], $description, $read, $write);
    }
    else {
      $metadata = Charger::gateway()->metadata('EF-S' . $subscriptionId, $description, $payer, ['orderid' => $orderId]);
      $result = Charger::withColumns(
        Charger::run($sub['mode'], $read, $write, $orderId, $amount, static fn($client) => $client->saleWithCardReference($card, $amount, $metadata), \Usaepay\GatewayClient::TYPES_CHARGE, $sub['account']),
        $sub['mode'],
        $sub['account']
      );
    }

    if ($result['outcome'] === 'unresolved' || $result['outcome'] === 'failed') {
      // Nothing is counted against the payer: either the request provably
      // did not happen, or the marker makes the next run look it up first.
      Subscriptions::update($subscriptionId, ['last_error' => $result['gateway']]);
      error_log('[embed-forms] renewal ' . $orderId . ' ' . $result['outcome'] . ': ' . $result['gateway']);
      return 'unresolved';
    }

    if ($result['outcome'] === 'declined') {
      return $this->declined($sub, $entry, $form, $scheduled, $amount, $result, $now);
    }

    // Approved. Record the payment first (by transaction key, so a second
    // pass over the same charge records nothing new), then move the schedule.
    $columns = $result['columns'];
    $paidAmount = Money::normalize($result['amount'] ?? $amount) ?? $amount;
    $existing = Payments::findByTransaction($columns['transaction_key']);
    if (!$existing) {
      $paymentId = Payments::create($columns + [
        'entry_id' => $entry['id'],
        'subscription_id' => $subscriptionId,
        'kind' => 'renewal',
        'status' => 'approved',
        'amount' => $paidAmount,
        'mode' => $sub['mode'],
        'gateway' => $sub['gateway'],
        'account' => $sub['account'],
        'method' => 'card',
        'orderid' => $orderId,
      ]);
      Notes::add($entry['id'], sprintf(__('Renewal for %1$s paid %2$s: %3$s', 'embed-forms'), $scheduled->format('Y-m-d'), Money::format($paidAmount), $result['note']) . ($result['reconciled'] ? ' ' . __('(recovered: the charge had gone through on an earlier run)', 'embed-forms') : ''), $subscriptionId);
    }
    else {
      $paymentId = $existing['id'];
    }

    $made = $sub['payments_made'] + 1;
    $index = $sub['installment_index'] + 1;
    $next = Schedule::installmentDate($start, $sub['interval_length'], $sub['interval_unit'], $index);
    if ($next <= $now) {
      // The worker fell behind (cron stopped for a while): charge at most
      // one installment per run and skip to the next date still ahead.
      [$index, $next] = Schedule::nextInstallmentAfter($start, $now, $sub['interval_length'], $sub['interval_unit'], $index);
    }
    $completed = $sub['recurring_times'] > 0 && $made >= $sub['recurring_times'];
    Subscriptions::update($subscriptionId, [
      'status' => $completed ? 'completed' : 'active',
      'payments_made' => $made,
      'failed_attempts' => 0,
      'installment_index' => $index,
      'next_charge' => $completed ? NULL : $next->format('Y-m-d H:i:s'),
      'last_error' => NULL,
      'marker_json' => NULL,
    ]);
    if ($completed) {
      Notes::add($entry['id'], sprintf(__('All %d payments made; the recurring payment is complete.', 'embed-forms'), $made), $subscriptionId);
    }

    $payment = Payments::find($paymentId);
    $fresh = Subscriptions::find($subscriptionId);
    do_action('embed_forms_renewal_charged', $payment, $fresh, $entry, $form);
    if (!$existing && !empty($form['settings']['renewal_receipts'])) {
      self::receipt($form, $entry, $payment, $fresh);
    }
    if ($completed) {
      return 'completed';
    }
    return $result['reconciled'] ? 'recovered' : 'charged';
  }

  private function declined(array $sub, array $entry, array $form, \DateTimeImmutable $scheduled, string $amount, array $result, \DateTimeImmutable $now): string {
    $attempt = $sub['failed_attempts'] + 1;
    Payments::create([
      'entry_id' => $entry['id'],
      'subscription_id' => $sub['id'],
      'kind' => 'renewal',
      'status' => 'declined',
      'amount' => $amount,
      'mode' => $sub['mode'],
      'gateway' => $sub['gateway'],
      'account' => $sub['account'],
      'method' => 'card',
      'gateway_message' => $result['gateway'],
    ]);
    if ($attempt >= Schedule::MAX_ATTEMPTS) {
      Subscriptions::update($sub['id'], ['failed_attempts' => $attempt, 'last_error' => $result['gateway'], 'marker_json' => NULL]);
      Refunds::cancelSubscription($sub['id'], sprintf(__('Cancelled after %1$d declined attempts (%2$s).', 'embed-forms'), $attempt, $result['gateway']));
      self::alert($form, $entry, $sub, sprintf(__('The recurring payment of %1$s for entry #%2$d was declined %3$d times and has been cancelled. Last reason: %4$s', 'embed-forms'), Money::format($amount), $entry['id'], $attempt, $result['gateway']));
      return 'cancelled';
    }
    $retry = $now->modify('+' . Schedule::RETRY_DAYS . ' days');
    Subscriptions::update($sub['id'], [
      'status' => 'failing',
      'failed_attempts' => $attempt,
      'next_charge' => $retry->format('Y-m-d H:i:s'),
      'last_error' => $result['gateway'],
      'marker_json' => NULL,
    ]);
    Notes::add($entry['id'], sprintf(__('Renewal for %1$s declined (attempt %2$d of %3$d): %4$s. Next attempt %5$s UTC.', 'embed-forms'), $scheduled->format('Y-m-d'), $attempt, Schedule::MAX_ATTEMPTS, $result['gateway'], $retry->format('Y-m-d H:i')), $sub['id']);
    return 'declined';
  }

  private static function receipt(array $form, array $entry, array $payment, array $sub): void {
    $email = (string) $entry['payer_email'];
    if (!is_email($email)) {
      return;
    }
    $e = static fn(string $s) => esc_html($s);
    $site = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
    $lines = [
      sprintf(__('Thank you. Your %1$s payment of %2$s for %3$s was charged today.', 'embed-forms'), strtolower(Fields::frequencyLabel($sub['interval_unit'])), Money::format((string) $payment['amount']), $form['title']),
    ];
    if ($payment['card_last4'] !== '') {
      $lines[] = sprintf(__('Card: %1$s ending in %2$s', 'embed-forms'), $payment['card_brand'] ?: __('card', 'embed-forms'), $payment['card_last4']);
    }
    $lines[] = sprintf(__('Reference: %s', 'embed-forms'), $payment['transaction_key']);
    if ($sub['next_charge']) {
      $lines[] = sprintf(__('Next payment: %s', 'embed-forms'), wp_date(get_option('date_format'), strtotime($sub['next_charge'] . ' UTC')));
    }
    $lines[] = __('To change or cancel this recurring payment, reply to this email.', 'embed-forms');
    $replyTo = (string) ($form['settings']['from_email'] ?? '') ?: (string) get_option('admin_email');
    Mailer::send([$email], sprintf(__('Your payment to %s', 'embed-forms'), $site), '<p>' . implode('</p><p>', array_map($e, $lines)) . '</p>', $replyTo, $form['settings']);
  }

  /**
   * Tell the form's notification recipients about a problem.
   */
  private static function alert(array $form, array $entry, array $sub, string $message): void {
    $context = Mailer::context($form, $entry);
    $to = [];
    foreach ($form['settings']['notifications'] as $notification) {
      if (!empty($notification['enabled'])) {
        foreach (preg_split('/[\s,;]+/', \EmbedForms\Notifications\MergeTags::replace($notification['to'], $context, FALSE)) ?: [] as $address) {
          if (is_email($address)) {
            $to[] = $address;
          }
        }
      }
    }
    if (!$to) {
      $to[] = (string) get_option('admin_email');
    }
    $url = admin_url('admin.php?page=embed-forms-entries&entry=' . (int) $entry['id']);
    Mailer::send(array_unique($to), sprintf(__('Recurring payment cancelled: %s', 'embed-forms'), $form['title']), '<p>' . esc_html($message) . '</p><p><a href="' . esc_url($url) . '">' . esc_html__('View the entry', 'embed-forms') . '</a></p>', '', $form['settings']);
  }

}
