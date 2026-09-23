<?php

namespace EmbedForms\Admin;

use EmbedForms\Plugin;

/**
 * Embed Forms admin menu: Forms (list and editor), Entries, Settings.
 */
final class Menu {

  public const PAGE_FORMS = 'embed-forms';

  public const PAGE_ENTRIES = 'embed-forms-entries';

  public const PAGE_SETTINGS = 'embed-forms-settings';

  public function register(): void {
    add_action('admin_menu', [$this, 'menu']);
    add_action('admin_enqueue_scripts', [$this, 'assets']);
    (new FormsScreen())->registerActions();
    (new EntriesScreen())->registerActions();
    (new SettingsScreen())->register();
  }

  public function menu(): void {
    $forms = new FormsScreen();
    $hook = add_menu_page(__('Embed Forms', 'embed-forms'), __('Embed Forms', 'embed-forms'), Plugin::CAPABILITY, self::PAGE_FORMS, [$forms, 'render'], 'dashicons-feedback', 58);
    add_action('load-' . $hook, [$forms, 'load']);
    add_submenu_page(self::PAGE_FORMS, __('Forms', 'embed-forms'), __('All Forms', 'embed-forms'), Plugin::CAPABILITY, self::PAGE_FORMS, [$forms, 'render']);
    $entries = new EntriesScreen();
    $entriesHook = add_submenu_page(self::PAGE_FORMS, __('Entries', 'embed-forms'), __('Entries', 'embed-forms'), Plugin::CAPABILITY, self::PAGE_ENTRIES, [$entries, 'render']);
    add_action('load-' . $entriesHook, [$entries, 'load']);
    add_submenu_page(self::PAGE_FORMS, __('Embed Forms Settings', 'embed-forms'), __('Settings', 'embed-forms'), Plugin::CAPABILITY, self::PAGE_SETTINGS, [new SettingsScreen(), 'render']);
  }

  public function assets(string $hook): void {
    if (!str_contains($hook, 'embed-forms')) {
      return;
    }
    $plugin = Plugin::instance();
    wp_enqueue_style('embed-forms-admin', $plugin->url('assets/admin.css'), [], $plugin->assetVersion('assets/admin.css'));
    wp_enqueue_script('embed-forms-admin', $plugin->url('assets/admin.js'), [], $plugin->assetVersion('assets/admin.js'), TRUE);
    wp_localize_script('embed-forms-admin', 'EmbedFormsAdmin', [
      'copied' => __('Copied', 'embed-forms'),
      'confirmDelete' => __('Delete this permanently? This cannot be undone.', 'embed-forms'),
    ]);
  }

  public static function formsUrl(array $args = []): string {
    return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_FORMS));
  }

  public static function entriesUrl(array $args = []): string {
    return add_query_arg($args, admin_url('admin.php?page=' . self::PAGE_ENTRIES));
  }

  public static function actionUrl(string $action, array $args = []): string {
    return wp_nonce_url(add_query_arg(array_merge(['action' => $action], $args), admin_url('admin-post.php')), $action);
  }

  /**
   * One-time notices carried across a redirect.
   */
  public static function flash(string $message, string $type = 'success'): void {
    set_transient('embed_forms_notice_' . get_current_user_id(), ['message' => $message, 'type' => $type], 120);
  }

  public static function renderFlash(): void {
    $key = 'embed_forms_notice_' . get_current_user_id();
    $notice = get_transient($key);
    if (is_array($notice)) {
      delete_transient($key);
      printf('<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr($notice['type']), esc_html($notice['message']));
    }
  }

  public static function requireCapability(): void {
    if (!current_user_can(Plugin::CAPABILITY)) {
      wp_die(esc_html__('You do not have permission to do that.', 'embed-forms'), 403);
    }
  }

}
