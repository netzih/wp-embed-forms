<?php

namespace EmbedForms\Orders;

use EmbedForms\Admin\OfflineDonations;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Notifications\Mailer;
use EmbedForms\Payments\Checkout;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Pricing;
use EmbedForms\Payments\Processor;
use EmbedForms\Payments\Refunds;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;

/**
 * What a store manager (or an administrator) does to an entry. Every
 * change is written to the entry's history with the person's name. Card
 * refunds go through Payments\Refunds, the same as the entry screen; money
 * returned by cash or check is only recorded.
 *
 * Each method returns ['ok' => bool, 'message' => string].
 */
final class Service {

  public static function who(): string {
    return OfflineDonations::userName();
  }

  /**
   * The entry's money and payment status.
   *
   * @return array{received: int, refunded: int, net: int, due: int, credit: int, state: string, payments: array[]}
   */
  public static function summary(array $entry): array {
    $payments = Payments::forEntry((int) $entry['id']);
    $money = Status::money($entry['amount'], $payments, ($entry['order_status'] ?? '') === 'cancelled');
    return $money + ['state' => Status::payment($entry, $money), 'payments' => $payments];
  }

  private static function changed(array $form, int $entryId): void {
    do_action('embed_forms_entry_changed', (int) $form['id']);
    /**
     * An order's status, quantities or payments changed on the store
     * manager page or the entry screen.
     */
    do_action('embed_forms_order_changed', Entries::find($entryId), $form);
  }

  // ------------------------------------------------------------- order

  public static function setOrder(array $entry, array $form, string $status, ?string $tracking = NULL, bool $notify = FALSE): array {
    if (!array_key_exists($status, Status::ORDER)) {
      return ['ok' => FALSE, 'message' => __('Choose an order status.', 'embed-forms')];
    }
    $row = [];
    $parts = [];
    $before = (string) ($entry['order_status'] ?? '');
    if ($status !== $before) {
      $row['order_status'] = $status;
      $parts[] = sprintf(__('order %1$s → %2$s', 'embed-forms'), Status::orderLabel($before), Status::orderLabel($status));
    }
    if ($tracking !== NULL) {
      $tracking = Tracking::clean($tracking);
      if ($tracking !== (string) ($entry['tracking'] ?? '')) {
        $row['tracking'] = $tracking;
        $parts[] = $tracking === '' ? __('tracking number removed', 'embed-forms') : sprintf(__('tracking %s', 'embed-forms'), $tracking);
      }
    }
    if ($row) {
      Entries::update((int) $entry['id'], $row);
      Notes::add((int) $entry['id'], sprintf(__('%1$s changed the order: %2$s.', 'embed-forms'), self::who(), implode(', ', $parts)));
      $entry = Entries::find((int) $entry['id']);
      self::changed($form, (int) $entry['id']);
    }
    $message = $row ? __('Order saved.', 'embed-forms') : __('Nothing changed.', 'embed-forms');
    if ($notify && $status === 'shipped') {
      $sent = self::sendShipped($entry, $form);
      $message .= ' ' . $sent['message'];
    }
    return ['ok' => TRUE, 'message' => trim($message)];
  }

  public static function sendShipped(array $entry, array $form): array {
    $email = (string) $entry['payer_email'];
    if (!is_email($email)) {
      return ['ok' => FALSE, 'message' => __('No shipped email: the order has no email address.', 'embed-forms')];
    }
    $settings = $form['settings']['orders'];
    $link = Tracking::link((string) $entry['tracking']);
    $extra = [
      'tracking_number' => (string) $entry['tracking'],
      'tracking_carrier' => $link['carrier'],
      'tracking_url' => $link['url'],
    ];
    $html = ['tracking_link' => $link['url'] !== '' ? '<a href="' . esc_url($link['url']) . '">' . esc_html__('Track your package', 'embed-forms') . '</a>' : ''];
    $ok = Mailer::message($form, $entry, [$email], $settings['shipped_subject'], $settings['shipped_body'], $extra, $html);
    if ($ok) {
      Notes::add((int) $entry['id'], sprintf(__('Shipped email sent to %1$s by %2$s.', 'embed-forms'), $email, self::who()));
    }
    return ['ok' => $ok, 'message' => $ok ? sprintf(__('Shipped email sent to %s.', 'embed-forms'), $email) : __('The shipped email could not be sent.', 'embed-forms')];
  }

  public static function cancel(array $entry, array $form, bool $refund): array {
    $messages = [];
    if (($entry['order_status'] ?? '') !== 'cancelled') {
      Entries::update((int) $entry['id'], ['order_status' => 'cancelled']);
      Notes::add((int) $entry['id'], sprintf(__('%s cancelled the order.', 'embed-forms'), self::who()));
      $messages[] = __('Order cancelled.', 'embed-forms');
    }
    $ok = TRUE;
    if ($refund) {
      foreach (Payments::forEntry((int) $entry['id']) as $payment) {
        $left = self::left($payment);
        if ($left <= 0) {
          continue;
        }
        $result = self::refund($entry, (int) $payment['id'], Money::fromCents($left));
        $ok = $ok && $result['ok'];
        $messages[] = $result['message'];
      }
    }
    self::changed($form, (int) $entry['id']);
    return ['ok' => $ok, 'message' => implode(' ', $messages) ?: __('Nothing changed.', 'embed-forms')];
  }

  public static function reopen(array $entry, array $form): array {
    if (($entry['order_status'] ?? '') !== 'cancelled') {
      return ['ok' => TRUE, 'message' => __('Nothing changed.', 'embed-forms')];
    }
    Entries::update((int) $entry['id'], ['order_status' => '']);
    Notes::add((int) $entry['id'], sprintf(__('%s reopened the order.', 'embed-forms'), self::who()));
    self::changed($form, (int) $entry['id']);
    return ['ok' => TRUE, 'message' => __('Order reopened.', 'embed-forms')];
  }

  /**
   * New quantities: the total is worked out again (following products
   * too); the card is never charged again. What was overpaid shows as a
   * refund due, what is missing as a balance due.
   *
   * @param array<string, mixed> $quantities
   */
  public static function changeQuantities(array $entry, array $form, array $quantities): array {
    if (Subscriptions::forEntry((int) $entry['id'])) {
      return ['ok' => FALSE, 'message' => __('Quantities of a recurring payment cannot be changed.', 'embed-forms')];
    }
    $schema = Forms::schemaAt($form, (int) $entry['form_version']);
    $result = Status::requantify($schema, $entry['data'], $quantities);
    if (!$result['ok']) {
      return $result;
    }
    if (!$result['changes']) {
      return ['ok' => TRUE, 'message' => __('Nothing changed.', 'embed-forms')];
    }
    $products = Status::products($schema);
    $parts = [];
    foreach ($result['changes'] as $id => [$before, $after]) {
      $parts[] = sprintf('%1$s %2$d → %3$d', $products[$id]['label'], $before, $after);
    }
    $oldTotal = (string) ($entry['amount'] ?? '0.00');
    Entries::update((int) $entry['id'], ['data' => $result['values'], 'amount' => $result['total']]);
    Notes::add((int) $entry['id'], sprintf(__('%1$s changed quantities: %2$s. Total %3$s → %4$s.', 'embed-forms'), self::who(), implode(', ', $parts), Money::format($oldTotal), Money::format($result['total'])));
    $entry = Entries::find((int) $entry['id']);
    self::changed($form, (int) $entry['id']);
    $summary = self::summary($entry);
    $message = sprintf(__('Quantities saved. New total %s.', 'embed-forms'), Money::format($result['total']));
    if ($summary['credit'] > 0) {
      $message .= ' ' . sprintf(__('%s was paid beyond it: refund it below.', 'embed-forms'), Money::format($summary['credit']));
    }
    elseif ($summary['due'] > 0) {
      $message .= ' ' . sprintf(__('%s is still to be paid.', 'embed-forms'), Money::format($summary['due']));
    }
    return ['ok' => TRUE, 'message' => $message];
  }

  // ------------------------------------------------------------- money

  /**
   * What of a payment can still go back, in cents.
   */
  public static function left(array $payment): int {
    if (!in_array($payment['kind'], ['charge', 'renewal'], TRUE) || !in_array($payment['status'], ['approved', 'partially_refunded'], TRUE)) {
      return 0;
    }
    return max(0, (Money::toCents((string) $payment['amount']) ?? 0) - (Money::toCents((string) $payment['refunded_amount']) ?? 0));
  }

  /**
   * A refund of one of the entry's payments: at the processor for a card,
   * recorded only for cash, check and the like.
   */
  public static function refund(array $entry, int $paymentId, string $amount): array {
    $payment = Payments::find($paymentId);
    if (!$payment || (int) $payment['entry_id'] !== (int) $entry['id']) {
      return ['ok' => FALSE, 'message' => __('That payment is not on this order.', 'embed-forms')];
    }
    if ($payment['gateway'] !== Processor::OFFLINE) {
      $result = Refunds::refund($paymentId, $amount);
      if ($result['ok']) {
        Notes::add((int) $entry['id'], sprintf(__('Refund of %1$s given by %2$s.', 'embed-forms'), Money::format(Money::normalize($amount) ?? $amount), self::who()));
      }
      return $result;
    }
    $left = self::left($payment);
    $cents = Money::toCents($amount);
    if ($cents === NULL || $cents <= 0 || $cents > $left) {
      return ['ok' => FALSE, 'message' => sprintf(__('Enter an amount up to %s.', 'embed-forms'), Money::format($left))];
    }
    Payments::create([
      'entry_id' => (int) $entry['id'],
      'parent_id' => $paymentId,
      'kind' => 'refund',
      'status' => 'approved',
      'amount' => Money::fromCents($cents),
      'gateway' => Processor::OFFLINE,
      'method' => $payment['method'],
    ]);
    $refunded = (Money::toCents((string) $payment['refunded_amount']) ?? 0) + $cents;
    Payments::update($paymentId, [
      'refunded_amount' => Money::fromCents($refunded),
      'status' => $cents >= $left ? 'refunded' : 'partially_refunded',
    ]);
    Notes::add((int) $entry['id'], sprintf(__('%1$s recorded %2$s given back (%3$s).', 'embed-forms'), self::who(), Money::format(Money::fromCents($cents)), OfflineDonations::methodLabel((string) $payment['method'])));
    $open = array_filter(Payments::forEntry((int) $entry['id']), static fn($p) => self::left($p) > 0);
    if (!$open) {
      Entries::setStatus((int) $entry['id'], 'refunded');
    }
    return ['ok' => TRUE, 'message' => sprintf(__('%s recorded as given back.', 'embed-forms'), Money::format(Money::fromCents($cents)))];
  }

  /**
   * Money received by cash, check and the like, toward the order.
   */
  public static function recordPayment(array $entry, array $form, string $amount, string $method, string $reference): array {
    $cents = Money::toCents($amount);
    if ($cents === NULL || $cents <= 0) {
      return ['ok' => FALSE, 'message' => __('Enter the amount received.', 'embed-forms')];
    }
    $method = in_array($method, OfflineDonations::METHODS, TRUE) ? $method : 'other';
    Payments::create([
      'entry_id' => (int) $entry['id'],
      'kind' => 'charge',
      'status' => 'approved',
      'amount' => Money::fromCents($cents),
      'gateway' => Processor::OFFLINE,
      'method' => $method,
      'refnum' => mb_substr(trim($reference), 0, 64),
    ]);
    if (in_array($entry['status'], ['submitted', 'pending_payment', 'refunded'], TRUE)) {
      Entries::setStatus((int) $entry['id'], 'paid');
    }
    Notes::add((int) $entry['id'], sprintf(__('%1$s recorded a payment of %2$s (%3$s).', 'embed-forms'), self::who(), Money::format(Money::fromCents($cents)), trim(OfflineDonations::methodLabel($method) . ' ' . trim($reference))));
    self::changed($form, (int) $entry['id']);
    return ['ok' => TRUE, 'message' => sprintf(__('Payment of %s recorded.', 'embed-forms'), Money::format(Money::fromCents($cents)))];
  }

  public static function stopRecurring(array $entry, int $subscriptionId): array {
    $subscription = Subscriptions::find($subscriptionId);
    if (!$subscription || (int) $subscription['entry_id'] !== (int) $entry['id']) {
      return ['ok' => FALSE, 'message' => __('That recurring payment is not on this entry.', 'embed-forms')];
    }
    $done = Refunds::cancelSubscription($subscriptionId, sprintf(__('(by %s)', 'embed-forms'), self::who()));
    return ['ok' => $done, 'message' => $done ? __('Recurring payment stopped.', 'embed-forms') : __('That recurring payment is not active.', 'embed-forms')];
  }

  // ------------------------------------------------------------- notes and emails

  public static function note(array $entry, string $text): array {
    $text = trim(mb_substr($text, 0, 2000));
    if ($text === '') {
      return ['ok' => FALSE, 'message' => __('Write the note first.', 'embed-forms')];
    }
    Notes::add((int) $entry['id'], sprintf(__('%1$s: %2$s', 'embed-forms'), self::who(), $text), NULL, 'note');
    return ['ok' => TRUE, 'message' => __('Note added.', 'embed-forms')];
  }

  /**
   * The form's confirmation email again, with the payment details.
   */
  public static function resendReceipt(array $entry, array $form): array {
    if (empty($form['settings']['autoresponder']['enabled'])) {
      return ['ok' => FALSE, 'message' => __('This form does not send a confirmation email.', 'embed-forms')];
    }
    [$extra, $html] = self::paymentTags($entry, $form);
    $ok = Mailer::confirmation($form, $entry, $extra, $html);
    if ($ok) {
      Notes::add((int) $entry['id'], sprintf(__('Confirmation email sent again by %s.', 'embed-forms'), self::who()));
    }
    return ['ok' => $ok, 'message' => $ok ? __('Confirmation email sent.', 'embed-forms') : __('The confirmation email could not be sent: check the email address.', 'embed-forms')];
  }

  /**
   * @return array{0: array, 1: array}
   */
  private static function paymentTags(array $entry, array $form): array {
    $schema = Forms::schemaAt($form, (int) $entry['form_version']);
    $pricing = Pricing::compute($schema, $entry['data']);
    $charge = NULL;
    foreach (Payments::forEntry((int) $entry['id']) as $payment) {
      if ($payment['kind'] === 'charge' && in_array($payment['status'], ['approved', 'partially_refunded'], TRUE)) {
        $charge = $payment;
        break;
      }
    }
    if ($charge && $pricing['total'] !== (string) $charge['amount']) {
      // Show the order as it is now.
      $charge['amount'] = $pricing['total'];
    }
    return [Checkout::tags($charge, $pricing, NULL), ['payment_summary' => Checkout::summaryHtml($pricing, $charge, NULL)]];
  }

  // ------------------------------------------------------------- new orders

  /**
   * An order (or donation) entered by hand.
   *
   * @param array $pay
   *   paid (bool), amount ('' = the total), method, reference.
   *
   * @return array{ok: bool, message: string, errors: array<string, string>, entry_id: int}
   */
  public static function create(array $form, array $input, array $pay, bool $notify, string $note = ''): array {
    $schema = $form['schema'];
    $result = Validator::validate($schema, $input, ['required' => FALSE]);
    if ($result['errors']) {
      return ['ok' => FALSE, 'message' => __('Please correct the highlighted fields.', 'embed-forms'), 'errors' => $result['errors'], 'entry_id' => 0];
    }
    $values = $result['values'];
    $pricing = Pricing::compute($schema, $values);
    if (FormSchema::hasPayment($schema) && $pricing['cents'] <= 0) {
      return ['ok' => FALSE, 'message' => Status::isStore($schema) ? __('Enter a quantity.', 'embed-forms') : __('Enter an amount.', 'embed-forms'), 'errors' => [], 'entry_id' => 0];
    }
    if ($pricing['frequency'] !== 'once') {
      return ['ok' => FALSE, 'message' => __('Recurring payments cannot be entered by hand. Choose one-time.', 'embed-forms'), 'errors' => [], 'entry_id' => 0];
    }
    $paidAmount = '';
    if (!empty($pay['paid'])) {
      $paidAmount = trim((string) ($pay['amount'] ?? '')) === '' ? $pricing['total'] : (Money::normalize($pay['amount']) ?? '');
      if ($paidAmount === '' || (Money::toCents($paidAmount) ?? 0) <= 0) {
        return ['ok' => FALSE, 'message' => __('Enter the amount received.', 'embed-forms'), 'errors' => [], 'entry_id' => 0];
      }
    }
    $entryId = Entries::create($form, $values, [
      'status' => 'submitted',
      'email' => \EmbedForms\Submit\SubmissionHandler::payerEmail($form, $values),
      'amount' => FormSchema::hasPayment($schema) ? $pricing['total'] : NULL,
    ]);
    if ($entryId <= 0) {
      return ['ok' => FALSE, 'message' => __('The order could not be saved. Please try again.', 'embed-forms'), 'errors' => [], 'entry_id' => 0];
    }
    Entries::update($entryId, ['is_read' => 1]);
    Notes::add($entryId, sprintf(__('Entered by hand by %s.', 'embed-forms'), self::who()));
    if (trim($note) !== '') {
      self::note(['id' => $entryId], $note);
    }
    $entry = Entries::find($entryId);
    if ($paidAmount !== '') {
      self::recordPayment($entry, $form, $paidAmount, (string) ($pay['method'] ?? 'cash'), (string) ($pay['reference'] ?? ''));
      $entry = Entries::find($entryId);
    }
    /** This hook is documented in src/Submit/SubmissionHandler.php */
    do_action('embed_forms_entry_submitted', $entry, $form);
    do_action('embed_forms_entry_changed', (int) $form['id']);
    if ($notify) {
      [$extra, $html] = self::paymentTags($entry, $form);
      try {
        Mailer::entrySubmitted($form, $entry, $extra, $html);
      }
      catch (\Throwable $e) {
        error_log('[embed-forms] order email failed for entry ' . $entryId . ': ' . $e->getMessage());
      }
    }
    return ['ok' => TRUE, 'message' => sprintf(__('Order #%d added.', 'embed-forms'), $entryId), 'errors' => [], 'entry_id' => $entryId];
  }

}
