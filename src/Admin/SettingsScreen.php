<?php

namespace EmbedForms\Admin;

use EmbedForms\Plugin;
use EmbedForms\Settings;

/**
 * Embed Forms > Settings: Turnstile keys, email sender, limits.
 */
final class SettingsScreen {

  public function register(): void {
    add_action('admin_init', static function (): void {
      register_setting('embed_forms', Settings::OPTION, ['type' => 'array', 'sanitize_callback' => [Settings::class, 'sanitize']]);
    });
  }

  public function render(): void {
    Menu::requireCapability();
    $s = Settings::all();
    $name = Settings::OPTION;
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Embed Forms Settings', 'embed-forms'); ?></h1>
      <?php settings_errors(); ?>
      <form method="post" action="options.php">
        <?php settings_fields('embed_forms'); ?>
        <h2><?php esc_html_e('Cloudflare Turnstile', 'embed-forms'); ?></h2>
        <p class="description"><?php esc_html_e('Stops bots without puzzles and works inside embedded forms. Create a widget at dash.cloudflare.com > Turnstile, with this site\'s domain as hostname.', 'embed-forms'); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-ts-site"><?php esc_html_e('Site key', 'embed-forms'); ?></label></th>
            <td><input type="text" id="ef-ts-site" name="<?php echo esc_attr($name); ?>[turnstile_site_key]" value="<?php echo esc_attr($s['turnstile_site_key']); ?>" class="regular-text code"></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-ts-secret"><?php esc_html_e('Secret key', 'embed-forms'); ?></label></th>
            <td><input type="password" id="ef-ts-secret" name="<?php echo esc_attr($name); ?>[turnstile_secret_key]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo $s['turnstile_secret_key'] !== '' ? esc_attr__('Saved (leave empty to keep)', 'embed-forms') : ''; ?>">
              <?php if ($s['turnstile_secret_key'] !== '') : ?>
                <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[turnstile_clear_secret]" value="1"> <?php esc_html_e('Remove the saved secret', 'embed-forms'); ?></label>
              <?php endif; ?></td>
          </tr>
        </table>

        <h2><?php esc_html_e('Email', 'embed-forms'); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-from-name"><?php esc_html_e('From name', 'embed-forms'); ?></label></th>
            <td><input type="text" id="ef-from-name" name="<?php echo esc_attr($name); ?>[from_name]" value="<?php echo esc_attr($s['from_name']); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-from-email"><?php esc_html_e('From address', 'embed-forms'); ?></label></th>
            <td><input type="email" id="ef-from-email" name="<?php echo esc_attr($name); ?>[from_email]" value="<?php echo esc_attr($s['from_email']); ?>" class="regular-text">
              <p class="description"><?php esc_html_e('Leave empty to use the site\'s mail settings (Post SMTP or WordPress default). Use an address your mail service may send from.', 'embed-forms'); ?></p></td>
          </tr>
        </table>

        <h2><?php esc_html_e('Limits', 'embed-forms'); ?></h2>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-rate"><?php esc_html_e('Submissions per hour', 'embed-forms'); ?></label></th>
            <td><input type="number" min="0" id="ef-rate" name="<?php echo esc_attr($name); ?>[rate_limit]" value="<?php echo (int) $s['rate_limit']; ?>" class="small-text">
              <span class="description"><?php esc_html_e('per visitor IP address, across all forms. 0 for no limit.', 'embed-forms'); ?></span></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-failed"><?php esc_html_e('Declined payments per hour', 'embed-forms'); ?></label></th>
            <td><input type="number" min="0" id="ef-failed" name="<?php echo esc_attr($name); ?>[failed_payment_limit]" value="<?php echo (int) $s['failed_payment_limit']; ?>" class="small-text">
              <span class="description"><?php esc_html_e('per visitor IP address before payments are refused (stops card testing). 0 for no limit.', 'embed-forms'); ?></span></td>
          </tr>
        </table>

        <h2><?php esc_html_e('Payments', 'embed-forms'); ?></h2>
        <?php if (Plugin::paymentsAvailable()) : ?>
          <p><?php printf(esc_html__('USAePay credentials, sandbox or live mode and Apple Pay are managed under %s.', 'embed-forms'), '<a href="' . esc_url(admin_url('options-general.php?page=usaepay-payments')) . '">' . esc_html__('Settings > USAePay', 'embed-forms') . '</a>'); ?></p>
        <?php else : ?>
          <p><?php esc_html_e('Install and activate the USAePay Payments plugin to add payment fields to forms.', 'embed-forms'); ?></p>
        <?php endif; ?>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

}
