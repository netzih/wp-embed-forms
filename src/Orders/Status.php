<?php

namespace EmbedForms\Orders;

use EmbedForms\Payments\Money;
use EmbedForms\Payments\Pricing;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;

/**
 * Where an entry stands: its payment (worked out from its payments) and,
 * on a store form (one with a product), its order (set by a person).
 * Pure PHP so it can be unit tested.
 *
 * Money, in cents:
 * - received: what was paid (voided payments never reached the card);
 * - refunded: what went back;
 * - net: received less refunded;
 * - due: what the order total still asks for beyond what was received (a
 *   refund never makes a balance due);
 * - credit: what was kept beyond the order total (after the quantity went
 *   down, or everything on a cancelled order), to refund.
 */
final class Status {

  /**
   * Order statuses: '' is a new order.
   */
  public const ORDER = [
    '' => 'New',
    'ready' => 'Ready',
    'shipped' => 'Shipped',
    'picked_up' => 'Picked up',
    'cancelled' => 'Cancelled',
  ];

  public const PAYMENT = [
    'paid' => 'Paid',
    'unpaid' => 'Unpaid',
    'balance_due' => 'Balance due',
    'refund_due' => 'Refund due',
    'partly_refunded' => 'Partly refunded',
    'refunded' => 'Refunded',
    'pending' => 'Pending',
    'failed' => 'Failed',
    'none' => 'No payment',
  ];

  public static function orderLabel(string $key): string {
    return __(self::ORDER[$key] ?? self::ORDER[''], 'embed-forms');
  }

  public static function paymentLabel(string $key): string {
    return __(self::PAYMENT[$key] ?? $key, 'embed-forms');
  }

  /**
   * A store form sells something: it has a product.
   */
  public static function isStore(array $schema): bool {
    foreach (FormSchema::inputs($schema) as $field) {
      if ($field['type'] === 'product') {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * @param array[] $payments
   *   The entry's payments (Db\Payments::forEntry()).
   *
   * @return array{received: int, refunded: int, net: int, due: int, credit: int}
   */
  public static function money(mixed $amount, array $payments, bool $cancelled = FALSE): array {
    $received = 0;
    $refunded = 0;
    foreach ($payments as $payment) {
      if (!in_array($payment['kind'], ['charge', 'renewal'], TRUE) || !in_array($payment['status'], ['approved', 'partially_refunded', 'refunded'], TRUE)) {
        continue;
      }
      $received += Money::toCents((string) $payment['amount']) ?? 0;
      $refunded += Money::toCents((string) $payment['refunded_amount']) ?? 0;
    }
    $net = $received - $refunded;
    $total = $cancelled ? 0 : (Money::toCents((string) ($amount ?? '0')) ?? 0);
    return [
      'received' => $received,
      'refunded' => $refunded,
      'net' => $net,
      'due' => $cancelled ? 0 : max(0, $total - $received),
      'credit' => max(0, $net - $total),
    ];
  }

  /**
   * The payment status shown for an entry (a key of PAYMENT).
   */
  public static function payment(array $entry, array $money): string {
    $cancelled = ($entry['order_status'] ?? '') === 'cancelled';
    if ($entry['status'] === 'refunded') {
      return 'refunded';
    }
    if ($entry['status'] === 'failed') {
      return 'failed';
    }
    if ($entry['status'] === 'pending_payment') {
      return 'pending';
    }
    if ($money['received'] === 0) {
      return !$cancelled && (Money::toCents((string) ($entry['amount'] ?? '0')) ?? 0) > 0 ? 'unpaid' : 'none';
    }
    if ($money['credit'] > 0) {
      return 'refund_due';
    }
    if ($money['due'] > 0) {
      return 'balance_due';
    }
    if ($money['net'] === 0) {
      return 'refunded';
    }
    return $money['refunded'] > 0 ? 'partly_refunded' : 'paid';
  }

  /**
   * Products with their own quantity box, and those following one.
   *
   * @return array<string, array{label: string, price: string, own: bool, follows: string, max: int}>
   */
  public static function products(array $schema): array {
    $out = [];
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if ($field['type'] !== 'product') {
        continue;
      }
      $out[$id] = [
        'label' => $field['label'] !== '' ? $field['label'] : $id,
        'price' => (string) ($field['price'] ?? '0'),
        'own' => !empty($field['quantity']),
        'follows' => (string) ($field['quantity_of'] ?? ''),
        'max' => (int) ($field['max_quantity'] ?? 1),
      ];
    }
    return $out;
  }

  /**
   * How many of each product an entry has.
   *
   * @return array<string, int>
   */
  public static function quantities(array $schema, array $data): array {
    $out = [];
    foreach (self::products($schema) as $id => $product) {
      $out[$id] = max(0, (int) ($data[$id] ?? 0));
    }
    return $out;
  }

  /**
   * The answers and total after changing quantities: only products change
   * (and the products following them); every other answer stays.
   *
   * @param array<string, mixed> $quantities
   *   Product id => new quantity, for products with their own box.
   *
   * @return array{ok: bool, message: string, values: array, total: string, changes: array<string, array{0: int, 1: int}>}
   */
  public static function requantify(array $schema, array $data, array $quantities): array {
    $products = self::products($schema);
    $input = $data;
    foreach ($quantities as $id => $quantity) {
      if (!isset($products[$id]) || !$products[$id]['own']) {
        continue;
      }
      $quantity = trim((string) $quantity);
      if (!preg_match('/^\d{1,4}$/', $quantity)) {
        return ['ok' => FALSE, 'message' => sprintf(__('Enter a whole number for %s.', 'embed-forms'), $products[$id]['label']), 'values' => $data, 'total' => '', 'changes' => []];
      }
      if ((int) $quantity > $products[$id]['max']) {
        return ['ok' => FALSE, 'message' => sprintf(__('%1$s: at most %2$d.', 'embed-forms'), $products[$id]['label'], $products[$id]['max']), 'values' => $data, 'total' => '', 'changes' => []];
      }
      $input[$id] = $quantity;
    }
    // The validator works out what following products take.
    $checked = Validator::validate($schema, $input, ['required' => FALSE])['values'];
    $values = $data;
    $changes = [];
    foreach ($products as $id => $product) {
      $before = max(0, (int) ($data[$id] ?? 0));
      if (array_key_exists($id, $checked)) {
        $after = max(0, (int) $checked[$id]);
      }
      else {
        // A product the form hides for these answers keeps its quantity,
        // except one following another (shipping on a pickup order).
        $after = $product['follows'] !== '' ? 0 : $before;
      }
      if (!array_key_exists($id, $data) && $after === 0) {
        continue;
      }
      $values[$id] = (string) $after;
      if ($before !== $after) {
        $changes[$id] = [$before, $after];
      }
    }
    return ['ok' => TRUE, 'message' => '', 'values' => $values, 'total' => Pricing::compute($schema, $values)['total'], 'changes' => $changes];
  }

}
