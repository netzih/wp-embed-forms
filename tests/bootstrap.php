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
