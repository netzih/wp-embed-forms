<?php

namespace EmbedForms\Submit;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Notifications\Mailer;
use EmbedForms\Notifications\MergeTags;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;
use EmbedForms\Security\RateLimit;
use EmbedForms\Security\Token;
use EmbedForms\Security\Turnstile;
use EmbedForms\Settings;

/**
 * One submission from the public form: checks that it may be accepted,
 * validates it against the form, stores the entry and sends the emails.
 */
final class SubmissionHandler {

  /**
   * @param array $request
   *   token, values (field id => answer), hp (honeypot), turnstile,
   *   source_url.
   *
   * @return array{status: int, body: array}
   */
  public function handle(array $form, array $request, bool $canPreview = FALSE): array {
    if ($form['status'] !== 'live' && !($form['status'] === 'draft' && $canPreview)) {
      return self::fail(403, $form['status'] === 'closed' ? $form['settings']['closed_message'] : __('This form is not available.', 'embed-forms'));
    }
    if (self::isFull($form)) {
      return self::fail(403, $form['settings']['closed_message']);
    }

    // The same origin rules as the page: a submission that says it came
    // from a website outside the form's list is refused.
    $domains = $form['settings']['embed_domains'];
    $source = (string) ($request['source_url'] ?? '');
    if (!$canPreview && $domains && $source !== '' && !\EmbedForms\Security\Frame::allows($domains, $source) && !self::isOwnPage($source)) {
      return self::fail(403, __('This form cannot be submitted from this website.', 'embed-forms'));
    }

    $ip = RateLimit::clientIp();
    if (!RateLimit::hit('submit', $ip, (int) Settings::get('rate_limit'), HOUR_IN_SECONDS)) {
      return self::fail(429, __('Too many submissions from your network. Please try again later.', 'embed-forms'));
    }

    // Bots fill every input; people never see this one. Answer as if it
    // worked so the bot has nothing to learn from.
    if (trim((string) ($request['hp'] ?? '')) !== '') {
      return ['status' => 200, 'body' => ['ok' => TRUE, 'confirmation' => ['type' => 'message', 'message' => wp_kses_post($form['settings']['confirmation']['message'])]]];
    }

    $tokenProblem = Token::check((string) ($request['token'] ?? ''), (int) $form['id'], Token::secret());
    if ($tokenProblem === 'too_fast') {
      return self::fail(400, __('That was quick! Please take a moment and submit again.', 'embed-forms'));
    }
    if ($tokenProblem !== NULL) {
      return self::fail(400, __('This form has expired. Please reload the page and submit again.', 'embed-forms'), [], 'reload');
    }

    if (Turnstile::enabledFor($form['settings'])) {
      $problem = Turnstile::verify((string) ($request['turnstile'] ?? ''), $ip);
      if ($problem !== NULL) {
        return self::fail(400, $problem, [], 'turnstile');
      }
    }

    $values = is_array($request['values'] ?? NULL) ? $request['values'] : [];
    $result = Validator::validate($form['schema'], $values);
    if ($result['errors']) {
      return self::fail(422, __('Please correct the highlighted fields.', 'embed-forms'), $result['errors']);
    }

    $sourceUrl = esc_url_raw((string) ($request['source_url'] ?? ''));
    $entryId = Entries::create($form, $result['values'], [
      'status' => 'submitted',
      'email' => self::payerEmail($form, $result['values']),
      'ip' => $ip,
      'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash((string) $_SERVER['HTTP_USER_AGENT'])) : '',
      'source_url' => $sourceUrl,
    ]);
    if ($entryId <= 0) {
      return self::fail(500, __('Your submission could not be saved. Please try again.', 'embed-forms'));
    }
    $entry = Entries::find($entryId);

    /**
     * A new entry was stored. Connectors (CRM, webhooks) hook here.
     */
    do_action('embed_forms_entry_submitted', $entry, $form);

    try {
      Mailer::entrySubmitted($form, $entry);
    }
    catch (\Throwable $e) {
      error_log('[embed-forms] notification failed for entry ' . $entryId . ': ' . $e->getMessage());
    }

    return ['status' => 200, 'body' => ['ok' => TRUE, 'entry' => $entryId, 'confirmation' => self::confirmation($form, $entry)]];
  }

  public static function confirmation(array $form, array $entry): array {
    $confirmation = $form['settings']['confirmation'];
    $context = Mailer::context($form, $entry);
    if ($confirmation['type'] === 'redirect' && $confirmation['url'] !== '') {
      $url = MergeTags::replaceUrl($confirmation['url'], $context);
      return ['type' => 'redirect', 'url' => esc_url_raw($url)];
    }
    return ['type' => 'message', 'message' => wp_kses_post(MergeTags::replace($confirmation['message'], $context, TRUE))];
  }

  private static function isOwnPage(string $url): bool {
    return strtolower((string) parse_url($url, PHP_URL_HOST)) === strtolower((string) parse_url(home_url(), PHP_URL_HOST));
  }

  public static function isFull(array $form): bool {
    $max = (int) $form['settings']['max_entries'];
    return $max > 0 && Entries::countActive((int) $form['id']) >= $max;
  }

  /**
   * The first email answer, or the one the confirmation email goes to.
   */
  public static function payerEmail(array $form, array $values): string {
    $preferred = $form['settings']['autoresponder']['to_field'] ?? '';
    if ($preferred !== '' && is_email((string) ($values[$preferred] ?? ''))) {
      return (string) $values[$preferred];
    }
    foreach (FormSchema::inputs($form['schema']) as $id => $field) {
      if ($field['type'] === 'email' && is_email((string) ($values[$id] ?? ''))) {
        return (string) $values[$id];
      }
    }
    return '';
  }

  private static function fail(int $status, string $message, array $errors = [], string $code = ''): array {
    $body = ['ok' => FALSE, 'message' => $message];
    if ($errors) {
      $body['errors'] = $errors;
    }
    if ($code !== '') {
      $body['code'] = $code;
    }
    return ['status' => $status, 'body' => $body];
  }

}
