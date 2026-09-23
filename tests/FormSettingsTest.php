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

  public function testDomains(): void {
    self::assertSame(['example.org', '*.example.com', 'localhost:8000'], FormSettings::domains("https://Example.org/\n*.example.com, localhost:8000  bad domain!  https://example.org"));
  }

  public function testRedirectNeedsHttpUrl(): void {
    $s = FormSettings::normalize(['confirmation' => ['type' => 'redirect', 'url' => 'javascript:alert(1)']]);
    self::assertSame('', $s['confirmation']['url']);
    $s = FormSettings::normalize(['confirmation' => ['type' => 'redirect', 'url' => 'https://example.org/thanks?n={field:name}']]);
    self::assertSame('https://example.org/thanks?n={field:name}', $s['confirmation']['url']);
  }

}
