<?php

namespace EmbedForms\Admin;

use EmbedForms\Settings;
use EmbedForms\Sheets\Client;

/**
 * Embed Forms > Settings, in sections: spam protection (Turnstile), email
 * sender, limits, payments (USAePay note, Stripe accounts) and Google
 * Sheets (the service account, with a setup guide).
 */
final class SettingsScreen {

  public function register(): void {
    add_action('admin_init', static function (): void {
      register_setting('embed_forms', Settings::OPTION, ['type' => 'array', 'sanitize_callback' => [Settings::class, 'sanitize']]);
    });
    add_action('admin_post_embed_forms_google_check', [$this, 'checkGoogle']);
  }

  public function render(): void {
    Menu::requireCapability();
    $s = Settings::all();
    $name = Settings::OPTION;
    $stripeCount = count(Settings::stripeAccounts());
    ?>
    <div class="wrap">
      <h1><?php esc_html_e('Embed Forms Settings', 'embed-forms'); ?></h1>
      <?php settings_errors(); ?>
      <?php Menu::renderFlash(); ?>
      <form method="post" action="options.php">
        <?php settings_fields('embed_forms'); ?>
        <?php Sections::start(__('Settings', 'embed-forms')); ?>

        <?php Sections::open('spam', __('Spam protection', 'embed-forms'), Settings::turnstileConfigured() ? __('Turnstile set up', 'embed-forms') : __('Not set up', 'embed-forms'), '', Settings::turnstileConfigured() ? 'good' : ''); ?>
        <p class="ef-section-intro"><?php esc_html_e('Cloudflare Turnstile stops bots without puzzles, also inside embedded forms. Create a widget at dash.cloudflare.com > Turnstile with this site\'s domain, then paste its keys. Each form can turn the check on or off.', 'embed-forms'); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-ts-site"><?php esc_html_e('Site key', 'embed-forms'); ?></label></th>
            <td><input type="text" id="ef-ts-site" name="<?php echo esc_attr($name); ?>[turnstile_site_key]" value="<?php echo esc_attr($s['turnstile_site_key']); ?>" class="regular-text code"></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-ts-secret"><?php esc_html_e('Secret key', 'embed-forms'); ?></label></th>
            <td><input type="password" id="ef-ts-secret" name="<?php echo esc_attr($name); ?>[turnstile_secret_key]" value="" class="regular-text code" autocomplete="new-password" placeholder="<?php echo $s['turnstile_secret_key'] !== '' ? esc_attr__('Saved (leave empty to keep)', 'embed-forms') : ''; ?>">
              <?php if ($s['turnstile_secret_key'] !== '') : ?>
                <br><label><input type="checkbox" name="<?php echo esc_attr($name); ?>[turnstile_clear_secret]" value="1"> <?php esc_html_e('Remove the saved secret', 'embed-forms'); ?></label>
              <?php endif; ?></td>
          </tr>
        </table>
        <?php submit_button(__('Save changes', 'embed-forms'), 'primary', 'submit-spam'); ?>
        <?php Sections::close(); ?>

        <?php Sections::open('email', __('Email sender', 'embed-forms'), $s['from_email'] !== '' ? $s['from_email'] : __('Site default', 'embed-forms')); ?>
        <p class="ef-section-intro"><?php esc_html_e('Who emails from your forms come from, unless a form sets its own sender.', 'embed-forms'); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-from-name"><?php esc_html_e('From name', 'embed-forms'); ?></label></th>
            <td><input type="text" id="ef-from-name" name="<?php echo esc_attr($name); ?>[from_name]" value="<?php echo esc_attr($s['from_name']); ?>" class="regular-text"></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-from-email"><?php esc_html_e('From address', 'embed-forms'); ?></label></th>
            <td><input type="email" id="ef-from-email" name="<?php echo esc_attr($name); ?>[from_email]" value="<?php echo esc_attr($s['from_email']); ?>" class="regular-text">
              <p class="description"><?php esc_html_e('Empty uses the site\'s mail settings (Post SMTP or WordPress). Use an address your mail service may send from.', 'embed-forms'); ?></p></td>
          </tr>
        </table>
        <?php submit_button(__('Save changes', 'embed-forms'), 'primary', 'submit-email'); ?>
        <?php Sections::close(); ?>

        <?php Sections::open('limits', __('Limits', 'embed-forms'), sprintf(__('%d submissions an hour', 'embed-forms'), (int) $s['rate_limit'])); ?>
        <p class="ef-section-intro"><?php esc_html_e('Per visitor IP address, across all forms. 0 turns a limit off.', 'embed-forms'); ?></p>
        <table class="form-table" role="presentation">
          <tr>
            <th scope="row"><label for="ef-rate"><?php esc_html_e('Submissions', 'embed-forms'); ?></label></th>
            <td><input type="number" min="0" id="ef-rate" name="<?php echo esc_attr($name); ?>[rate_limit]" value="<?php echo (int) $s['rate_limit']; ?>" class="small-text"> <?php esc_html_e('an hour', 'embed-forms'); ?></td>
          </tr>
          <tr>
            <th scope="row"><label for="ef-failed"><?php esc_html_e('Declined payments', 'embed-forms'); ?></label></th>
            <td><input type="number" min="0" id="ef-failed" name="<?php echo esc_attr($name); ?>[failed_payment_limit]" value="<?php echo (int) $s['failed_payment_limit']; ?>" class="small-text"> <?php esc_html_e('an hour, then payments are refused (stops card testing)', 'embed-forms'); ?></td>
          </tr>
        </table>
        <?php submit_button(__('Save changes', 'embed-forms'), 'primary', 'submit-limits'); ?>
        <?php Sections::close(); ?>

        <?php Sections::open('payments', __('Payments', 'embed-forms'), sprintf(_n('Stripe %1$s, %2$d account', 'Stripe %1$s, %2$d accounts', $stripeCount, 'embed-forms'), $s['stripe_mode'] === 'live' ? __('live', 'embed-forms') : __('test mode', 'embed-forms'), $stripeCount)); ?>
        <?php $this->renderPayments($s, $name); ?>
        <?php submit_button(__('Save changes', 'embed-forms'), 'primary', 'submit-payments'); ?>
        <?php Sections::close(); ?>

        <?php $this->renderGoogle($name); ?>

        <?php Sections::end(); ?>
      </form>
    </div>
    <?php
  }

  private function renderPayments(array $s, string $name): void {
    $rows = array_values(Settings::stripeAccounts());
    $rows[] = NULL;
    ?>
    <h3><?php esc_html_e('USAePay', 'embed-forms'); ?></h3>
    <?php if (\EmbedForms\Payments\Processor::usaepayActive()) : ?>
      <p><?php printf(esc_html__('Accounts, sandbox or live mode and Apple Pay are managed under %s.', 'embed-forms'), '<a href="' . esc_url(admin_url('options-general.php?page=usaepay-payments')) . '">' . esc_html__('Settings > USAePay', 'embed-forms') . '</a>'); ?></p>
    <?php else : ?>
      <p><?php esc_html_e('Install and activate the USAePay Payments plugin to take payments through USAePay.', 'embed-forms'); ?></p>
    <?php endif; ?>

    <h3><?php esc_html_e('Stripe', 'embed-forms'); ?></h3>
    <p class="ef-section-intro"><?php esc_html_e('Keys are under Developers > API keys in the Stripe dashboard. Card details go straight to Stripe and never reach this site. Each form picks its account under Settings > Payments.', 'embed-forms'); ?></p>
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
      <table class="form-table" role="presentation" style="border-top:1px solid #f0f0f1">
        <tr>
          <th scope="row"><label for="ef-stripe-<?php echo (int) $i; ?>"><?php echo $account ? esc_html__('Account name', 'embed-forms') : esc_html__('Add an account', 'embed-forms'); ?></label></th>
          <td>
            <input type="text" id="ef-stripe-<?php echo (int) $i; ?>" name="<?php echo $field('label'); ?>" value="<?php echo esc_attr($account['label'] ?? ''); ?>" class="regular-text" placeholder="<?php echo $account ? '' : esc_attr__('e.g. Main Stripe account', 'embed-forms'); ?>">
            <?php if ($account) : ?>
              <input type="hidden" name="<?php echo $field('id'); ?>" value="<?php echo esc_attr($account['id']); ?>">
              <code style="margin-left:8px"><?php echo esc_html($account['id']); ?></code>
              <br><label><input type="checkbox" name="<?php echo $field('remove'); ?>" value="1"> <?php esc_html_e('Remove this account (not while it has recurring payments or payments you may refund)', 'embed-forms'); ?></label>
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
                  <br><label><input type="checkbox" name="<?php echo $field($mode . '_clear_secret'); ?>" value="1"> <?php esc_html_e('Remove the saved secret key', 'embed-forms'); ?></label>
                <?php endif; ?></p>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endforeach;
  }

  private function renderGoogle(string $name): void {
    $account = Settings::googleServiceAccount();
    Sections::open('sheets', __('Google Sheets', 'embed-forms'), $account ? __('Connected', 'embed-forms') : __('Not connected', 'embed-forms'), '', $account ? 'good' : '');
    ?>
    <p class="ef-section-intro"><?php esc_html_e('Forms can add a row to a Google Sheet for every new entry. Connect Google once here (about 10 minutes), then turn it on in each form\'s Settings > Google Sheets.', 'embed-forms'); ?></p>

    <?php if ($account) : ?>
      <div class="ef-connected">
        <span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
        <span><?php esc_html_e('Connected as', 'embed-forms'); ?> <code><?php echo esc_html($account['client_email']); ?></code></span>
        <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_google_check')); ?>"><?php esc_html_e('Check the connection', 'embed-forms'); ?></a>
      </div>
      <p><?php esc_html_e('Share each spreadsheet with this address as Editor (Share in Google Sheets), then paste its link in the form.', 'embed-forms'); ?></p>
      <details class="ef-guide">
        <summary><?php esc_html_e('Show the setup steps', 'embed-forms'); ?></summary>
        <?php $this->steps(TRUE); ?>
      </details>
    <?php else : ?>
      <?php $this->steps(FALSE); ?>
    <?php endif; ?>

    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><label for="ef-google-key"><?php echo $account ? esc_html__('Replace the key', 'embed-forms') : esc_html__('Key file', 'embed-forms'); ?></label></th>
        <td>
          <p><input type="file" accept=".json,application/json" class="ef-key-file" data-target="ef-google-key" aria-label="<?php esc_attr_e('Choose the downloaded .json file', 'embed-forms'); ?>"></p>
          <textarea id="ef-google-key" name="<?php echo esc_attr($name); ?>[google_service_account]" rows="4" class="large-text code" autocomplete="off" spellcheck="false" placeholder="<?php echo $account ? esc_attr__('Saved (leave empty to keep)', 'embed-forms') : esc_attr__('Choose the file above, or open it in a text editor and paste everything here.', 'embed-forms'); ?>"></textarea>
          <p class="description"><?php esc_html_e('The key works like a password to the shared spreadsheets, so it is never shown again.', 'embed-forms'); ?></p>
          <?php if ($account) : ?>
            <label><input type="checkbox" name="<?php echo esc_attr($name); ?>[google_clear_service_account]" value="1"> <?php esc_html_e('Disconnect (remove the saved key; forms stop sending)', 'embed-forms'); ?></label>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <?php submit_button($account ? __('Save changes', 'embed-forms') : __('Save and connect', 'embed-forms'), 'primary', 'submit-sheets'); ?>
    <?php
    Sections::close();
  }

  /**
   * The Google Cloud setup, step by step, with links to each page.
   */
  private function steps(bool $done): void {
    $link = static fn(string $url, string $text): string => '<a href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html($text) . '</a>';
    ?>
    <ol class="ef-steps<?php echo $done ? ' is-done' : ''; ?>">
      <li>
        <strong><?php esc_html_e('Create a Google Cloud project', 'embed-forms'); ?></strong>
        <p><?php printf(esc_html__('Sign in with the Google account that owns your spreadsheets and open %s. Name it, for example, "Embed Forms", and click Create. It is free; no billing account is needed.', 'embed-forms'), $link('https://console.cloud.google.com/projectcreate', __('New project', 'embed-forms'))); ?></p>
      </li>
      <li>
        <strong><?php esc_html_e('Turn on the Google Sheets API', 'embed-forms'); ?></strong>
        <p><?php printf(esc_html__('Open %s, check that your new project is selected at the top of the page, and click Enable.', 'embed-forms'), $link('https://console.cloud.google.com/apis/library/sheets.googleapis.com', __('Google Sheets API', 'embed-forms'))); ?></p>
      </li>
      <li>
        <strong><?php esc_html_e('Create a service account', 'embed-forms'); ?></strong>
        <p><?php printf(esc_html__('Open %s. Name it, for example, "embed-forms", click Create and continue, then Done. Skip the optional permission steps: it needs no roles.', 'embed-forms'), $link('https://console.cloud.google.com/iam-admin/serviceaccounts/create', __('Create service account', 'embed-forms'))); ?></p>
      </li>
      <li>
        <strong><?php esc_html_e('Download a key for it', 'embed-forms'); ?></strong>
        <p><?php printf(esc_html__('In %s, click the account, open the Keys tab, then Add key > Create new key > JSON > Create. A .json file downloads.', 'embed-forms'), $link('https://console.cloud.google.com/iam-admin/serviceaccounts', __('Service accounts', 'embed-forms'))); ?></p>
        <p class="ef-step-note"><?php esc_html_e('If Google says key creation is disabled, your organisation blocks it with the policy "Disable service account key creation". A Google Workspace admin can turn that off for this project under IAM & Admin > Organization policies.', 'embed-forms'); ?></p>
      </li>
      <li>
        <strong><?php esc_html_e('Add the key here', 'embed-forms'); ?></strong>
        <p><?php esc_html_e('Choose the downloaded file below (or paste its contents) and save. It should look like this:', 'embed-forms'); ?></p>
        <figure class="ef-keyfile">
          <div class="ef-keyfile-bar">embed-forms-123456-3f9c2a1b7d4e.json</div>
<pre>{
  <mark>"type": "service_account"</mark>,
  "project_id": "embed-forms-123456",
  "private_key_id": "3f9c2a1b7d4e…",
  "private_key": "-----BEGIN PRIVATE KEY-----\nMIIEv…",
  <mark>"client_email": "embed-forms@embed-forms-123456.iam.gserviceaccount.com"</mark>,
  "client_id": "1048…",
  …
}</pre>
        </figure>
        <p class="ef-keyfile-legend"><?php esc_html_e('The type must be service_account. The client_email is the address you share spreadsheets with.', 'embed-forms'); ?></p>
      </li>
      <li>
        <strong><?php esc_html_e('Share your spreadsheet with it', 'embed-forms'); ?></strong>
        <p><?php esc_html_e('In Google Sheets, click Share, add the client_email address as Editor, and send. Then open a form\'s Settings > Google Sheets, paste the spreadsheet\'s link and turn it on.', 'embed-forms'); ?></p>
      </li>
    </ol>
    <?php
  }

  public function checkGoogle(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_google_check');
    try {
      Client::fromSettings()->verify();
      Menu::flash(__('Google accepted the key. Spreadsheets shared with the service account can now receive entries.', 'embed-forms'));
    }
    catch (\Throwable $e) {
      Menu::flash(sprintf(__('Google did not accept the key: %s', 'embed-forms'), $e->getMessage()), 'error');
    }
    wp_safe_redirect(admin_url('admin.php?page=' . Menu::PAGE_SETTINGS . '&section=sheets'));
    exit;
  }

}
