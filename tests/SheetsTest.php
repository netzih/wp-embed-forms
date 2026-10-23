<?php

namespace EmbedForms\Tests;

use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\FormSettings;
use EmbedForms\Settings;
use EmbedForms\Sheets\Client;
use EmbedForms\Sheets\Rows;
use PHPUnit\Framework\TestCase;

final class SheetsTest extends TestCase {

  private function inputs(): array {
    return FormSchema::inputs(FormSchema::normalize(['fields' => [
      ['id' => 'name', 'type' => 'name', 'label' => 'Name'],
      ['id' => 'email', 'type' => 'email', 'label' => 'Email'],
      ['id' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => [['value' => 's', 'label' => 'Small'], ['value' => 'l', 'label' => 'Large']]],
      ['id' => 'note', 'type' => 'textarea', 'label' => 'Note'],
    ]]));
  }

  public function testHeadersFollowTheCsvAndAreUnique(): void {
    $headers = Rows::headers($this->inputs());
    self::assertSame(['Entry', 'Date', 'Status', 'Email', 'Amount', 'Name (first)', 'Name (last)', 'Email (2)', 'Size', 'Note', 'Transaction', 'Card', 'Submitted on'], $headers);
  }

  public function testValuesAreRawWithNumbersForIdAndAmount(): void {
    $entry = [
      'id' => 42,
      'status' => 'paid',
      'payer_email' => 'a@example.org',
      'amount' => '26.00',
      'source_url' => 'https://example.org/book/',
      'data' => ['name' => ['first' => 'Chaya', 'last' => 'Levi'], 'email' => 'a@example.org', 'size' => 'l', 'note' => '=HYPERLINK("x")'],
    ];
    $row = Rows::values($entry, $this->inputs(), '2026-10-23 09:15', ['gateway' => 'stripe', 'mode' => 'sandbox', 'refnum' => '', 'transaction_key' => 'pi_123', 'card_brand' => 'Visa', 'card_last4' => '4242']);
    self::assertSame([42, '2026-10-23 09:15', 'paid', 'a@example.org', 26.0, 'Chaya', 'Levi', 'a@example.org', 'Large', '=HYPERLINK("x")', 'pi_123 (test)', 'Visa •••• 4242', 'https://example.org/book/'], $row);

    $entry['amount'] = NULL;
    $offline = Rows::values($entry, $this->inputs(), 'd', ['gateway' => 'offline', 'method' => 'check', 'refnum' => '1001', 'mode' => '']);
    self::assertSame('', $offline[4]);
    self::assertSame('Offline check 1001', $offline[10]);
    self::assertSame('', $offline[11]);
    self::assertSame('', Rows::values($entry, $this->inputs(), 'd')[10]);
  }

  public function testHeaderMergeKeepsTheSheetsOrderAndAddsNewColumns(): void {
    $ours = ['Entry', 'Date', 'Size', 'Note'];
    self::assertSame($ours, Rows::mergeHeader([], $ours));
    $header = Rows::mergeHeader(['Date', 'entry', 'Our notes', 'Size', '', ''], $ours);
    self::assertSame(['Date', 'entry', 'Our notes', 'Size', 'Note'], $header);
    self::assertSame(['d', 7, '', 'L', 'n'], Rows::align($header, $ours, [7, 'd', 'L', 'n']));
  }

  public function testColumnLettersRangesAndSpreadsheetIds(): void {
    self::assertSame('A', Rows::columnLetter(0));
    self::assertSame('Z', Rows::columnLetter(25));
    self::assertSame('AA', Rows::columnLetter(26));
    self::assertSame('BA', Rows::columnLetter(52));
    self::assertSame("'Bob''s tab'!A1", Rows::range("Bob's tab", 'A1'));
    $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789-ab';
    self::assertSame($id, Rows::spreadsheetId('https://docs.google.com/spreadsheets/d/' . $id . '/edit#gid=0'));
    self::assertSame($id, Rows::spreadsheetId(' ' . $id . ' '));
    self::assertSame('', Rows::spreadsheetId('https://example.org/sheet'));
  }

  public function testFormSettingsNormalizeSheets(): void {
    self::assertSame(['enabled' => FALSE, 'spreadsheet' => '', 'tab' => ''], FormSettings::normalize([])['sheets']);
    $id = '1AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789-ab';
    $s = FormSettings::normalize(['sheets' => ['enabled' => '1', 'spreadsheet' => 'https://docs.google.com/spreadsheets/d/' . $id . '/edit', 'tab' => " Entries\n"]]);
    self::assertSame(['enabled' => TRUE, 'spreadsheet' => $id, 'tab' => 'Entries'], $s['sheets']);
  }

  public function testServiceAccountKeyIsParsedAndSignsAJwt(): void {
    self::assertNull(Settings::parseServiceAccount('{"type":"authorized_user"}'));
    self::assertNull(Settings::parseServiceAccount('not json'));

    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    $account = Settings::parseServiceAccount((string) json_encode([
      'type' => 'service_account',
      'project_id' => 'p',
      'private_key_id' => 'kid1',
      'private_key' => $pem,
      'client_email' => 'forms@p.iam.gserviceaccount.com',
      'token_uri' => 'https://oauth2.googleapis.com/token',
    ]));
    self::assertSame('forms@p.iam.gserviceaccount.com', $account['client_email']);

    $jwt = Client::jwt($account, 'https://oauth2.googleapis.com/token', 1000);
    [$header, $claims, $signature] = explode('.', $jwt);
    $decode = static fn(string $part) => base64_decode(strtr($part, '-_', '+/'));
    self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid1'], json_decode($decode($header), TRUE));
    self::assertSame(['iss' => 'forms@p.iam.gserviceaccount.com', 'scope' => Client::SCOPE, 'aud' => 'https://oauth2.googleapis.com/token', 'iat' => 1000, 'exp' => 4600], json_decode($decode($claims), TRUE));
    $public = openssl_pkey_get_details($key)['key'];
    self::assertSame(1, openssl_verify($header . '.' . $claims, $decode($signature), $public, OPENSSL_ALGO_SHA256));
  }

}
