<?php

namespace EmbedForms\Schema;

/**
 * The field types a form can hold and what each one is. Shared by the
 * schema normalizer, the server-side validator, the builder palette and the
 * entry screens, so a type added here is known everywhere.
 */
final class Fields {

  /**
   * Types that collect a value, keyed by type, with the shape of the value:
   * 'string', 'list' (several choices), or 'object' (named parts).
   */
  public const INPUTS = [
    'text' => 'string',
    'textarea' => 'string',
    'email' => 'string',
    'phone' => 'string',
    'number' => 'string',
    'select' => 'string',
    'radio' => 'string',
    'checkbox' => 'list',
    'date' => 'string',
    'name' => 'object',
    'address' => 'object',
    'hidden' => 'string',
    'amount' => 'string',
    'product' => 'string',
    'frequency' => 'string',
  ];

  /**
   * Types that only lay the form out.
   */
  public const LAYOUT = ['html', 'section', 'page', 'total', 'payment'];

  /**
   * Types that price the form or take the payment. Charged only through
   * USAePay Payments.
   */
  public const PAYMENT_TYPES = ['amount', 'product', 'frequency', 'total', 'payment'];

  /**
   * Types a form may hold only once.
   */
  public const SINGLE = ['frequency', 'payment'];

  /**
   * Payment intervals a frequency field can offer; 'once' is a one-time
   * payment.
   */
  public const FREQUENCIES = ['once', 'week', 'month', 'year'];

  public const CHOICE_TYPES = ['select', 'radio', 'checkbox'];

  public const NAME_PARTS = ['prefix', 'first', 'middle', 'last', 'suffix'];

  public const ADDRESS_PARTS = ['line1', 'line2', 'city', 'state', 'postcode', 'country'];

  /**
   * The builder palette: type => [label, group, dashicon].
   *
   * @return array<string, array{label: string, group: string, icon: string}>
   */
  public static function palette(): array {
    $p = static fn(string $label, string $group, string $icon) => ['label' => $label, 'group' => $group, 'icon' => $icon];
    return [
      'text' => $p(__('Short text', 'embed-forms'), 'basic', 'editor-textcolor'),
      'textarea' => $p(__('Paragraph', 'embed-forms'), 'basic', 'editor-paragraph'),
      'email' => $p(__('Email', 'embed-forms'), 'basic', 'email'),
      'phone' => $p(__('Phone', 'embed-forms'), 'basic', 'phone'),
      'number' => $p(__('Number', 'embed-forms'), 'basic', 'calculator'),
      'date' => $p(__('Date', 'embed-forms'), 'basic', 'calendar-alt'),
      'name' => $p(__('Name', 'embed-forms'), 'basic', 'admin-users'),
      'address' => $p(__('Address', 'embed-forms'), 'basic', 'location'),
      'select' => $p(__('Dropdown', 'embed-forms'), 'choice', 'menu-alt'),
      'radio' => $p(__('Single choice', 'embed-forms'), 'choice', 'marker'),
      'checkbox' => $p(__('Multiple choice', 'embed-forms'), 'choice', 'yes-alt'),
      'hidden' => $p(__('Hidden value', 'embed-forms'), 'choice', 'hidden'),
      'section' => $p(__('Section heading', 'embed-forms'), 'layout', 'heading'),
      'html' => $p(__('Text / HTML', 'embed-forms'), 'layout', 'editor-code'),
      'page' => $p(__('Page break', 'embed-forms'), 'layout', 'editor-insertmore'),
      'amount' => $p(__('Amount', 'embed-forms'), 'payment', 'money-alt'),
      'product' => $p(__('Product', 'embed-forms'), 'payment', 'cart'),
      'frequency' => $p(__('Frequency', 'embed-forms'), 'payment', 'update'),
      'total' => $p(__('Total', 'embed-forms'), 'payment', 'chart-bar'),
      'payment' => $p(__('Card payment', 'embed-forms'), 'payment', 'id'),
    ];
  }

  public static function frequencyLabel(string $frequency): string {
    $labels = [
      'once' => __('One time', 'embed-forms'),
      'week' => __('Weekly', 'embed-forms'),
      'month' => __('Monthly', 'embed-forms'),
      'year' => __('Yearly', 'embed-forms'),
    ];
    return $labels[$frequency] ?? $frequency;
  }

  public static function all(): array {
    return array_merge(array_keys(self::INPUTS), self::LAYOUT);
  }

  public static function isInput(string $type): bool {
    return isset(self::INPUTS[$type]);
  }

  public static function valueShape(string $type): ?string {
    return self::INPUTS[$type] ?? NULL;
  }

  /**
   * The parts an object-valued field is made of, as configured on the field
   * (a name field without middle name, an address without line 2).
   *
   * @return string[]
   */
  public static function parts(array $field): array {
    $all = $field['type'] === 'name' ? self::NAME_PARTS : ($field['type'] === 'address' ? self::ADDRESS_PARTS : []);
    $enabled = isset($field['parts']) && is_array($field['parts']) ? array_values(array_intersect($all, $field['parts'])) : [];
    if ($enabled) {
      return $enabled;
    }
    return $field['type'] === 'name' ? ['first', 'last'] : ($field['type'] === 'address' ? ['line1', 'line2', 'city', 'state', 'postcode', 'country'] : []);
  }

  /**
   * Parts that must be filled when the field is required.
   *
   * @return string[]
   */
  public static function requiredParts(array $field): array {
    $parts = self::parts($field);
    $optional = ['prefix', 'middle', 'suffix', 'line2'];
    return array_values(array_diff($parts, $optional));
  }

}
