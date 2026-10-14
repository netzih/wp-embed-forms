<?php

namespace EmbedForms\Campaign;

use EmbedForms\Schema\FormSchema;

/**
 * How one entry appears on a campaign's public donor list: the name to show
 * (Anonymous when the person asked, or gave no name), the amount and city
 * when the campaign shows them, and the frequency. Nothing else about the
 * person leaves the site. Pure PHP so it can be unit tested; assets/form.js
 * (donorName) previews the same name to the person filling in the form.
 */
final class Donors {

  /**
   * @param array $schema
   *   The form schema the entry was made on.
   * @param array $data
   *   The entry's answers.
   * @param array $campaign
   *   The form's campaign settings (FormSettings 'campaign').
   *
   * @return array{name: string, anonymous: bool}
   */
  public static function publicName(array $schema, array $data, array $campaign): array {
    $shown = '';
    $first = '';
    $last = '';
    $nameFound = FALSE;
    foreach (FormSchema::inputs($schema) as $id => $field) {
      $value = $data[$id] ?? NULL;
      if ($field['type'] === 'anonymous' && $value === '1') {
        return ['name' => self::anonymousLabel(), 'anonymous' => TRUE];
      }
      if ($field['type'] === 'display_name' && is_scalar($value)) {
        $shown = self::clean((string) $value);
      }
      if ($field['type'] === 'name' && !$nameFound && is_array($value)) {
        $nameFound = TRUE;
        $first = self::clean((string) ($value['first'] ?? ''));
        $last = self::clean((string) ($value['last'] ?? ''));
      }
    }
    if ($shown !== '') {
      return ['name' => $shown, 'anonymous' => FALSE];
    }
    if (($campaign['name_fallback'] ?? 'initial') !== 'anonymous' && $first !== '') {
      $initial = $last !== '' ? ' ' . mb_strtoupper(mb_substr($last, 0, 1)) . '.' : '';
      return ['name' => $first . $initial, 'anonymous' => FALSE];
    }
    return ['name' => self::anonymousLabel(), 'anonymous' => TRUE];
  }

  /**
   * One donor as the public list shows them.
   *
   * @param array $entry
   *   id, data, amount, and first_paid (Y-m-d H:i:s UTC).
   *
   * @return array{id: string, name: string, anonymous: bool, amount: ?string, frequency: string, city: ?string, at: string}
   */
  public static function publicDonor(array $schema, array $entry, array $campaign, string $secret): array {
    $name = self::publicName($schema, $entry['data'], $campaign);
    $frequency = 'once';
    $city = NULL;
    foreach (FormSchema::inputs($schema) as $id => $field) {
      $value = $entry['data'][$id] ?? NULL;
      if ($field['type'] === 'frequency' && is_string($value) && $value !== '') {
        $frequency = $value;
      }
      if ($field['type'] === 'address' && $city === NULL && is_array($value) && trim((string) ($value['city'] ?? '')) !== '') {
        $city = self::clean((string) $value['city']);
      }
    }
    return [
      // Stable per entry without giving the entry number away.
      'id' => substr(hash_hmac('sha256', 'donor-' . $entry['id'], $secret), 0, 12),
      'name' => $name['name'],
      'anonymous' => $name['anonymous'],
      'amount' => !empty($campaign['show_amounts']) && $entry['amount'] !== NULL && $entry['amount'] !== '' ? (string) $entry['amount'] : NULL,
      'frequency' => $frequency,
      // An anonymous donor's town could point at them.
      'city' => !empty($campaign['show_city']) && !$name['anonymous'] ? $city : NULL,
      'at' => gmdate('Y-m-d\TH:i:s\Z', strtotime($entry['first_paid'] . ' UTC')),
    ];
  }

  public static function anonymousLabel(): string {
    return function_exists('__') ? __('Anonymous', 'embed-forms') : 'Anonymous';
  }

  private static function clean(string $value): string {
    $value = trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '');
    return mb_substr($value, 0, 60);
  }

}
