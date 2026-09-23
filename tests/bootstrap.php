<?php

require_once dirname(__DIR__) . '/vendor/autoload.php';

// Minimal WordPress shims for the pure classes under test.
if (!function_exists('__')) {
  function __(string $text, string $domain = 'default'): string {
    return $text;
  }
}
if (!function_exists('wp_json_encode')) {
  function wp_json_encode(mixed $data, int $flags = 0): string|false {
    return json_encode($data, $flags);
  }
}
if (!defined('DAY_IN_SECONDS')) {
  define('DAY_IN_SECONDS', 86400);
}
if (!defined('HOUR_IN_SECONDS')) {
  define('HOUR_IN_SECONDS', 3600);
}
if (!function_exists('sanitize_text_field')) {
  function sanitize_text_field(string $text): string {
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags($text)));
  }
}
if (!function_exists('sanitize_key')) {
  function sanitize_key(string $key): string {
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower($key));
  }
}
if (!function_exists('sanitize_email')) {
  function sanitize_email(string $email): string {
    return (string) filter_var(trim($email), FILTER_SANITIZE_EMAIL);
  }
}
// Options and filters in memory, so settings and the Stripe transport can
// be driven from tests.
$GLOBALS['ef_test_options'] = [];
$GLOBALS['ef_test_filters'] = [];
if (!function_exists('get_option')) {
  function get_option(string $name, mixed $default = FALSE): mixed {
    return $GLOBALS['ef_test_options'][$name] ?? $default;
  }
}
if (!function_exists('home_url')) {
  function home_url(): string {
    return 'https://example.org';
  }
}
if (!function_exists('add_filter')) {
  function add_filter(string $hook, callable $callback): void {
    $GLOBALS['ef_test_filters'][$hook][] = $callback;
  }
}
if (!function_exists('apply_filters')) {
  function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    foreach ($GLOBALS['ef_test_filters'][$hook] ?? [] as $callback) {
      $value = $callback($value, ...$args);
    }
    return $value;
  }
}

/**
 * Just enough of wpdb for Payments\Lock: option rows in memory.
 */
final class EfTestWpdb {
  public string $options = 'wp_options';
  public array $rows = [];
  public function suppress_errors(bool $on): bool {
    return FALSE;
  }
  public function prepare(string $sql, mixed ...$args): array {
    return [$sql, $args];
  }
  public function query(array $q): int {
    [$sql, $args] = $q;
    if (str_starts_with($sql, 'INSERT')) {
      if (isset($this->rows[$args[0]])) {
        return 0;
      }
      $this->rows[$args[0]] = $args[1];
      return 1;
    }
    if (($this->rows[$args[1]] ?? NULL) === $args[2]) {
      $this->rows[$args[1]] = $args[0];
      return 1;
    }
    return 0;
  }
  public function get_var(array $q): ?string {
    return $this->rows[$q[1][0]] ?? NULL;
  }
  public function delete(string $table, array $where): void {
    if (($this->rows[$where['option_name']] ?? NULL) === $where['option_value']) {
      unset($this->rows[$where['option_name']]);
    }
  }
}
$GLOBALS['wpdb'] = new EfTestWpdb();
