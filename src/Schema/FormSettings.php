<?php

namespace EmbedForms\Schema;

/**
 * Per-form settings in canonical shape: confirmation, email notifications,
 * payer confirmation email and its sender, where the form may be embedded,
 * spam checks, and which payment processor and account take its payments.
 * Pure PHP so it can be unit tested.
 */
final class FormSettings {

  /**
   * Accent colours a form may use; form.css defines each as ef-accent-*.
   */
  public const ACCENTS = ['blue', 'teal', 'green', 'violet', 'rose', 'ink', 'custom'];

  /**
   * Fonts a form may use (Render\Design::FONTS has their families).
   */
  public const FONTS = ['geist', 'system', 'inter', 'dm-sans', 'nunito', 'poppins', 'lora', 'merriweather', 'baloo-2', 'playfair-display'];

  public const LAYOUTS = ['card', 'plain'];

  public const CORNERS = ['soft', 'round'];

  public const NAME_FALLBACKS = ['initial', 'anonymous'];

  public const PROCESSORS = ['usaepay', 'stripe'];

  public static function defaults(): array {
    return [
      'submit_label' => 'Submit',
      'show_title' => TRUE,
      'description' => '',
      'accent' => 'blue',
      // With accent 'custom': the brand colour, #rrggbb.
      'accent_color' => '',
      'font' => 'geist',
      // Empty uses the body font.
      'heading_font' => '',
      // 'plain' drops the card (border, shadow, padding) so a form embedded
      // in a designed page sits on that page's own card.
      'layout' => 'card',
      'corners' => 'soft',
      'confirmation' => [
        'type' => 'message',
        'message' => 'Thank you! Your submission has been received.',
        'url' => '',
      ],
      'notifications' => [
        [
          'enabled' => TRUE,
          'to' => '{admin_email}',
          'subject' => 'New submission: {form_title}',
          'body' => '{all_fields}',
          'reply_to' => '',
        ],
      ],
      'autoresponder' => [
        'enabled' => FALSE,
        'to_field' => '',
        'subject' => 'Thank you',
        'body' => "Thank you for your submission.\n\n{payment_summary}\n\n{all_fields}",
      ],
      // Sender of this form's emails; empty uses Embed Forms > Settings.
      'from_name' => '',
      'from_email' => '',
      'payment' => [
        'processor' => 'usaepay',
        // An account id from Settings > USAePay ('default' for the main one).
        'usaepay_account' => 'default',
        // An account id from Embed Forms > Settings > Stripe.
        'stripe_account' => '',
      ],
      'embed_domains' => [],
      'allow_direct' => TRUE,
      'turnstile' => TRUE,
      'renewal_receipts' => TRUE,
      'max_entries' => 0,
      'closed_message' => 'This form is no longer accepting submissions.',
      // A public donor list and running total, read by assets/campaign.js.
      'campaign' => [
        'enabled' => FALSE,
        'goal' => '',
        'show_amounts' => TRUE,
        'show_city' => FALSE,
        // For a donor who left the display name blank: 'initial' shows the
        // first name and last initial, 'anonymous' shows Anonymous.
        'name_fallback' => 'initial',
        // Raised elsewhere before or outside this form.
        'offset_amount' => '',
        'offset_donors' => 0,
      ],
    ];
  }

  public static function normalize(mixed $in): array {
    if (is_string($in)) {
      $in = json_decode($in, TRUE);
    }
    $in = is_array($in) ? $in : [];
    $d = self::defaults();

    $confirmation = is_array($in['confirmation'] ?? NULL) ? $in['confirmation'] : [];
    $autoresponder = is_array($in['autoresponder'] ?? NULL) ? $in['autoresponder'] : [];
    $payment = is_array($in['payment'] ?? NULL) ? $in['payment'] : [];
    $campaign = is_array($in['campaign'] ?? NULL) ? $in['campaign'] : [];
    $fromEmail = strtolower(self::line($in['from_email'] ?? ''));

    $notifications = [];
    foreach (is_array($in['notifications'] ?? NULL) ? $in['notifications'] : $d['notifications'] as $n) {
      if (!is_array($n)) {
        continue;
      }
      $notifications[] = [
        'enabled' => !empty($n['enabled']),
        'to' => self::line($n['to'] ?? ''),
        'subject' => self::line($n['subject'] ?? ''),
        'body' => (string) ($n['body'] ?? ''),
        'reply_to' => self::line($n['reply_to'] ?? ''),
      ];
    }

    return [
      'submit_label' => self::line($in['submit_label'] ?? $d['submit_label']) ?: $d['submit_label'],
      'show_title' => array_key_exists('show_title', $in) ? !empty($in['show_title']) : $d['show_title'],
      'description' => (string) ($in['description'] ?? ''),
      'accent' => in_array($in['accent'] ?? NULL, self::ACCENTS, TRUE) ? $in['accent'] : $d['accent'],
      'accent_color' => self::color($in['accent_color'] ?? ''),
      'font' => in_array($in['font'] ?? NULL, self::FONTS, TRUE) ? $in['font'] : $d['font'],
      'heading_font' => in_array($in['heading_font'] ?? NULL, self::FONTS, TRUE) ? $in['heading_font'] : '',
      'layout' => in_array($in['layout'] ?? NULL, self::LAYOUTS, TRUE) ? $in['layout'] : $d['layout'],
      'corners' => in_array($in['corners'] ?? NULL, self::CORNERS, TRUE) ? $in['corners'] : $d['corners'],
      'confirmation' => [
        'type' => ($confirmation['type'] ?? 'message') === 'redirect' ? 'redirect' : 'message',
        'message' => (string) ($confirmation['message'] ?? $d['confirmation']['message']),
        'url' => self::url($confirmation['url'] ?? ''),
      ],
      'notifications' => $notifications,
      'autoresponder' => [
        'enabled' => !empty($autoresponder['enabled']),
        'to_field' => preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($autoresponder['to_field'] ?? ''))),
        'subject' => self::line($autoresponder['subject'] ?? $d['autoresponder']['subject']),
        'body' => (string) ($autoresponder['body'] ?? $d['autoresponder']['body']),
      ],
      'from_name' => self::line($in['from_name'] ?? ''),
      'from_email' => filter_var($fromEmail, FILTER_VALIDATE_EMAIL) ? $fromEmail : '',
      'payment' => [
        'processor' => in_array($payment['processor'] ?? NULL, self::PROCESSORS, TRUE) ? $payment['processor'] : $d['payment']['processor'],
        'usaepay_account' => self::accountId($payment['usaepay_account'] ?? '') ?: $d['payment']['usaepay_account'],
        'stripe_account' => self::accountId($payment['stripe_account'] ?? ''),
      ],
      'embed_domains' => self::domains($in['embed_domains'] ?? []),
      'allow_direct' => array_key_exists('allow_direct', $in) ? !empty($in['allow_direct']) : $d['allow_direct'],
      'turnstile' => array_key_exists('turnstile', $in) ? !empty($in['turnstile']) : $d['turnstile'],
      'renewal_receipts' => array_key_exists('renewal_receipts', $in) ? !empty($in['renewal_receipts']) : $d['renewal_receipts'],
      'max_entries' => max(0, (int) ($in['max_entries'] ?? 0)),
      'closed_message' => (string) ($in['closed_message'] ?? $d['closed_message']),
      'campaign' => [
        'enabled' => !empty($campaign['enabled']),
        'goal' => self::money($campaign['goal'] ?? ''),
        'show_amounts' => array_key_exists('show_amounts', $campaign) ? !empty($campaign['show_amounts']) : $d['campaign']['show_amounts'],
        'show_city' => !empty($campaign['show_city']),
        'name_fallback' => in_array($campaign['name_fallback'] ?? NULL, self::NAME_FALLBACKS, TRUE) ? $campaign['name_fallback'] : $d['campaign']['name_fallback'],
        'offset_amount' => self::money($campaign['offset_amount'] ?? ''),
        'offset_donors' => max(0, (int) ($campaign['offset_donors'] ?? 0)),
      ],
    ];
  }

  /**
   * A #rrggbb colour (also accepts #rgb), lower case, or ''.
   */
  public static function color(mixed $value): string {
    $value = is_scalar($value) ? strtolower(trim((string) $value)) : '';
    if (preg_match('/^#?([0-9a-f])([0-9a-f])([0-9a-f])$/', $value, $m)) {
      return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
    }
    return preg_match('/^#?([0-9a-f]{6})$/', $value, $m) ? '#' . $m[1] : '';
  }

  private static function money(mixed $value): string {
    $amount = \EmbedForms\Payments\Money::normalize(is_scalar($value) ? str_replace([',', '$', ' '], '', (string) $value) : '');
    return $amount === NULL || $amount === '0.00' ? '' : $amount;
  }

  /**
   * Host patterns the form may be framed by: "example.org" or
   * "*.example.org", optionally with a port. Anything else is dropped.
   *
   * @return string[]
   */
  public static function domains(mixed $in): array {
    if (is_string($in)) {
      $in = preg_split('/[\s,]+/', $in) ?: [];
    }
    $out = [];
    foreach (is_array($in) ? $in : [] as $domain) {
      $domain = strtolower(trim((string) $domain));
      $domain = preg_replace('#^https?://#', '', $domain);
      $domain = rtrim((string) $domain, '/');
      // A real hostname has a dot; localhost is allowed for testing.
      $host = preg_replace('/:\d+$/', '', (string) $domain);
      if (!str_contains($host, '.') && $host !== 'localhost') {
        continue;
      }
      if (preg_match('/^(\*\.)?([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?(:\d{1,5})?$/', $domain)) {
        $out[$domain] = TRUE;
      }
    }
    return array_keys($out);
  }

  private static function accountId(mixed $value): string {
    return is_scalar($value) ? substr(preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)) ?? '', 0, 64) : '';
  }

  private static function line(mixed $value): string {
    return is_scalar($value) ? trim(preg_replace('/[\r\n\t]+/', ' ', (string) $value) ?? '') : '';
  }

  private static function url(mixed $value): string {
    $value = is_scalar($value) ? trim((string) $value) : '';
    return preg_match('#^https?://[^\s]+$#i', $value) ? $value : '';
  }

}
