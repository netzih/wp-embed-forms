<?php

namespace EmbedForms\Tests;

use EmbedForms\Campaign\Donors;
use EmbedForms\Render\Design;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\FormSettings;
use EmbedForms\Schema\Validator;
use PHPUnit\Framework\TestCase;

final class CampaignTest extends TestCase {

  private function schema(): array {
    return FormSchema::normalize(['fields' => [
      ['id' => 'name', 'type' => 'name', 'label' => 'Name', 'required' => TRUE],
      ['id' => 'shown', 'type' => 'display_name', 'label' => 'Name to display'],
      ['id' => 'anon', 'type' => 'anonymous', 'label' => 'Give anonymously', 'required' => TRUE],
      ['id' => 'frequency', 'type' => 'frequency', 'frequencies' => ['once', 'month']],
      ['id' => 'address', 'type' => 'address', 'label' => 'Address', 'parts' => ['line1', 'city']],
    ]]);
  }

  public function testDonorFieldsNormalizeAndValidate(): void {
    $schema = $this->schema();
    $anon = FormSchema::find($schema, 'anon');
    self::assertFalse($anon['required']);
    self::assertSame(60, FormSchema::find($schema, 'shown')['max']);

    $result = Validator::validate($schema, ['name' => ['first' => 'Chaya', 'last' => 'Levi'], 'shown' => ' <b>The Levi Family</b> ', 'anon' => 'on']);
    self::assertSame([], $result['errors']);
    self::assertSame('The Levi Family', $result['values']['shown']);
    self::assertSame('1', $result['values']['anon']);
    self::assertSame('', Validator::validate($schema, ['anon' => 'nope'])['values']['anon']);

    $this->expectException(\InvalidArgumentException::class);
    FormSchema::normalize(['fields' => [['type' => 'anonymous'], ['type' => 'anonymous']]]);
  }

  public function testRequiredCanBeRelaxedForAdministrators(): void {
    $schema = $this->schema();
    self::assertArrayHasKey('name', Validator::validate($schema, [])['errors']);
    self::assertSame([], Validator::validate($schema, [], ['required' => FALSE])['errors']);
  }

  public function testPublicName(): void {
    $schema = $this->schema();
    $campaign = FormSettings::normalize(NULL)['campaign'];
    $name = ['first' => 'mendel', 'last' => 'kaplan'];

    self::assertSame(['name' => 'The Kaplans', 'anonymous' => FALSE], Donors::publicName($schema, ['name' => $name, 'shown' => 'The Kaplans', 'anon' => ''], $campaign));
    self::assertSame(['name' => 'mendel K.', 'anonymous' => FALSE], Donors::publicName($schema, ['name' => $name, 'shown' => '', 'anon' => ''], $campaign));
    self::assertSame(['name' => 'Anonymous', 'anonymous' => TRUE], Donors::publicName($schema, ['name' => $name, 'shown' => 'The Kaplans', 'anon' => '1'], $campaign));
    self::assertSame(['name' => 'Anonymous', 'anonymous' => TRUE], Donors::publicName($schema, ['name' => $name], ['name_fallback' => 'anonymous'] + $campaign));
    self::assertSame(['name' => 'Anonymous', 'anonymous' => TRUE], Donors::publicName($schema, [], $campaign));
  }

  public function testPublicDonorHidesWhatTheCampaignDoesNotShow(): void {
    $schema = $this->schema();
    $campaign = FormSettings::normalize(['campaign' => ['enabled' => TRUE, 'show_city' => TRUE]])['campaign'];
    $entry = ['id' => 7, 'amount' => '180.00', 'first_paid' => '2026-10-12 17:49:00', 'data' => [
      'name' => ['first' => 'Rivky', 'last' => 'Brook'], 'frequency' => 'month', 'address' => ['line1' => '1 Main St', 'city' => 'Miami'],
    ]];
    $donor = Donors::publicDonor($schema, $entry, $campaign, 'secret');
    self::assertSame('Rivky B.', $donor['name']);
    self::assertSame('180.00', $donor['amount']);
    self::assertSame('month', $donor['frequency']);
    self::assertSame('Miami', $donor['city']);
    self::assertSame('2026-10-12T17:49:00Z', $donor['at']);
    self::assertMatchesRegularExpression('/^[0-9a-f]{12}$/', $donor['id']);
    self::assertStringNotContainsString('Main', json_encode($donor));

    $entry['data']['anon'] = '1';
    $donor = Donors::publicDonor($schema, $entry, ['show_amounts' => FALSE] + $campaign, 'secret');
    self::assertNull($donor['amount']);
    self::assertNull($donor['city'], 'An anonymous donor\'s city is never shown.');
  }

  public function testDonorLimitAndStartDate(): void {
    $d = FormSettings::normalize(NULL)['campaign'];
    self::assertSame(50, $d['max_donors']);
    self::assertSame('', $d['since']);
    $s = FormSettings::normalize(['campaign' => ['max_donors' => '12', 'since' => '2026-11-01']])['campaign'];
    self::assertSame(12, $s['max_donors']);
    self::assertSame('2026-11-01', $s['since']);
    self::assertSame(200, FormSettings::normalize(['campaign' => ['max_donors' => '5000']])['campaign']['max_donors']);
    self::assertSame(50, FormSettings::normalize(['campaign' => ['max_donors' => '']])['campaign']['max_donors']);
    self::assertSame('', FormSettings::normalize(['campaign' => ['since' => '2026-02-31']])['campaign']['since']);
    self::assertSame('', FormSettings::normalize(['campaign' => ['since' => "2026-11-01' OR 1=1"]])['campaign']['since']);
  }

  public function testCampaignAndDesignSettings(): void {
    $d = FormSettings::normalize(NULL);
    self::assertFalse($d['campaign']['enabled']);
    self::assertSame('card', $d['layout']);
    self::assertFalse(Design::isDesigned($d));

    $s = FormSettings::normalize([
      'accent' => 'custom', 'accent_color' => '#8A2C3B', 'font' => 'nunito', 'heading_font' => 'baloo-2', 'layout' => 'plain', 'corners' => 'round',
      'campaign' => ['enabled' => '1', 'goal' => '$300,000', 'show_amounts' => '0', 'offset_amount' => '1,000.50', 'offset_donors' => '-3', 'name_fallback' => 'x'],
    ]);
    self::assertSame('#8a2c3b', $s['accent_color']);
    self::assertSame('300000.00', $s['campaign']['goal']);
    self::assertFalse($s['campaign']['show_amounts']);
    self::assertSame('1000.50', $s['campaign']['offset_amount']);
    self::assertSame(0, $s['campaign']['offset_donors']);
    self::assertSame('initial', $s['campaign']['name_fallback']);
    self::assertSame('', FormSettings::normalize(['accent_color' => 'red;}'])['accent_color']);
    self::assertSame('geist', FormSettings::normalize(['font' => '../evil'])['font']);

    self::assertTrue(Design::isDesigned($s));
    self::assertContains('ef-layout-plain', Design::bodyClasses($s));
    self::assertStringContainsString('family=Nunito', Design::fontsUrl($s));
    self::assertStringContainsString('family=Baloo+2', Design::fontsUrl($s));
    $vars = Design::cssVariables($s);
    self::assertStringContainsString('--ef-accent:#8a2c3b;', $vars);
    self::assertStringContainsString('--ef-accent-text:#fff;', $vars);
    self::assertStringNotContainsString('<', $vars);
    // A light brand colour gets dark button text and a darker link colour.
    $light = Design::accentVariables('#d6a654');
    self::assertSame('#1d1b18', $light['--ef-accent-text']);
    self::assertNotSame('#d6a654', $light['--ef-accent-ink']);
  }

}
