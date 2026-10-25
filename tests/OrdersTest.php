<?php

namespace EmbedForms\Tests;

use EmbedForms\Orders\Status;
use EmbedForms\Orders\Tracking;
use EmbedForms\Schema\FormSchema;
use PHPUnit\Framework\TestCase;

final class OrdersTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'book', 'type' => 'product', 'label' => 'Book', 'price' => '24.99', 'quantity' => TRUE, 'max_quantity' => 20],
      ['id' => 'delivery', 'type' => 'radio', 'label' => 'Delivery', 'options' => [['value' => 'pickup', 'label' => 'Pick up'], ['value' => 'ship', 'label' => 'Ship']]],
      ['id' => 'shipping', 'type' => 'product', 'label' => 'Shipping', 'price' => '5.00', 'quantity_of' => 'book', 'conditions' => ['action' => 'show', 'match' => 'all', 'rules' => [['field' => 'delivery', 'operator' => 'is', 'value' => 'ship']]]],
      ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
    ]]);
  }

  private static function payment(string $amount, string $refunded = '0.00', string $status = 'approved', string $kind = 'charge'): array {
    return ['kind' => $kind, 'status' => $status, 'amount' => $amount, 'refunded_amount' => $refunded];
  }

  public function testStoreFormsHaveAProduct(): void {
    self::assertTrue(Status::isStore($this->schema()));
    self::assertFalse(Status::isStore(FormSchema::normalize(['fields' => [['id' => 'gift', 'type' => 'amount', 'label' => 'Gift']]])));
  }

  public function testMoneyAndPaymentStatus(): void {
    $entry = ['status' => 'paid', 'amount' => '59.98', 'order_status' => ''];
    $paid = Status::money('59.98', [self::payment('59.98')]);
    self::assertSame(['received' => 5998, 'refunded' => 0, 'net' => 5998, 'due' => 0, 'credit' => 0], $paid);
    self::assertSame('paid', Status::payment($entry, $paid));

    // Quantity went up: the extra is due; a refund never makes a balance due.
    self::assertSame('balance_due', Status::payment(['amount' => '84.97'] + $entry, Status::money('84.97', [self::payment('59.98')])));
    $goodwill = Status::money('59.98', [self::payment('59.98', '5.00', 'partially_refunded')]);
    self::assertSame(0, $goodwill['due']);
    self::assertSame('partly_refunded', Status::payment($entry, $goodwill));

    // Quantity went down: what was paid beyond the total is to refund.
    $down = Status::money('29.99', [self::payment('59.98')]);
    self::assertSame(2999, $down['credit']);
    self::assertSame('refund_due', Status::payment(['amount' => '29.99'] + $entry, $down));

    // Cancelled: everything kept is to refund, nothing is due.
    $cancelled = Status::money('59.98', [self::payment('59.98')], TRUE);
    self::assertSame(['due' => 0, 'credit' => 5998], ['due' => $cancelled['due'], 'credit' => $cancelled['credit']]);
    self::assertSame('refunded', Status::payment(['order_status' => 'cancelled'] + $entry, Status::money('59.98', [self::payment('59.98', '59.98', 'refunded')], TRUE)));

    // Entered by hand, not paid yet; voided payments never arrived.
    self::assertSame('unpaid', Status::payment(['status' => 'submitted'] + $entry, Status::money('59.98', [self::payment('59.98', '59.98', 'voided')])));
    self::assertSame('none', Status::payment(['status' => 'submitted', 'amount' => NULL] + $entry, Status::money(NULL, [])));
    self::assertSame('refunded', Status::payment(['status' => 'refunded'] + $entry, $paid));
  }

  public function testRequantifyMovesFollowersAndTotal(): void {
    $data = ['book' => '2', 'delivery' => 'ship', 'shipping' => '2', 'email' => 'bad address'];
    $result = Status::requantify($this->schema(), $data, ['book' => '3']);
    self::assertTrue($result['ok']);
    self::assertSame('3', $result['values']['book']);
    self::assertSame('3', $result['values']['shipping']);
    // Other answers stay exactly as stored, even ones that would not pass.
    self::assertSame('bad address', $result['values']['email']);
    self::assertSame('89.97', $result['total']);
    self::assertSame(['book' => [2, 3], 'shipping' => [2, 3]], $result['changes']);

    $pickup = Status::requantify($this->schema(), ['book' => '1', 'delivery' => 'pickup'], ['book' => '4']);
    self::assertArrayNotHasKey('shipping', $pickup['values']);
    self::assertSame('99.96', $pickup['total']);

    // Switched to pickup after paying for shipping: shipping goes.
    $switched = Status::requantify($this->schema(), ['book' => '3', 'delivery' => 'pickup', 'shipping' => '3'], ['book' => '4']);
    self::assertSame('0', $switched['values']['shipping']);
    self::assertSame('99.96', $switched['total']);
    self::assertSame([3, 0], $switched['changes']['shipping']);

    self::assertFalse(Status::requantify($this->schema(), $data, ['book' => '21'])['ok']);
    self::assertFalse(Status::requantify($this->schema(), $data, ['book' => '-1'])['ok']);
    // A following product cannot be set on its own.
    self::assertSame([], Status::requantify($this->schema(), $data, ['shipping' => '9'])['changes']);
    self::assertSame(['book' => 3, 'shipping' => 3], Status::quantities($this->schema(), $result['values']));
  }

  public function testTrackingLinks(): void {
    self::assertSame('UPS', Tracking::link('1z 999 aa1 01 2345 6784')['carrier']);
    self::assertStringContainsString('tLabels=9400111899223197428490', Tracking::link('9400 1118 9922 3197 4284 90')['url']);
    self::assertSame('FedEx', Tracking::link('123456789012')['carrier']);
    self::assertSame('', Tracking::link('ABC')['url']);
    self::assertSame('https://example.com/t/1', Tracking::link('https://example.com/t/1')['url']);
    self::assertSame('', Tracking::link('')['url']);
  }

}
