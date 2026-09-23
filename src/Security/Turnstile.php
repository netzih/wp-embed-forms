<?php

namespace EmbedForms\Security;

use EmbedForms\Settings;

/**
 * Cloudflare Turnstile: the widget script on the form page and the
 * server-side check of its response. Turnstile works inside third-party
 * iframes, which reCAPTCHA-style cookie checks do not.
 */
final class Turnstile {

  public const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';

  private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

  public static function enabledFor(array $settings): bool {
    return !empty($settings['turnstile']) && Settings::turnstileConfigured();
  }

  /**
   * @return string|null
   *   NULL when the response is valid, otherwise a payer-facing message.
   */
  public static function verify(string $response, string $ip): ?string {
    if ($response === '') {
      return __('Please complete the security check.', 'embed-forms');
    }
    $result = wp_remote_post(self::VERIFY_URL, [
      'timeout' => 10,
      'body' => array_filter([
        'secret' => Settings::get('turnstile_secret_key'),
        'response' => $response,
        'remoteip' => $ip,
      ]),
    ]);
    if (is_wp_error($result)) {
      return __('The security check could not be verified. Please try again.', 'embed-forms');
    }
    $body = json_decode((string) wp_remote_retrieve_body($result), TRUE);
    return !empty($body['success']) ? NULL : __('The security check failed. Please try again.', 'embed-forms');
  }

}
