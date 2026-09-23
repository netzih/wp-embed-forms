<?php

namespace EmbedForms\Payments;

use EmbedForms\Admin\Menu;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Plugin;
use EmbedForms\Schema\Fields;
use EmbedForms\Schema\FormSchema;

/**
 * Payment support: the browser side of the payment fields (USAePay Pay.js
 * or Stripe.js, per form), the entry screen's payments and subscription
 * panels with refund and cancel, the hourly renewal worker, and USAePay
 * Payments' Unresolved requests list.
 *
 * Hooks are added unconditionally and check for USAePay Payments when they
 * run: that plugin loads after this one.
 */
final class Module {

  public function register(): void {
    add_filter('embed_forms_page_config', [$this, 'pageConfig'], 10, 2);
    add_filter('embed_forms_page_scripts', [$this, 'pageScripts'], 10, 2);
    add_action('init', [$this, 'schedule']);
    add_action(Renewals::CRON, [$this, 'runRenewals']);
    add_filter('usaepay_payments_renewal_workers', [$this, 'renewalWorker']);
    add_filter('usaepay_payments_unresolved', [$this, 'unresolved']);
    add_filter('embed_forms_restored_status', [$this, 'restoredStatus'], 10, 2);
    if (is_admin()) {
      add_action('embed_forms_entry_detail', [$this, 'entryPanels'], 10, 2);
      add_action('admin_post_embed_forms_refund', [$this, 'refundAction']);
      add_action('admin_post_embed_forms_cancel_subscription', [$this, 'cancelAction']);
      add_action('admin_post_embed_forms_run_renewals', [$this, 'runRenewalsAction']);
      add_action('embed_forms_editor_settings', [$this, 'editorSettings']);
    }
  }

  // ------------------------------------------------------------- public

  public function pageConfig(array $config, array $form): array {
    $types = array_column($form['schema']['fields'], 'type');
    if (!array_intersect($types, Fields::PAYMENT_TYPES)) {
      return $config;
    }
    ['gateway' => $gateway, 'account' => $account] = Processor::forForm($form);
    $payment = [
      'processor' => $gateway,
      'available' => $gateway === Processor::STRIPE || Processor::usaepayActive(),
      'configured' => FALSE,
      'currency' => 'USD',
      'i18n' => [
        'notConfigured' => __('Payments are not set up on this form yet, so it cannot be submitted. Please contact us.', 'embed-forms'),
        'other' => __('Other', 'embed-forms'),
        'otherAmount' => __('Other amount', 'embed-forms'),
        'total' => __('Total', 'embed-forms'),
        'quantity' => __('Quantity', 'embed-forms'),
        'orCard' => __('or pay by card', 'embed-forms'),
        'secureNote' => $gateway === Processor::STRIPE
          ? __('Card details are entered securely in a form hosted by Stripe.', 'embed-forms')
          : __('Card details are entered securely in a form hosted by USAePay.', 'embed-forms'),
        'sandboxNote' => $gateway === Processor::STRIPE
          ? __('Test mode: use card 4242 4242 4242 4242 with any future date and CVC.', 'embed-forms')
          : __('Test mode: use card 4000100011112224 with any future date and CVC.', 'embed-forms'),
        'nothingToPay' => __('Nothing to pay yet.', 'embed-forms'),
        'per' => ['week' => __('per week', 'embed-forms'), 'month' => __('per month', 'embed-forms'), 'year' => __('per year', 'embed-forms')],
        'frequencies' => array_combine(Fields::FREQUENCIES, array_map([Fields::class, 'frequencyLabel'], Fields::FREQUENCIES)),
        'payNow' => __('Pay %s', 'embed-forms'),
        'chooseAmount' => __('Please choose or enter an amount.', 'embed-forms'),
        'amountFormat' => __('Please enter an amount, like 25 or 25.50.', 'embed-forms'),
        'min' => __('The minimum amount is %s.', 'embed-forms'),
        'max' => __('The maximum amount is %s.', 'embed-forms'),
        'atLeastOne' => __('Please choose at least one.', 'embed-forms'),
      ],
    ];
    if ($gateway === Processor::STRIPE && FormSchema::hasPayment($form['schema'])) {
      $mode = Processor::mode(Processor::STRIPE);
      $payment += [
        'publishableKey' => \EmbedForms\Settings::stripeKey($account, $mode, 'publishable'),
        'sandbox' => $mode === 'sandbox',
      ];
      $payment['configured'] = Processor::checkoutReady($form);
      $payment['i18n'] += [
        'checkCard' => __('Please check the card number, expiration date and security code.', 'embed-forms'),
        'cardFailed' => __('The card could not be checked. Please try again.', 'embed-forms'),
      ];
    }
    elseif (Processor::usaepayActive() && FormSchema::hasPayment($form['schema'])) {
      $settings = \Usaepay\WordPress\Plugin::instance()->settings();
      $mode = $settings->mode();
      $payment += [
        'publicKey' => Processor::usaepayHasAccounts() ? $settings->publicKey($mode, $account) : $settings->publicKey($mode),
        'payJsUrl' => $settings->payJsUrl($mode),
        'sandbox' => $mode === 'sandbox',
        'applePay' => [
          'enabled' => $settings->applePayEnabled(),
          'displayName' => $settings->applePayDisplayName() ?: wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
          'countryCode' => 'US',
        ],
      ];
      $payment['configured'] = Processor::checkoutReady($form);
      $payment['i18n'] += [
        'checkCard' => __('Please check the card number, expiration date and security code.', 'usaepay-payments'),
      ];
    }
    $config['payment'] = $payment;
    return $config;
  }

  public function pageScripts(array $scripts, array $config): array {
    if (empty($config['payment'])) {
      return $scripts;
    }
    if (!empty($config['payment']['configured']) && ($config['payment']['processor'] ?? '') === Processor::STRIPE) {
      $scripts[] = 'https://js.stripe.com/v3/';
    }
    elseif (!empty($config['payment']['configured'])) {
      $usaepay = \Usaepay\WordPress\Plugin::instance();
      $scripts[] = add_query_arg('ver', $usaepay->assetVersion('assets/js/usaepay-payjs.js'), $usaepay->url('assets/js/usaepay-payjs.js'));
    }
    $plugin = Plugin::instance();
    $scripts[] = add_query_arg('ver', $plugin->assetVersion('assets/payments.js'), $plugin->url('assets/payments.js'));
    return $scripts;
  }

  // -------------------------------------------------------------- cron

  public function schedule(): void {
    if (!wp_next_scheduled(Renewals::CRON)) {
      wp_schedule_event(time() + 600, 'hourly', Renewals::CRON);
    }
  }

  public function runRenewals(): void {
    $summary = (new Renewals())->run();
    if ($summary['due'] > 0) {
      error_log('[embed-forms] renewals: ' . wp_json_encode($summary));
    }
  }

  public function renewalWorker(array $workers): array {
    $workers[__('Embed Forms', 'embed-forms')] = static fn() => (new Renewals())->run();
    return $workers;
  }

  /**
   * In-flight charges and renewals for Settings > USAePay > Unresolved
   * requests.
   */
  public function unresolved(array $items): array {
    if (!class_exists('\Usaepay\WordPress\Admin\Unresolved') || !method_exists('\Usaepay\WordPress\Admin\Unresolved', 'makeItem')) {
      return $items;
    }
    foreach (Payments::unresolved() as $payment) {
      if ($payment['gateway'] !== Processor::USAEPAY) {
        continue;
      }
      $url = Menu::entriesUrl(['entry' => $payment['entry_id']]);
      $refund = $payment['kind'] === 'charge' && in_array($payment['status'], ['approved', 'partially_refunded'], TRUE);
      $items[] = \Usaepay\WordPress\Admin\Unresolved::makeItem(
        'Embed Forms',
        sprintf(__('Entry #%d', 'embed-forms'), $payment['entry_id']),
        $url,
        $refund ? 'refund' : 'charge',
        $payment['marker'],
        $payment['mode'] ?: Charger::mode(),
        $refund
          ? __('Refund the same amount again from the entry: it is recorded without refunding twice.', 'embed-forms')
          : __('When the payer submits again the earlier charge is recorded instead of charging twice. To settle it now, note the transaction on the entry and mark it paid.', 'embed-forms'),
        (string) $payment['account']
      );
    }
    foreach (Subscriptions::unresolved() as $sub) {
      if ($sub['gateway'] !== Processor::USAEPAY) {
        continue;
      }
      $items[] = \Usaepay\WordPress\Admin\Unresolved::makeItem(
        'Embed Forms',
        sprintf(__('Recurring payment #%d (entry #%d)', 'embed-forms'), $sub['id'], $sub['entry_id']),
        Menu::entriesUrl(['entry' => $sub['entry_id']]),
        'charge',
        $sub['marker'],
        $sub['mode'],
        __('The hourly renewal worker records this charge on its next run without charging again; "Run renewal workers now" does it immediately.', 'embed-forms'),
        (string) $sub['account']
      );
    }
    return $items;
  }

  public function restoredStatus(string $status, array $entry): string {
    foreach (Payments::forEntry($entry['id']) as $payment) {
      if (in_array($payment['kind'], ['charge', 'renewal'], TRUE) && in_array($payment['status'], ['approved', 'partially_refunded'], TRUE)) {
        return 'paid';
      }
    }
    return $status;
  }

  // ------------------------------------------------------------- admin

  public function editorSettings(array $form): void {
    if (!array_intersect(array_column($form['schema']['fields'], 'type'), Fields::PAYMENT_TYPES)) {
      return;
    }
    ?>
    <h2><?php esc_html_e('Payments', 'embed-forms'); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Renewal receipts', 'embed-forms'); ?></th>
        <td><label><input type="checkbox" name="settings[renewal_receipts]" value="1" <?php checked(!empty($form['settings']['renewal_receipts'])); ?>> <?php esc_html_e('Email the payer a receipt for each recurring payment', 'embed-forms'); ?></label>
          <input type="hidden" name="settings_flags[]" value="renewal_receipts"></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Merge tags', 'embed-forms'); ?></th>
        <td><p class="description"><?php esc_html_e('For the confirmation message and emails: {payment_summary} (table of what was paid), {payment_amount}, {payment_frequency}, {transaction_id}, {card_brand}, {card_last4}, {next_payment_date}.', 'embed-forms'); ?></p></td>
      </tr>
      <?php
      $choices = Processor::accountChoices();
      $p = $form['settings']['payment'];
      ?>
      <tr>
        <th scope="row"><?php esc_html_e('Payment processor', 'embed-forms'); ?></th>
        <td>
          <fieldset>
            <label><input type="radio" name="settings[payment][processor]" value="usaepay" <?php checked($p['processor'], 'usaepay'); ?>> USAePay</label>
            <select name="settings[payment][usaepay_account]" aria-label="<?php esc_attr_e('USAePay account', 'embed-forms'); ?>" style="margin-left:8px">
              <?php foreach ($choices['usaepay'] ?: ['default' => __('Default account', 'embed-forms')] as $id => $label) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($p['usaepay_account'], $id); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
              <?php if (!isset($choices['usaepay'][$p['usaepay_account']]) && $p['usaepay_account'] !== 'default') : ?>
                <option value="<?php echo esc_attr($p['usaepay_account']); ?>" selected><?php echo esc_html(sprintf(__('%s (removed)', 'embed-forms'), $p['usaepay_account'])); ?></option>
              <?php endif; ?>
            </select>
            <br>
            <label><input type="radio" name="settings[payment][processor]" value="stripe" <?php checked($p['processor'], 'stripe'); ?>> Stripe</label>
            <select name="settings[payment][stripe_account]" aria-label="<?php esc_attr_e('Stripe account', 'embed-forms'); ?>" style="margin-left:8px">
              <option value=""><?php esc_html_e('Choose an account', 'embed-forms'); ?></option>
              <?php foreach ($choices['stripe'] as $id => $label) : ?>
                <option value="<?php echo esc_attr($id); ?>" <?php selected($p['stripe_account'], $id); ?>><?php echo esc_html($label); ?></option>
              <?php endforeach; ?>
              <?php if ($p['stripe_account'] !== '' && !isset($choices['stripe'][$p['stripe_account']])) : ?>
                <option value="<?php echo esc_attr($p['stripe_account']); ?>" selected><?php echo esc_html(sprintf(__('%s (removed)', 'embed-forms'), $p['stripe_account'])); ?></option>
              <?php endif; ?>
            </select>
          </fieldset>
          <p class="description">
            <?php
            printf(
              /* translators: 1: Settings > USAePay link, 2: Embed Forms > Settings link */
              esc_html__('USAePay accounts are managed under %1$s, Stripe accounts under %2$s. Payments already made keep the processor and account they were made with: their refunds and recurring charges still go there after you change this.', 'embed-forms'),
              '<a href="' . esc_url(admin_url('options-general.php?page=usaepay-payments')) . '">' . esc_html__('Settings > USAePay', 'embed-forms') . '</a>',
              '<a href="' . esc_url(admin_url('admin.php?page=' . Menu::PAGE_SETTINGS)) . '">' . esc_html__('Embed Forms > Settings', 'embed-forms') . '</a>'
            );
            ?>
          </p>
          <?php
          ['gateway' => $gateway] = Processor::forForm($form);
          $mode = Processor::mode($gateway);
          if ($gateway === Processor::USAEPAY && !Processor::usaepayActive()) {
            $problem = __('USAePay Payments is not active: this form cannot take payments.', 'embed-forms');
          }
          elseif (!Processor::checkoutReady($form)) {
            $problem = $gateway === Processor::STRIPE && $p['stripe_account'] === ''
              ? __('Choose a Stripe account.', 'embed-forms')
              : sprintf(__('The keys of this account for %s mode are incomplete: this form cannot take payments.', 'embed-forms'), $mode === 'live' ? __('live', 'embed-forms') : __('test', 'embed-forms'));
          }
          ?>
          <p><?php echo esc_html(sprintf(__('Mode: %s.', 'embed-forms'), $mode === 'sandbox' ? __('Test (sandbox)', 'embed-forms') : __('Live', 'embed-forms'))); ?>
            <?php echo !empty($problem) ? '<strong>' . esc_html($problem) . '</strong>' : ''; ?></p>
        </td>
      </tr>
    </table>
    <?php
  }

  public function entryPanels(array $entry, array $form): void {
    $payments = Payments::forEntry($entry['id']);
    $subscriptions = Subscriptions::forEntry($entry['id']);
    $notes = Notes::forEntry($entry['id']);
    $labels = [
      'pending' => __('Pending', 'embed-forms'),
      'approved' => __('Approved', 'embed-forms'),
      'declined' => __('Declined', 'embed-forms'),
      'failed' => __('Failed', 'embed-forms'),
      'voided' => __('Voided', 'embed-forms'),
      'refunded' => __('Refunded', 'embed-forms'),
      'partially_refunded' => __('Partly refunded', 'embed-forms'),
    ];
    $kinds = ['charge' => __('Payment', 'embed-forms'), 'renewal' => __('Renewal', 'embed-forms'), 'refund' => __('Refund', 'embed-forms')];
    $date = static fn(?string $d) => $d ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($d . ' UTC')) : '—';

    if ($subscriptions) :
      ?>
      <h2 class="ef-subhead"><?php esc_html_e('Recurring payment', 'embed-forms'); ?></h2>
      <?php foreach ($subscriptions as $sub) : ?>
        <table class="widefat striped ef-answers">
          <tbody>
            <tr><th scope="row"><?php esc_html_e('Status', 'embed-forms'); ?></th><td><span class="ef-status ef-status-<?php echo esc_attr($sub['status']); ?>"><?php echo esc_html(ucfirst($sub['status'])); ?></span><?php echo $sub['mode'] === 'sandbox' ? ' <em>' . esc_html__('sandbox', 'embed-forms') . '</em>' : ''; ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Amount', 'embed-forms'); ?></th><td><?php echo esc_html(Money::format((string) $sub['amount']) . ' ' . strtolower(Fields::frequencyLabel($sub['interval_unit']))); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Payments made', 'embed-forms'); ?></th><td><?php echo esc_html($sub['payments_made'] . ($sub['recurring_times'] > 0 ? ' / ' . $sub['recurring_times'] : '')); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Next payment', 'embed-forms'); ?></th><td><?php echo esc_html($date($sub['next_charge'])); ?><?php echo $sub['failed_attempts'] ? ' ' . esc_html(sprintf(__('(retry %1$d of %2$d)', 'embed-forms'), $sub['failed_attempts'], Schedule::MAX_ATTEMPTS)) : ''; ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Card', 'embed-forms'); ?></th><td><?php echo esc_html(trim($sub['card_brand'] . ' •••• ' . $sub['card_last4'])); ?></td></tr>
            <tr><th scope="row"><?php esc_html_e('Processor', 'embed-forms'); ?></th><td><?php echo esc_html(Processor::label($sub['gateway'], $sub['account'])); ?><?php echo $sub['customer_reference'] !== '' ? ' · <code>' . esc_html($sub['customer_reference']) . '</code>' : ''; ?></td></tr>
            <?php if (!empty($sub['last_error'])) : ?>
              <tr><th scope="row"><?php esc_html_e('Last problem', 'embed-forms'); ?></th><td><?php echo esc_html($sub['last_error']); ?></td></tr>
            <?php endif; ?>
          </tbody>
        </table>
        <?php if (in_array($sub['status'], ['active', 'failing'], TRUE)) : ?>
          <p><a class="button ef-confirm-delete" href="<?php echo esc_url(Menu::actionUrl('embed_forms_cancel_subscription', ['subscription' => $sub['id']])); ?>"><?php esc_html_e('Cancel recurring payment', 'embed-forms'); ?></a></p>
        <?php endif; ?>
      <?php endforeach;
    endif;

    if ($payments) :
      ?>
      <h2 class="ef-subhead"><?php esc_html_e('Payments', 'embed-forms'); ?></h2>
      <table class="widefat striped ef-payments">
        <thead><tr>
          <th><?php esc_html_e('Date', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Type', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Amount', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Status', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Reference', 'embed-forms'); ?></th>
          <th></th>
        </tr></thead>
        <tbody>
          <?php foreach (array_reverse($payments) as $p) :
            $refundable = in_array($p['kind'], ['charge', 'renewal'], TRUE) && in_array($p['status'], ['approved', 'partially_refunded'], TRUE);
            $left = (Money::toCents((string) $p['amount']) ?? 0) - (Money::toCents((string) $p['refunded_amount']) ?? 0);
            ?>
            <tr>
              <td><?php echo esc_html($date($p['created_at'])); ?></td>
              <td><?php echo esc_html($kinds[$p['kind']] ?? $p['kind']); ?><?php echo $p['method'] === 'applepay' ? ' · Apple Pay' : ''; ?><?php echo $p['mode'] === 'sandbox' ? ' · <em>' . esc_html__('sandbox', 'embed-forms') . '</em>' : ''; ?></td>
              <td><?php echo esc_html(($p['kind'] === 'refund' ? '−' : '') . Money::format((string) $p['amount'])); ?><?php echo (float) $p['refunded_amount'] > 0 && $p['kind'] !== 'refund' ? '<br><small>' . esc_html(sprintf(__('%s refunded', 'embed-forms'), Money::format((string) $p['refunded_amount']))) . '</small>' : ''; ?></td>
              <td><?php echo esc_html($labels[$p['status']] ?? $p['status']); ?><?php echo $p['gateway_message'] ? '<br><small>' . esc_html($p['gateway_message']) . '</small>' : ''; ?></td>
              <td><small><?php echo esc_html(Processor::label($p['gateway'], $p['account'])); ?></small><br><code><?php echo esc_html($p['transaction_key'] ?: '—'); ?></code><?php echo $p['card_last4'] ? '<br><small>' . esc_html(trim($p['card_brand'] . ' •••• ' . $p['card_last4'])) . '</small>' : ''; ?></td>
              <td>
                <?php if ($refundable) : ?>
                  <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" class="ef-refund-form">
                    <input type="hidden" name="action" value="embed_forms_refund">
                    <input type="hidden" name="payment" value="<?php echo (int) $p['id']; ?>">
                    <?php wp_nonce_field('embed_forms_refund_' . $p['id']); ?>
                    <label class="screen-reader-text" for="ef-refund-<?php echo (int) $p['id']; ?>"><?php esc_html_e('Refund amount', 'embed-forms'); ?></label>
                    <input type="text" inputmode="decimal" id="ef-refund-<?php echo (int) $p['id']; ?>" name="amount" value="<?php echo esc_attr(Money::fromCents($left)); ?>" size="7">
                    <button type="submit" class="button ef-confirm-refund"><?php esc_html_e('Refund', 'embed-forms'); ?></button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php
    endif;

    if ($notes) :
      ?>
      <h2 class="ef-subhead"><?php esc_html_e('History', 'embed-forms'); ?></h2>
      <ul class="ef-notes">
        <?php foreach ($notes as $note) : ?>
          <li><time><?php echo esc_html($date($note['created_at'])); ?></time> <?php echo esc_html($note['content']); ?></li>
        <?php endforeach; ?>
      </ul>
      <?php
    endif;
  }

  public function refundAction(): void {
    Menu::requireCapability();
    $id = (int) ($_POST['payment'] ?? 0);
    check_admin_referer('embed_forms_refund_' . $id);
    $payment = Payments::find($id);
    $result = Refunds::refund($id, isset($_POST['amount']) ? sanitize_text_field(wp_unslash((string) $_POST['amount'])) : NULL);
    Menu::flash($result['message'], $result['ok'] ? 'success' : 'error');
    wp_safe_redirect(Menu::entriesUrl(['entry' => $payment ? $payment['entry_id'] : 0]));
    exit;
  }

  public function cancelAction(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_cancel_subscription');
    $id = (int) ($_GET['subscription'] ?? 0);
    $sub = Subscriptions::find($id);
    $user = wp_get_current_user();
    $done = Refunds::cancelSubscription($id, $user && $user->exists() ? sprintf(__('(by %s)', 'embed-forms'), $user->display_name) : '');
    Menu::flash($done ? __('Recurring payment cancelled.', 'embed-forms') : __('That recurring payment is not active.', 'embed-forms'), $done ? 'success' : 'error');
    wp_safe_redirect(Menu::entriesUrl(['entry' => $sub ? $sub['entry_id'] : 0]));
    exit;
  }

  public function runRenewalsAction(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_run_renewals');
    $summary = (new Renewals())->run();
    $parts = [];
    foreach ($summary as $key => $count) {
      if ($count > 0 || $key === 'due') {
        $parts[] = $key . ' ' . $count;
      }
    }
    Menu::flash(sprintf(__('Renewals: %s', 'embed-forms'), implode(', ', $parts)));
    wp_safe_redirect(admin_url('admin.php?page=' . Menu::PAGE_SUBSCRIPTIONS));
    exit;
  }

}
