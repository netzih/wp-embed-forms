<?php

namespace EmbedForms\Tests;

use EmbedForms\Security\Frame;
use EmbedForms\Security\Token;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase {

  public function testTokenRoundTrip(): void {
    $token = Token::issue(7, 'secret', 1000);
    self::assertNull(Token::check($token, 7, 'secret', 1010));
    self::assertSame('invalid', Token::check($token, 8, 'secret', 1010));
    self::assertSame('invalid', Token::check($token, 7, 'other', 1010));
    self::assertSame('too_fast', Token::check($token, 7, 'secret', 1001));
    self::assertSame('expired', Token::check($token, 7, 'secret', 1000 + DAY_IN_SECONDS + 1));
    self::assertSame('invalid', Token::check('garbage', 7, 'secret', 1010));
    [$payload, $sig] = explode('.', $token);
    self::assertSame('invalid', Token::check($payload . 'x.' . $sig, 7, 'secret', 1010));
  }

  public function testFramePolicy(): void {
    self::assertSame('frame-ancestors *', Frame::policy([]));
    self::assertSame("frame-ancestors 'self' https://example.org http://example.org https://*.example.com http://*.example.com", Frame::policy(['example.org', '*.example.com']));
  }

  public function testFrameAllows(): void {
    self::assertTrue(Frame::allows([], 'https://anything.test/'));
    self::assertTrue(Frame::allows(['example.org'], 'https://example.org/page'));
    self::assertFalse(Frame::allows(['example.org'], 'https://evil-example.org/'));
    self::assertTrue(Frame::allows(['*.example.org'], 'https://www.example.org/'));
    self::assertTrue(Frame::allows(['*.example.org'], 'https://example.org/'));
    self::assertFalse(Frame::allows(['*.example.org'], 'https://example.org.evil.test/'));
    self::assertTrue(Frame::allows(['localhost:8000'], 'http://localhost:8000/x'));
    self::assertFalse(Frame::allows(['localhost:8000'], 'http://localhost:9000/x'));
    self::assertFalse(Frame::allows(['example.org'], 'not a url'));
  }

}
