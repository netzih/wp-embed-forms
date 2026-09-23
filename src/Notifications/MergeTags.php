<?php

namespace EmbedForms\Notifications;

use EmbedForms\Schema\Display;
use EmbedForms\Schema\FormSchema;

/**
 * Replaces {tags} in notification addresses, subjects and bodies:
 *
 *   {field:ID}      one answer ({field:ID:part} for a name/address part)
 *   {all_fields}    every answer, as a table in HTML bodies
 *   {form_title} {entry_id} {entry_url} {date} {admin_email} {site_name}
 *   {source_url}    the page the form was embedded on
 *
 * Unknown tags are left as they are, so a typo shows up in the email.
 */
final class MergeTags {

  /**
   * @param array $context
   *   form (row with schema and title), entry (row with data), values
   *   (the answers; defaults to the entry's), extra (tag => text).
   */
  public static function replace(string $text, array $context, bool $html): string {
    $escape = $html ? static fn(string $s) => nl2br(htmlspecialchars($s, ENT_QUOTES, 'UTF-8')) : static fn(string $s) => $s;
    return self::replaceWith($text, $context, $escape, $html);
  }

  /**
   * For redirect URLs: each substituted answer is URL-encoded so it stays
   * inside its query parameter.
   */
  public static function replaceUrl(string $url, array $context): string {
    return self::replaceWith($url, $context, static fn(string $s) => rawurlencode($s), FALSE);
  }

  private static function replaceWith(string $text, array $context, callable $escape, bool $html): string {
    $schema = $context['schema'] ?? $context['form']['schema'] ?? ['fields' => []];
    $values = $context['values'] ?? $context['entry']['data'] ?? [];

    $simple = array_merge([
      'form_title' => (string) ($context['form']['title'] ?? ''),
      'entry_id' => (string) ($context['entry']['id'] ?? ''),
      'entry_url' => (string) ($context['entry_url'] ?? ''),
      'date' => (string) ($context['date'] ?? ''),
      'admin_email' => (string) ($context['admin_email'] ?? ''),
      'site_name' => (string) ($context['site_name'] ?? ''),
      'source_url' => (string) ($context['entry']['source_url'] ?? ''),
    ], $context['extra'] ?? []);

    $htmlTags = $context['extra_html'] ?? [];

    return preg_replace_callback('/\{([a-z_]+)(?::([a-z0-9_]+))?(?::([a-z0-9]+))?\}/', static function (array $m) use ($schema, $values, $simple, $escape, $html, $htmlTags): string {
      $tag = $m[1];
      if (array_key_exists($tag, $htmlTags) && !isset($m[2])) {
        // Built as HTML by this plugin; in text contexts only its words.
        return $html ? (string) $htmlTags[$tag] : $escape(trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['</td>', '</tr>', '</p>'], [' ', "\n", "\n"], (string) $htmlTags[$tag]))) ?? ''));
      }
      if ($tag === 'all_fields') {
        return $html ? self::allFields($schema, $values, TRUE) : $escape(self::allFields($schema, $values, FALSE));
      }
      if ($tag === 'field' && isset($m[2])) {
        $field = FormSchema::find($schema, $m[2]);
        if (!$field) {
          return $m[0];
        }
        $value = $values[$m[2]] ?? NULL;
        if (!empty($m[3]) && is_array($value)) {
          return $escape((string) ($value[$m[3]] ?? ''));
        }
        return $escape(Display::text($field, $value));
      }
      if (array_key_exists($tag, $simple) && !isset($m[2])) {
        return $escape((string) $simple[$tag]);
      }
      return $m[0];
    }, $text) ?? $text;
  }

  public static function allFields(array $schema, array $values, bool $html): string {
    $rows = Display::rows($schema, $values);
    if (!$html) {
      return implode("\n\n", array_map(static fn(array $r) => $r['label'] . ":\n" . $r['value'], $rows));
    }
    $out = '<table cellpadding="8" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:640px;font-family:sans-serif;font-size:14px">';
    foreach ($rows as $row) {
      $out .= '<tr><td style="border-bottom:1px solid #e5e5e5;vertical-align:top;width:35%;font-weight:600">' . htmlspecialchars($row['label'], ENT_QUOTES, 'UTF-8') . '</td>'
        . '<td style="border-bottom:1px solid #e5e5e5;vertical-align:top">' . nl2br(htmlspecialchars($row['value'], ENT_QUOTES, 'UTF-8')) . '</td></tr>';
    }
    return $out . '</table>';
  }

}
