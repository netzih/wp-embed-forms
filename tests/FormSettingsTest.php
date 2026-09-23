<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\FormSettings;
use PHPUnit\Framework\TestCase;

final class FormSettingsTest extends TestCase {

  public function testDefaultsWhenEmpty(): void {
    $s = FormSettings::normalize(NULL);
    self::assertSame('Submit', $s['submit_label']);
    self::assertTrue($s['turnstile']);
    self::assertCount(1, $s['notifications']);
    self::assertTrue($s['allow_direct']);
    self::assertFalse(FormSettings::normalize(['allow_direct' => FALSE])['allow_direct']);
  }

  public function testAccent(): void {
    self::assertSame('blue', FormSettings::normalize(NULL)['accent']);
    self::assertSame('teal', FormSettings::normalize(['accent' => 'teal'])['accent']);
    self::assertSame('blue', FormSettings::normalize(['accent' => 'red; background: url(x)'])['accent']);
  }

  public function testDomains(): void {
    self::assertSame(['example.org', '*.example.com', 'localhost:8000'], FormSettings::domains("https://Example.org/\n*.example.com, localhost:8000  bad domain!  https://example.org"));
  }

  public function testRedirectNeedsHttpUrl(): void {
    $s = FormSettings::normalize(['confirmation' => ['type' => 'redirect', 'url' => 'javascript:alert(1)']]);
    self::assertSame('', $s['confirmation']['url']);
    $s = FormSettings::normalize(['confirmation' => ['type' => 'redirect', 'url' => 'https://example.org/thanks?n={field:name}']]);
    self::assertSame('https://example.org/thanks?n={field:name}', $s['confirmation']['url']);
  }

  public function testSenderAndPaymentProcessor(): void {
    $d = FormSettings::normalize(NULL);
    self::assertSame(['processor' => 'usaepay', 'usaepay_account' => 'default', 'stripe_account' => ''], $d['payment']);
    self::assertSame('', $d['from_email']);

    $s = FormSettings::normalize([
      'from_name' => "Camp\nOffice",
      'from_email' => ' Camp@Example.org ',
      'payment' => ['processor' => 'stripe', 'usaepay_account' => '', 'stripe_account' => 'Camp Account!'],
    ]);
    self::assertSame('Camp Office', $s['from_name']);
    self::assertSame('camp@example.org', $s['from_email']);
    self::assertSame(['processor' => 'stripe', 'usaepay_account' => 'default', 'stripe_account' => 'campaccount'], $s['payment']);

    self::assertSame('', FormSettings::normalize(['from_email' => 'not an email'])['from_email']);
    self::assertSame('usaepay', FormSettings::normalize(['payment' => ['processor' => 'paypal']])['payment']['processor']);
  }

}
