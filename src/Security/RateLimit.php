<?php

namespace EmbedForms\Security;

/**
 * Fixed-window counters in transients, per client IP. Good enough to stop a
 * script hammering the submit endpoint; not a precise limiter.
 */
final class RateLimit {

  /**
   * Count one hit and say whether the caller is still under the limit.
   */
  public static function hit(string $bucket, string $ip, int $limit, int $window): bool {
    if ($limit <= 0 || $ip === '') {
      return TRUE;
    }
    $key = 'ef_rl_' . md5($bucket . '|' . $ip . '|' . intdiv(time(), $window));
    $count = (int) get_transient($key);
    if ($count >= $limit) {
      return FALSE;
    }
    set_transient($key, $count + 1, $window);
    return TRUE;
  }

  public static function exceeded(string $bucket, string $ip, int $limit, int $window): bool {
    if ($limit <= 0 || $ip === '') {
      return FALSE;
    }
    return (int) get_transient('ef_rl_' . md5($bucket . '|' . $ip . '|' . intdiv(time(), $window))) >= $limit;
  }

  public static function clientIp(): string {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    $ip = (string) apply_filters('embed_forms_client_ip', $ip);
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
  }

}
