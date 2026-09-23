<?php

namespace EmbedForms\Tests;

use EmbedForms\Payments\Stripe\Client;
use EmbedForms\Payments\Stripe\Gateway;
use EmbedForms\Settings;
use PHPUnit\Framework\TestCase;

final class StripeTest extends TestCase {

  /** @var array<int, array{path: string, params: array, key: ?string}> */
  private array $sent = [];

  /** @var array<int, array|\Throwable> */
  private array $answers = [];

  protected function setUp(): void {
    $this->sent = [];
    $this->answers = [];
    $GLOBALS['ef_test_filters'] = [];
    add_filter('embed_forms_stripe_response', function ($value, string $method, string $path, array $params, ?string $key) {
      $this->sent[] = ['path' => $path, 'params' => $params, 'key' => $key];
      $answer = array_shift($this->answers);
      if ($answer instanceof \Throwable) {
        throw $answer;
      }
      return $answer;
    });
  }

  private function intent(string $status, array $extra = []): array {
    return ['status' => 200, 'body' => $extra + [
      'id' => 'pi_1',
      'status' => $status,
      'amount' => 1800,
      'amount_received' => $status === 'succeeded' ? 1800 : 0,
      'payment_method' => 'pm_1',
      'customer' => NULL,
      'client_secret' => 'pi_1_secret',
      'latest_charge' => ['id' => 'ch_1', 'payment_method_details' => ['card' => ['brand' => 'visa', 'last4' => '4242']]],
    ]];
  }

  /**
   * @return array{0: callable, 1: callable, 2: \ArrayObject}
   */
  private function store(): array {
    $box = new \ArrayObject(['marker' => NULL]);
    return [static fn() => $box['marker'], static function (?array $m) use ($box): void { $box['marker'] = $m; }, $box];
  }

  public function testEncodesNestedParamsAndBooleans(): void {
    self::assertSame(
      'amount=1800&confirm=true&metadata%5Borderid%5D=a%20b&expand%5B0%5D=latest_charge',
      Client::encode(['amount' => 1800, 'confirm' => TRUE, 'skip' => NULL, 'metadata' => ['orderid' => 'a b'], 'expand' => ['latest_charge']])
    );
  }

  public function testIntentStates(): void {
    $ok = Gateway::fromIntent($this->intent('succeeded')['body'], 'main', 'sandbox', FALSE);
    self::assertSame('approved', $ok['outcome']);
    self::assertSame('18.00', $ok['amount']);
    self::assertSame(['transaction_key' => 'pi_1', 'refnum' => 'ch_1', 'auth_code' => '', 'card_brand' => 'Visa', 'card_last4' => '4242'], $ok['columns']);
    self::assertSame('pm_1', $ok['card_reference']);

    $action = Gateway::fromIntent($this->intent('requires_action')['body'], 'main', 'sandbox', FALSE);
    self::assertSame('action', $action['outcome']);
    self::assertSame('pi_1_secret', $action['client_secret']);

    $declined = Gateway::fromIntent($this->intent('requires_payment_method', ['last_payment_error' => ['message' => 'Your card was declined.', 'decline_code' => 'insufficient_funds']])['body'], 'main', 'sandbox', FALSE);
    self::assertSame('declined', $declined['outcome']);
    self::assertSame('Your card was declined.', $declined['payer']);
  }

  public function testALostAnswerIsResentUnchangedUnderTheSameKey(): void {
    [$read, $write, $box] = $this->store();
    $client = new Client('sk_test_x');
    $request = ['method' => 'POST', 'path' => 'payment_intents', 'params' => ['amount' => 1800, 'payment_method' => 'pm_first']];

    $this->answers[] = new \EmbedForms\Payments\Stripe\AmbiguousError('timeout');
    $first = Gateway::run($client, $read, $write, 'ab12-ef-5-1', '18.00', $request, static fn() => NULL);
    self::assertSame('unresolved', $first['result']['outcome']);
    self::assertSame('ab12-ef-5-1', $box['marker']['orderid']);

    // The payer tries again with another card: the stored request goes out.
    $this->answers[] = $this->intent('succeeded') + ['replayed' => TRUE];
    $second = Gateway::run($client, $read, $write, 'ab12-ef-5-1', '18.00', ['method' => 'POST', 'path' => 'payment_intents', 'params' => ['amount' => 1800, 'payment_method' => 'pm_second']], static fn() => NULL);
    self::assertSame('pi_1', $second['response']['id']);
    self::assertTrue($second['reconciled']);
    self::assertCount(2, $this->sent);
    self::assertSame($this->sent[0]['params'], $this->sent[1]['params']);
    self::assertSame('ab12-ef-5-1', $this->sent[1]['key']);
  }

  public function testAStaleMarkerIsLookedUpBeforeSending(): void {
    [$read, $write, $box] = $this->store();
    $box['marker'] = ['orderid' => 'k1', 'sent_at' => time() - 2 * 86400, 'request' => ['method' => 'POST', 'path' => 'payment_intents', 'params' => ['amount' => 100]]];
    $found = Gateway::run(new Client('sk_test_x'), $read, $write, 'k1', '1.00', NULL, static fn() => ['id' => 'pi_old', 'status' => 'succeeded']);
    self::assertSame('pi_old', $found['response']['id']);
    self::assertTrue($found['reconciled']);
    self::assertSame([], $this->sent);
  }

  public function testCardDeclinesUseStripesWording(): void {
    [$read, $write] = $this->store();
    $this->answers[] = ['status' => 402, 'body' => ['error' => ['type' => 'card_error', 'code' => 'card_declined', 'decline_code' => 'generic_decline', 'message' => 'Your card was declined.']]];
    $out = Gateway::run(new Client('sk_test_x'), $read, $write, 'k2', '1.00', ['method' => 'POST', 'path' => 'payment_intents', 'params' => []], static fn() => NULL);
    self::assertSame('declined', $out['result']['outcome']);
    self::assertSame('Your card was declined.', $out['result']['payer']);
    self::assertSame('Your card was declined. (card_declined, generic_decline)', $out['result']['gateway']);
  }

  public function testStripeAccountsKeepIdsAndSecrets(): void {
    $current = ['main' => ['id' => 'main', 'label' => 'Main', 'test_secret_key' => 'sk_test_saved']];
    $out = Settings::sanitizeStripeAccounts([
      ['id' => 'main', 'label' => 'Main', 'test_publishable_key' => 'pk_test_1', 'test_secret_key' => ''],
      ['label' => 'Camp', 'test_publishable_key' => 'pk_test_2', 'test_secret_key' => 'sk_test_2'],
      ['label' => '', 'test_publishable_key' => ''],
    ], $current);
    self::assertSame(['main', 'camp'], array_column($out, 'id'));
    self::assertSame('sk_test_saved', $out[0]['test_secret_key']);

    $GLOBALS['ef_test_options'][Settings::OPTION] = ['stripe_accounts' => $out, 'stripe_mode' => 'test'];
    Settings::forget();
    self::assertSame('sandbox', Settings::stripeMode());
    self::assertSame('pk_test_2', Settings::stripeKey('camp', 'sandbox', 'publishable'));
    self::assertSame('', Settings::stripeKey('camp', 'live', 'secret'));
    unset($GLOBALS['ef_test_options'][Settings::OPTION]);
    Settings::forget();
  }

}
