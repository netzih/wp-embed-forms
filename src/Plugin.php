<?php

namespace EmbedForms;

/**
 * Bootstrap: the public form page and submit endpoint, the admin screens,
 * and the [embed_form] shortcode. Payments go through the USAePay Payments
 * plugin or Stripe, chosen per form.
 */
final class Plugin {

  public const VERSION = '0.3.0-beta.1';

  public const CAPABILITY = 'manage_options';

  private static ?Plugin $instance = NULL;

  private string $file;

  public static function boot(string $file): void {
    if (self::$instance) {
      return;
    }
    self::$instance = new self($file);
    self::$instance->hooks();
  }

  public static function instance(): Plugin {
    if (!self::$instance) {
      throw new \LogicException('Embed Forms has not been booted.');
    }
    return self::$instance;
  }

  private function __construct(string $file) {
    $this->file = $file;
  }

  private function hooks(): void {
    add_action('init', [$this, 'loadTextdomain']);
    add_action('init', [Db\Schema::class, 'maybeUpgrade'], 1);
    (new Render\PublicPage())->register();
    add_action('rest_api_init', static function (): void {
      (new Rest\SubmitController())->register();
    });
    add_shortcode('embed_form', [Embed::class, 'shortcode']);
    (new Payments\Module())->register();
    if (is_admin()) {
      (new Admin\Menu())->register();
    }
  }

  public static function activate(): void {
    Db\Schema::install();
    (new Render\PublicPage())->rewrite();
    flush_rewrite_rules();
  }

  public static function deactivate(): void {
    wp_clear_scheduled_hook(Payments\Renewals::CRON);
    flush_rewrite_rules();
  }

  /**
   * Whether any processor can take payments here: USAePay Payments is
   * active or a Stripe account is set up.
   */
  public static function paymentsAvailable(): bool {
    return class_exists('\Usaepay\WordPress\Plugin') || Settings::stripeAccounts() !== [];
  }

  public function loadTextdomain(): void {
    load_plugin_textdomain('embed-forms', FALSE, dirname(plugin_basename($this->file)) . '/languages');
  }

  public function file(): string {
    return $this->file;
  }

  public function url(string $path = ''): string {
    return plugins_url(ltrim($path, '/'), $this->file);
  }

  public function path(string $path = ''): string {
    return plugin_dir_path($this->file) . ltrim($path, '/');
  }

  /**
   * File mtime in development so edits bypass caches, the version otherwise.
   */
  public function assetVersion(string $relativePath): string {
    $file = $this->path($relativePath);
    if ((defined('WP_DEBUG') && WP_DEBUG) && is_file($file)) {
      return (string) filemtime($file);
    }
    return self::VERSION;
  }

}
