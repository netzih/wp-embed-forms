<?php

namespace EmbedForms\Tests;

use EmbedForms\Payments\Money;
use EmbedForms\Payments\Payer;
use EmbedForms\Payments\Pricing;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;
use PHPUnit\Framework\TestCase;

final class PaymentsTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'name', 'type' => 'name'],
      ['id' => 'email', 'type' => 'email'],
      ['id' => 'amount', 'type' => 'amount', 'required' => TRUE, 'amount_mode' => 'choices', 'amounts' => [['amount' => '18'], ['amount' => '36']], 'allow_other' => TRUE, 'min' => '5', 'max' => '1000'],
      ['id' => 'frequency', 'type' => 'frequency', 'frequencies' => ['once', 'month', 'bogus'], 'recurring_times' => 12],
      ['id' => 'shirt', 'type' => 'product', 'price' => '20', 'quantity' => TRUE, 'max_quantity' => 3],
      ['id' => 'fee', 'type' => 'product', 'price' => '2.50', 'conditions' => ['rules' => [['field' => 'frequency', 'op' => 'is', 'value' => 'once']]]],
      ['id' => 'total', 'type' => 'total'],
      ['id' => 'payment', 'type' => 'payment'],
    ]]);
  }

  public function testMoney(): void {
    self::assertSame(1250, Money::toCents('12.5'));
    self::assertSame(123456, Money::toCents('$1,234.56'));
    self::assertSame(1200, Money::toCents(12));
    self::assertNull(Money::toCents('12.345'));
    self::assertNull(Money::toCents('-5'));
    self::assertNull(Money::toCents('abc'));
    self::assertSame('0.05', Money::fromCents(5));
    self::assertSame('$1,234.50', Money::format('1234.5'));
  }

  public function testNormalizesPaymentFields(): void {
    $s = $this->schema();
    self::assertSame([['amount' => '18.00', 'label' => ''], ['amount' => '36.00', 'label' => '']], $s['fields'][2]['amounts']);
    self::assertSame(['once', 'month'], $s['fields'][3]['frequencies']);
    self::assertSame('once', $s['fields'][3]['default']);
    self::assertSame('20.00', $s['fields'][4]['price']);
    self::assertTrue(FormSchema::hasPayment($s));
  }

  public function testCardFieldRules(): void {
    foreach ([
      ['fields' => [['id' => 'p', 'type' => 'payment'], ['id' => 'q', 'type' => 'payment']]],
      ['fields' => [['id' => 'p', 'type' => 'payment'], ['id' => 'pg', 'type' => 'page']]],
      ['fields' => [['id' => 'a', 'type' => 'frequency'], ['id' => 'b', 'type' => 'frequency']]],
    ] as $bad) {
      try {
        FormSchema::normalize($bad);
        self::fail('Accepted ' . json_encode($bad));
      }
      catch (\InvalidArgumentException $e) {
        self::assertNotSame('', $e->getMessage());
      }
    }
  }

  public function testPricingUsesOnlyVisibleValidatedAnswers(): void {
    $s = $this->schema();
    $once = Validator::validate($s, ['amount' => '36', 'frequency' => 'once', 'shirt' => '2', 'fee' => '1']);
    self::assertSame([], $once['errors']);
    $p = Pricing::compute($s, $once['values']);
    self::assertSame('78.50', $p['total']);
    self::assertSame('once', $p['frequency']);
    self::assertSame(0, $p['recurring_times']);
    self::assertCount(3, $p['lines']);

    // Monthly hides the fee: its answer is dropped and adds nothing, even
    // though the browser sent it.
    $monthly = Validator::validate($s, ['amount' => '36', 'frequency' => 'month', 'shirt' => '0', 'fee' => '1']);
    $p = Pricing::compute($s, $monthly['values']);
    self::assertSame('36.00', $p['total']);
    self::assertSame('month', $p['frequency']);
    self::assertSame(12, $p['recurring_times']);
  }

  public function testAmountValidation(): void {
    $s = $this->schema();
    $errors = static fn(array $v) => Validator::validate($s, $v + ['frequency' => 'once'])['errors'];
    self::assertArrayHasKey('amount', $errors(['amount' => '']));
    self::assertArrayHasKey('amount', $errors(['amount' => '4']));
    self::assertArrayHasKey('amount', $errors(['amount' => '1001']));
    self::assertArrayHasKey('amount', $errors(['amount' => 'lots']));
    self::assertArrayNotHasKey('amount', $errors(['amount' => '25.5']));
    self::assertArrayHasKey('shirt', $errors(['amount' => '18', 'shirt' => '4']));
    self::assertArrayHasKey('frequency', Validator::validate($s, ['amount' => '18', 'frequency' => 'week'])['errors']);

    $noOther = FormSchema::normalize(['fields' => [['id' => 'a', 'type' => 'amount', 'amount_mode' => 'choices', 'amounts' => ['10', '20']]]]);
    self::assertArrayHasKey('a', Validator::validate($noOther, ['a' => '15'])['errors']);
    self::assertArrayNotHasKey('a', Validator::validate($noOther, ['a' => '20'])['errors']);

    // A fixed amount ignores what the browser sends.
    $fixed = FormSchema::normalize(['fields' => [['id' => 'a', 'type' => 'amount', 'amount_mode' => 'fixed', 'fixed_amount' => '50']]]);
    $r = Validator::validate($fixed, ['a' => '0.01']);
    self::assertSame('50.00', $r['values']['a']);
    // A product without a quantity box counts once when shown.
    $single = FormSchema::normalize(['fields' => [['id' => 'p', 'type' => 'product', 'price' => '5']]]);
    self::assertSame('5.00', Pricing::compute($single, Validator::validate($single, ['p' => '99'])['values'])['total']);
  }

  public function testPayer(): void {
    $s = FormSchema::normalize(['fields' => [
      ['id' => 'n', 'type' => 'name'],
      ['id' => 'e', 'type' => 'email'],
      ['id' => 'a', 'type' => 'address'],
    ]]);
    $payer = Payer::fromValues($s, ['n' => ['first' => 'Ada', 'last' => 'L'], 'e' => 'a@x.org', 'a' => ['line1' => '1 Main', 'city' => 'Richmond', 'state' => 'VA', 'postcode' => '23220', 'country' => 'US']]);
    self::assertSame('Ada', $payer['first_name']);
    self::assertSame('a@x.org', $payer['email']);
    self::assertSame('23220', $payer['postcode']);
    self::assertSame('Ada L', Payer::name($payer));
  }

}
