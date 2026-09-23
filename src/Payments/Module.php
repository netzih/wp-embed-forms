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
 * Payment support: the browser side of the payment fields, the entry
 * screen's payments and subscription panels with refund and cancel, the
 * hourly renewal worker, and USAePay Payments' Unresolved requests list.
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
    $payment = [
      'available' => Plugin::paymentsAvailable(),
      'configured' => FALSE,
      'currency' => 'USD',
      'i18n' => [
        'notConfigured' => __('Payments are not set up on this form yet, so it cannot be submitted. Please contact us.', 'embed-forms'),
        'other' => __('Other', 'embed-forms'),
        'otherAmount' => __('Other amount', 'embed-forms'),
        'total' => __('Total', 'embed-forms'),
        'quantity' => __('Quantity', 'embed-forms'),
        'orCard' => __('or pay by card', 'embed-forms'),
        'secureNote' => __('Card details are entered securely in a form hosted by USAePay.', 'embed-forms'),
        'sandboxNote' => __('Test mode: use card 4000100011112224 with any future date and CVC.', 'embed-forms'),
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
    if (Plugin::paymentsAvailable() && FormSchema::hasPayment($form['schema'])) {
      $settings = \Usaepay\WordPress\Plugin::instance()->settings();
      $mode = $settings->mode();
      $payment += [
        'publicKey' => $settings->publicKey($mode),
        'payJsUrl' => $settings->payJsUrl($mode),
        'sandbox' => $mode === 'sandbox',
        'applePay' => [
          'enabled' => $settings->applePayEnabled(),
          'displayName' => $settings->applePayDisplayName() ?: wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES),
          'countryCode' => 'US',
        ],
      ];
      $payment['configured'] = $settings->isConfigured($mode);
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
    if (!empty($config['payment']['configured'])) {
      $usaepay = \Usaepay\WordPress\Plugin::instance();
      $scripts[] = add_query_arg('ver', $usaepay->assetVersion('assets/js/usaepay-payjs.js'), $usaepay->url('assets/js/usaepay-payjs.js'));
    }
    $plugin = Plugin::instance();
    $scripts[] = add_query_arg('ver', $plugin->assetVersion('assets/payments.js'), $plugin->url('assets/payments.js'));
    return $scripts;
  }

  // -------------------------------------------------------------- cron

  public function schedule(): void {
    if (!Plugin::paymentsAvailable()) {
      return;
    }
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
          : __('When the payer submits again the earlier charge is recorded instead of charging twice. To settle it now, note the transaction on the entry and mark it paid.', 'embed-forms')
      );
    }
    foreach (Subscriptions::unresolved() as $sub) {
      $items[] = \Usaepay\WordPress\Admin\Unresolved::makeItem(
        'Embed Forms',
        sprintf(__('Recurring payment #%d (entry #%d)', 'embed-forms'), $sub['id'], $sub['entry_id']),
        Menu::entriesUrl(['entry' => $sub['entry_id']]),
        'charge',
        $sub['marker'],
        $sub['mode'],
        __('The hourly renewal worker records this charge on its next run without charging again; "Run renewal workers now" does it immediately.', 'embed-forms')
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
      <tr>
        <th scope="row"><?php esc_html_e('USAePay', 'embed-forms'); ?></th>
        <td>
          <?php if (!Plugin::paymentsAvailable()) : ?>
            <p><?php esc_html_e('USAePay Payments is not active: this form cannot take payments.', 'embed-forms'); ?></p>
          <?php else :
            $settings = \Usaepay\WordPress\Plugin::instance()->settings();
            ?>
            <p><?php echo esc_html(sprintf(__('Mode: %s.', 'embed-forms'), $settings->isSandbox() ? __('Sandbox (test)', 'embed-forms') : __('Live', 'embed-forms'))); ?>
              <?php echo $settings->isConfigured() ? '' : '<strong>' . esc_html__('Credentials for this mode are incomplete.', 'embed-forms') . '</strong>'; ?>
              <a href="<?php echo esc_url(admin_url('options-general.php?page=usaepay-payments')); ?>"><?php esc_html_e('Settings > USAePay', 'embed-forms'); ?></a></p>
          <?php endif; ?>
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
          <th><?php esc_html_e('USAePay reference', 'embed-forms'); ?></th>
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
              <td><code><?php echo esc_html($p['transaction_key'] ?: '—'); ?></code><?php echo $p['card_last4'] ? '<br><small>' . esc_html(trim($p['card_brand'] . ' •••• ' . $p['card_last4'])) . '</small>' : ''; ?></td>
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
    if (!Plugin::paymentsAvailable()) {
      Menu::flash(__('USAePay Payments is not active.', 'embed-forms'), 'error');
    }
    else {
      $result = Refunds::refund($id, isset($_POST['amount']) ? sanitize_text_field(wp_unslash((string) $_POST['amount'])) : NULL);
      Menu::flash($result['message'], $result['ok'] ? 'success' : 'error');
    }
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
