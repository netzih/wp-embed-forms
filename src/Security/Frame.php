<?php

namespace EmbedForms\Security;

/**
 * Framing policy of the public form page: which parent pages may show the
 * form in an iframe.
 */
final class Frame {

  /**
   * The Content-Security-Policy value for a form's allowed domains; an empty
   * list lets any site embed it.
   */
  public static function policy(array $domains): string {
    if (!$domains) {
      return 'frame-ancestors *';
    }
    $sources = ["'self'"];
    foreach ($domains as $domain) {
      $sources[] = 'https://' . $domain;
      $sources[] = 'http://' . $domain;
    }
    return 'frame-ancestors ' . implode(' ', $sources);
  }

  /**
   * Whether a parent page URL is on one of the allowed domains (an empty
   * list allows all). Used to decide which origin the page talks to.
   */
  public static function allows(array $domains, string $url): bool {
    if (!$domains) {
      return TRUE;
    }
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $port = parse_url($url, PHP_URL_PORT);
    if ($host === '') {
      return FALSE;
    }
    foreach ($domains as $domain) {
      [$pattern, $patternPort] = array_pad(explode(':', $domain, 2), 2, NULL);
      if ($patternPort !== NULL && (int) $patternPort !== (int) $port) {
        continue;
      }
      if (str_starts_with($pattern, '*.')) {
        $base = substr($pattern, 2);
        if ($host === $base || str_ends_with($host, '.' . $base)) {
          return TRUE;
        }
      }
      elseif ($host === $pattern) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
