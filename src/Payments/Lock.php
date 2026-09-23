<?php

namespace EmbedForms\Payments;

/**
 * A mutual-exclusion lock backed by a row in wp_options, for the renewal
 * worker and Stripe requests (USAePay requests are locked by USAePay
 * Payments' Reconcile). The INSERT is the test, because option_name is
 * unique; plain SQL keeps the option caches out of it. A dead holder keeps
 * the lock until its TTL passes, and release() deletes only its own handle.
 */
final class Lock {

  private const PREFIX = 'embed_forms_lock_';

  public static function acquire(string $name, int $ttlSeconds): ?string {
    global $wpdb;
    $option = self::option($name);
    $now = time();
    $handle = $now . ':' . bin2hex(random_bytes(8));
    $suppress = $wpdb->suppress_errors(TRUE);
    try {
      $inserted = $wpdb->query($wpdb->prepare("INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $option, $handle));
      if ($inserted === 1) {
        return $handle;
      }
      $current = (string) $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option));
      $heldSince = (int) strtok($current, ':');
      if ($current === '' || $heldSince <= 0 || $heldSince + $ttlSeconds >= $now) {
        return NULL;
      }
      // Stale: take it over, but only from the exact value seen.
      $taken = $wpdb->query($wpdb->prepare("UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $handle, $option, $current));
      return $taken === 1 ? $handle : NULL;
    }
    finally {
      $wpdb->suppress_errors($suppress);
    }
  }

  public static function release(string $name, ?string $handle): void {
    global $wpdb;
    if ($handle !== NULL && $handle !== '') {
      $wpdb->delete($wpdb->options, ['option_name' => self::option($name), 'option_value' => $handle]);
    }
  }

  private static function option(string $name): string {
    return self::PREFIX . preg_replace('/[^A-Za-z0-9_-]+/', '_', $name);
  }

}
