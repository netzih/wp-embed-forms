<?php

namespace EmbedForms\Notifications;

use EmbedForms\Settings;

/**
 * Sends a form's notifications and payer confirmation for a new entry
 * through wp_mail (so Post SMTP or any other mailer plugin applies).
 */
final class Mailer {

  public static function entrySubmitted(array $form, array $entry, array $extra = [], array $extraHtml = []): void {
    $context = self::context($form, $entry, $extra, $extraHtml);
    $settings = $form['settings'];

    foreach ($settings['notifications'] as $notification) {
      if (empty($notification['enabled'])) {
        continue;
      }
      $to = self::addresses(MergeTags::replace($notification['to'], $context, FALSE));
      if (!$to) {
        continue;
      }
      $replyTo = self::addresses(MergeTags::replace($notification['reply_to'], $context, FALSE));
      self::send($to, MergeTags::replace($notification['subject'], $context, FALSE), MergeTags::replace(self::bodyHtml($notification['body']), $context, TRUE), $replyTo[0] ?? '');
    }

    $auto = $settings['autoresponder'];
    if (!empty($auto['enabled']) && $auto['to_field'] !== '') {
      $email = (string) ($entry['data'][$auto['to_field']] ?? '');
      if (is_email($email)) {
        self::send([$email], MergeTags::replace($auto['subject'], $context, FALSE), MergeTags::replace(self::bodyHtml($auto['body']), $context, TRUE));
      }
    }
  }

  public static function context(array $form, array $entry, array $extra = [], array $extraHtml = []): array {
    return [
      'form' => $form,
      'entry' => $entry,
      'schema' => \EmbedForms\Db\Forms::schemaAt($form, (int) $entry['form_version']),
      'entry_url' => admin_url('admin.php?page=embed-forms-entries&entry=' . (int) $entry['id']),
      'date' => wp_date(get_option('date_format') . ' ' . get_option('time_format')),
      'admin_email' => (string) get_option('admin_email'),
      'site_name' => wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
      'extra' => $extra,
      'extra_html' => $extraHtml,
    ];
  }

  /**
   * @param string[] $to
   */
  public static function send(array $to, string $subject, string $html, string $replyTo = ''): bool {
    $headers = ['Content-Type: text/html; charset=UTF-8'];
    $fromEmail = (string) Settings::get('from_email');
    if ($fromEmail !== '') {
      $fromName = (string) Settings::get('from_name');
      $headers[] = 'From: ' . ($fromName !== '' ? self::headerText($fromName) . ' <' . $fromEmail . '>' : $fromEmail);
    }
    if ($replyTo !== '') {
      $headers[] = 'Reply-To: ' . $replyTo;
    }
    $body = '<!doctype html><html><body style="font-family:sans-serif;font-size:14px;line-height:1.5;color:#1d2327">' . $html . '</body></html>';
    return wp_mail($to, self::headerText($subject), $body, $headers);
  }

  /**
   * Bodies are written as text with optional HTML: plain text gets its line
   * breaks kept, anything that already has block markup is left alone.
   */
  private static function bodyHtml(string $body): string {
    if (preg_match('/<(p|div|table|br|ul|ol|h[1-6])\b/i', $body)) {
      return $body;
    }
    // Keep {all_fields} out of nl2br: it expands to a table.
    return implode('{all_fields}', array_map(static fn($part) => nl2br($part, FALSE), explode('{all_fields}', $body)));
  }

  /**
   * @return string[]
   */
  private static function addresses(string $list): array {
    $out = [];
    foreach (preg_split('/[\s,;]+/', $list) ?: [] as $address) {
      $address = sanitize_email($address);
      if ($address !== '' && is_email($address)) {
        $out[] = $address;
      }
    }
    return array_values(array_unique($out));
  }

  private static function headerText(string $text): string {
    return trim(preg_replace('/[\r\n]+/', ' ', wp_strip_all_tags($text)) ?? '');
  }

}
