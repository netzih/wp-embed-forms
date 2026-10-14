<?php

namespace EmbedForms\Admin;

use EmbedForms\Schema\Fields;

/**
 * One form field as a plain admin input, named values[ID] (values[ID][part]
 * for names and addresses, values[ID][] for multiple choice), for the entry
 * editor. Payment fields are not edited this way.
 */
final class FieldInputs {

  public static function render(array $field, mixed $value): void {
    $id = $field['id'];
    $name = 'values[' . $id . ']';
    $domId = 'ef-v-' . $id;
    switch ($field['type']) {
      case 'textarea':
        printf('<textarea id="%1$s" name="%2$s" rows="%3$d" class="large-text">%4$s</textarea>', esc_attr($domId), esc_attr($name), (int) ($field['rows'] ?? 4), esc_textarea(self::scalar($value)));
        return;

      case 'select':
      case 'radio':
        $current = self::scalar($value);
        printf('<select id="%1$s" name="%2$s"><option value="">—</option>', esc_attr($domId), esc_attr($name));
        $known = FALSE;
        foreach ($field['options'] ?? [] as $option) {
          $known = $known || (string) $option['value'] === $current;
          printf('<option value="%1$s"%2$s>%3$s</option>', esc_attr($option['value']), selected($current, (string) $option['value'], FALSE), esc_html($option['label']));
        }
        if (!$known && $current !== '') {
          // An answer from an option that was since removed.
          printf('<option value="%1$s" selected>%1$s</option>', esc_html($current));
        }
        echo '</select>';
        return;

      case 'checkbox':
        $chosen = array_map('strval', is_array($value) ? $value : ($value === NULL || $value === '' ? [] : [$value]));
        echo '<fieldset id="' . esc_attr($domId) . '">';
        foreach ($field['options'] ?? [] as $option) {
          printf('<label style="display:block"><input type="checkbox" name="%1$s[]" value="%2$s"%3$s> %4$s</label>', esc_attr($name), esc_attr($option['value']), checked(in_array((string) $option['value'], $chosen, TRUE), TRUE, FALSE), esc_html($option['label']));
        }
        echo '</fieldset>';
        return;

      case 'name':
      case 'address':
        $parts = Fields::parts($field);
        $labels = [
          'prefix' => __('Prefix', 'embed-forms'), 'first' => __('First', 'embed-forms'), 'middle' => __('Middle', 'embed-forms'),
          'last' => __('Last', 'embed-forms'), 'suffix' => __('Suffix', 'embed-forms'), 'line1' => __('Street address', 'embed-forms'),
          'line2' => __('Apartment, suite, etc.', 'embed-forms'), 'city' => __('City', 'embed-forms'), 'state' => __('State / Province', 'embed-forms'),
          'postcode' => __('ZIP / Postal code', 'embed-forms'), 'country' => __('Country', 'embed-forms'),
        ];
        echo '<div class="ef-admin-parts">';
        foreach ($parts as $i => $part) {
          printf(
            '<label><input type="text" id="%1$s" name="%2$s[%3$s]" value="%4$s"><small>%5$s</small></label>',
            esc_attr($i === 0 ? $domId : $domId . '-' . $part),
            esc_attr($name),
            esc_attr($part),
            esc_attr(is_array($value) ? (string) ($value[$part] ?? '') : ''),
            esc_html($labels[$part] ?? $part)
          );
        }
        echo '</div>';
        return;

      case 'anonymous':
        printf('<input type="hidden" name="%1$s" value=""><label><input type="checkbox" id="%2$s" name="%1$s" value="1"%3$s> %4$s</label>', esc_attr($name), esc_attr($domId), checked(self::scalar($value) === '1', TRUE, FALSE), esc_html($field['label'] ?: __('Give anonymously', 'embed-forms')));
        return;

      default:
        $types = ['email' => 'email', 'phone' => 'tel', 'number' => 'number', 'date' => 'date'];
        printf(
          '<input type="%1$s" id="%2$s" name="%3$s" value="%4$s" class="regular-text"%5$s>',
          esc_attr($types[$field['type']] ?? 'text'),
          esc_attr($domId),
          esc_attr($name),
          esc_attr(self::scalar($value)),
          $field['type'] === 'number' ? ' step="any"' : ''
        );
        if ($field['type'] === 'hidden') {
          echo '<p class="description">' . esc_html__('Hidden on the form (filled from the link or a default).', 'embed-forms') . '</p>';
        }
        if ($field['type'] === 'display_name') {
          echo '<p class="description">' . esc_html__('Shown on the campaign donor list. Leave blank to use the first name and last initial (or Anonymous, per the campaign settings).', 'embed-forms') . '</p>';
        }
    }
  }

  private static function scalar(mixed $value): string {
    return is_scalar($value) ? (string) $value : '';
  }

}
