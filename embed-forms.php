<?php
/**
 * Plugin Name: Embed Forms
 * Plugin URI: https://github.com/netzih/wp-embed-forms
 * Description: Build forms in WordPress and embed them on any website with one script tag. Payments through USAePay (requires the USAePay Payments plugin).
 * Version: 0.2.0-beta.2
 * Requires at least: 6.4
 * Requires PHP: 8.1
 * Author: Chabad of Richmond
 * License: GPL-2.0-or-later
 * Text Domain: embed-forms
 * Domain Path: /languages
 */

defined('ABSPATH') || exit;

// PSR-4 for EmbedForms\ without Composer, so the plugin runs from a plain zip.
spl_autoload_register(static function (string $class): void {
  if (strncmp($class, 'EmbedForms\\', 11) !== 0) {
    return;
  }
  $file = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, 11)) . '.php';
  if (is_file($file)) {
    require $file;
  }
});

\EmbedForms\Plugin::boot(__FILE__);

register_activation_hook(__FILE__, [\EmbedForms\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\EmbedForms\Plugin::class, 'deactivate']);
