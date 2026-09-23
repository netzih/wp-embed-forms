<?php

namespace EmbedForms\Admin;

use EmbedForms\Settings;

/**
 * Embed Forms > Settings: Turnstile keys, email sender, limits, Stripe
 * accounts.
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

        <h2><?php esc_html_e('USAePay', 'embed-forms'); ?></h2>
        <?php if (\EmbedForms\Payments\Processor::usaepayActive()) : ?>
          <p><?php printf(esc_html__('USAePay accounts, sandbox or live mode and Apple Pay are managed under %s. Each form chooses its account under Settings > Payments.', 'embed-forms'), '<a href="' . esc_url(admin_url('options-general.php?page=usaepay-payments')) . '">' . esc_html__('Settings > USAePay', 'embed-forms') . '</a>'); ?></p>
        <?php else : ?>
          <p><?php esc_html_e('Install and activate the USAePay Payments plugin for forms that take payments through USAePay.', 'embed-forms'); ?></p>
        <?php endif; ?>

        <?php $this->renderStripe($s, $name); ?>
        <?php submit_button(); ?>
      </form>
    </div>
    <?php
  }

  private function renderStripe(array $s, string $name): void {
    $rows = array_values(Settings::stripeAccounts());
    $rows[] = NULL;
    ?>
    <h2><?php esc_html_e('Stripe', 'embed-forms'); ?></h2>
    <p class="description"><?php esc_html_e('Stripe accounts forms can take payments through. Each form chooses its processor and account under Settings > Payments. Keys are under Developers > API keys in the Stripe dashboard; card details are entered in fields hosted by Stripe and never reach this site. Recurring payments are charged by this site from the card saved on a Stripe customer; nothing is scheduled in Stripe.', 'embed-forms'); ?></p>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Mode', 'embed-forms'); ?></th>
        <td>
          <label><input type="radio" name="<?php echo esc_attr($name); ?>[stripe_mode]" value="test" <?php checked($s['stripe_mode'] !== 'live'); ?>> <?php esc_html_e('Test (test keys, no real money)', 'embed-forms'); ?></label><br>
          <label><input type="radio" name="<?php echo esc_attr($name); ?>[stripe_mode]" value="live" <?php checked($s['stripe_mode'], 'live'); ?>> <?php esc_html_e('Live', 'embed-forms'); ?></label>
          <p class="description"><?php esc_html_e('For every Stripe account. Recurring payments are charged only while the mode they were made in is selected.', 'embed-forms'); ?></p>
        </td>
      </tr>
    </table>
    <?php foreach ($rows as $i => $account) :
      $field = static fn(string $key) => esc_attr($name . '[stripe_accounts][' . $i . '][' . $key . ']');
      ?>
      <table class="form-table" role="presentation" style="border-top:1px solid #c3c4c7">
        <tr>
          <th scope="row"><label for="ef-stripe-<?php echo (int) $i; ?>"><?php echo $account ? esc_html__('Account name', 'embed-forms') : esc_html__('Add a Stripe account', 'embed-forms'); ?></label></th>
          <td>
            <input type="text" id="ef-stripe-<?php echo (int) $i; ?>" name="<?php echo $field('label'); ?>" value="<?php echo esc_attr($account['label'] ?? ''); ?>" class="regular-text" placeholder="<?php echo $account ? '' : esc_attr__('e.g. Main Stripe account', 'embed-forms'); ?>">
            <?php if ($account) : ?>
              <input type="hidden" name="<?php echo $field('id'); ?>" value="<?php echo esc_attr($account['id']); ?>">
              <code style="margin-left:8px"><?php echo esc_html($account['id']); ?></code>
              <label style="margin-left:12px"><input type="checkbox" name="<?php echo $field('remove'); ?>" value="1"> <?php esc_html_e('Remove this account', 'embed-forms'); ?></label>
              <p class="description"><?php esc_html_e('Do not remove an account that still has active recurring payments or payments you may refund.', 'embed-forms'); ?></p>
            <?php else : ?>
              <p class="description"><?php esc_html_e('Fill in a name and the keys, then save. Leave blank to add nothing.', 'embed-forms'); ?></p>
            <?php endif; ?>
          </td>
        </tr>
        <?php foreach (['test' => __('Test keys', 'embed-forms'), 'live' => __('Live keys', 'embed-forms')] as $mode => $modeLabel) :
          $secret = (string) ($account[$mode . '_secret_key'] ?? '');
          ?>
          <tr>
            <th scope="row"><?php echo esc_html($modeLabel); ?></th>
            <td>
              <p><label><?php esc_html_e('Publishable key', 'embed-forms'); ?><br><input type="text" class="regular-text code" autocomplete="off" name="<?php echo $field($mode . '_publishable_key'); ?>" value="<?php echo esc_attr($account[$mode . '_publishable_key'] ?? ''); ?>" placeholder="<?php echo esc_attr('pk_' . $mode . '_…'); ?>"></label></p>
              <p><label><?php esc_html_e('Secret key', 'embed-forms'); ?><br><input type="password" class="regular-text code" autocomplete="new-password" name="<?php echo $field($mode . '_secret_key'); ?>" value="" placeholder="<?php echo $secret !== '' ? esc_attr__('Saved (leave empty to keep)', 'embed-forms') : esc_attr('sk_' . $mode . '_… / rk_' . $mode . '_…'); ?>"></label>
                <?php if ($secret !== '') : ?>
                  <label style="margin-left:8px"><input type="checkbox" name="<?php echo $field($mode . '_clear_secret'); ?>" value="1"> <?php esc_html_e('Remove the saved secret key', 'embed-forms'); ?></label>
                <?php endif; ?></p>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach;
  }

}
