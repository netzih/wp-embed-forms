<?php

namespace EmbedForms\Orders;

use EmbedForms\Admin\FieldInputs;
use EmbedForms\Admin\Menu;
use EmbedForms\Admin\OfflineDonations;
use EmbedForms\Admin\Sections;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Processor;
use EmbedForms\Schema\Display;
use EmbedForms\Schema\Fields;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Sheet\Module as SheetModule;
use EmbedForms\Sheet\Page;
use EmbedForms\Sheet\Xlsx;

/**
 * The manager page (/?ef-orders=FORM): a form's entries as a sheet
 * for people signed in to WordPress who manage the form (Access), with
 * full control of each entry:
 *
 * - order status (store forms) and tracking, with a "shipped" email;
 * - quantities, with the total worked out again (never charged again);
 * - card refunds (Payments\Refunds), cash or check received or given back;
 * - cancel and reopen; notes; the confirmation email again;
 * - orders entered by hand; a packing list to print; downloads.
 *
 * Every change is written to the entry's history with the person's name.
 * The same order status and tracking are on the entry screen in wp-admin.
 */
final class Module {

  public const QUERY_VAR = 'ef-orders';

  public function register(): void {
    (new Access())->register();
    add_action('template_redirect', [$this, 'maybeServe'], 0);
    add_filter('cloudflare_use_cache', static fn($cache) => isset($_GET[self::QUERY_VAR]) ? FALSE : $cache, 99);
    if (is_admin()) {
      add_action('embed_forms_editor_settings', [$this, 'editorSettings'], 45);
      add_action('embed_forms_entry_sidebar', [$this, 'entrySidebar'], 5, 2);
      add_action('admin_post_embed_forms_order_update', [$this, 'adminUpdate']);
    }
  }

  public static function url(int $formId, array $args = []): string {
    return add_query_arg([self::QUERY_VAR => $formId] + $args, home_url('/'));
  }

  // ------------------------------------------------------------- routing

  public function maybeServe(): void {
    if (!isset($_GET[self::QUERY_VAR])) {
      return;
    }
    Page::headers();
    $formId = absint($_GET[self::QUERY_VAR]);
    if (!is_user_logged_in()) {
      wp_safe_redirect(wp_login_url(self::url($formId)));
      exit;
    }
    $action = isset($_REQUEST['ef_action']) ? sanitize_key((string) $_REQUEST['ef_action']) : '';
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    $ids = Access::formIds();
    if ($formId === 0 && count($ids) === 1) {
      wp_safe_redirect(self::url($ids[0]));
      exit;
    }
    $form = $formId && Access::can($formId) ? Forms::find($formId) : NULL;
    if (!$form) {
      if ($action !== '') {
        wp_send_json(['ok' => FALSE, 'message' => __('You do not manage this form.', 'embed-forms')], 403);
      }
      if ($formId) {
        status_header(403);
      }
      $this->formList($ids);
      exit;
    }
    if ($post || in_array($action, ['panel', 'new'], TRUE)) {
      $nonce = (string) ($_SERVER['HTTP_X_EF_SHEET'] ?? '');
      if (!wp_verify_nonce($nonce, 'ef_orders_' . $form['id'])) {
        wp_send_json(['ok' => FALSE, 'message' => __('Your session ended. Reload the page.', 'embed-forms')], 403);
      }
    }
    if ($action === 'save' && $post) {
      $this->save($form);
    }
    if ($action === 'act' && $post) {
      $this->act($form);
    }
    if ($action === 'bulk' && $post) {
      $this->bulk($form);
    }
    if ($action === 'panel') {
      $entry = $this->entry($form, (int) ($_GET['entry'] ?? 0));
      wp_send_json(['ok' => TRUE, 'panel' => $this->panel($form, $entry)]);
    }
    if ($action === 'new') {
      wp_send_json(['ok' => TRUE, 'panel' => $this->newForm($form)]);
    }
    if ($action === 'create' && $post) {
      $this->create($form);
    }
    if ($action === 'csv' || $action === 'xlsx') {
      $columns = Page::columns($form, TRUE);
      Page::download($form, $columns, Page::rows($form, $columns), $action);
      exit;
    }
    if ($action === 'print') {
      $this->printList($form);
      exit;
    }
    $this->page($form, $ids);
    exit;
  }

  private function entry(array $form, int $id): array {
    $entry = Entries::find($id);
    if (!$entry || (int) $entry['form_id'] !== (int) $form['id'] || !in_array($entry['status'], Page::STATUSES, TRUE)) {
      wp_send_json(['ok' => FALSE, 'message' => __('This entry is no longer here. Reload the page.', 'embed-forms')], 404);
    }
    return $entry;
  }

  private static function noun(array $form): string {
    return Status::isStore($form['schema']) ? __('order', 'embed-forms') : __('entry', 'embed-forms');
  }

  // ------------------------------------------------------------- pages

  private function formList(array $ids): void {
    ob_start();
    ?>
    <div class="sh-gate sh-forms">
      <h1><?php esc_html_e('Your forms', 'embed-forms'); ?></h1>
      <?php if (!$ids) : ?>
        <p><?php esc_html_e('No forms are assigned to you yet. Ask an administrator to choose your forms on your profile.', 'embed-forms'); ?></p>
      <?php else : ?>
        <ul>
          <?php foreach ($ids as $id) :
            $f = Forms::find($id);
            if (!$f) {
              continue;
            }
            $open = Status::isStore($f['schema']) ? Entries::query(['form_id' => $id, 'status' => '', 'order_status' => 'open', 'limit' => 1])['total'] : NULL;
            ?>
            <li><a href="<?php echo esc_url(self::url($id)); ?>"><?php echo esc_html($f['title']); ?></a>
              <?php if ($open !== NULL) : ?><span><?php echo esc_html(sprintf(_n('%s open order', '%s open orders', $open, 'embed-forms'), number_format_i18n($open))); ?></span><?php endif; ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
      <p><a class="sh-button sh-quiet" href="<?php echo esc_url(wp_logout_url(self::url(0))); ?>"><?php esc_html_e('Sign out', 'embed-forms'); ?></a></p>
    </div>
    <?php
    Page::shell(__('Your forms', 'embed-forms'), NULL, (string) ob_get_clean());
  }

  private function page(array $form, array $ids): void {
    $columns = Page::columns($form, TRUE);
    $rows = Page::rows($form, $columns);
    $store = Status::isStore($form['schema']);
    $link = static fn(array $args) => self::url((int) $form['id'], $args);
    ob_start();
    ?>
    <header class="sh-bar">
      <div class="sh-title">
        <?php if (count($ids) > 1) : ?>
          <label class="sh-switch"><span class="screen-reader-text"><?php esc_html_e('Form', 'embed-forms'); ?></span>
            <select class="sh-form-switch">
              <?php foreach ($ids as $id) :
                $f = Forms::find($id);
                if ($f) : ?>
                  <option value="<?php echo esc_url(self::url($id)); ?>" <?php selected($id, (int) $form['id']); ?>><?php echo esc_html($f['title']); ?></option>
                <?php endif;
              endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <h1><?php echo esc_html($form['title']); ?></h1>
        <p><?php echo $store ? esc_html__('Click an order number to manage it. White cells can be changed in place.', 'embed-forms') : esc_html__('Click an entry number to manage it. White cells can be changed in place.', 'embed-forms'); ?></p>
      </div>
      <div class="sh-tools">
        <input type="search" class="sh-search" placeholder="<?php esc_attr_e('Find', 'embed-forms'); ?>" aria-label="<?php esc_attr_e('Find in entries', 'embed-forms'); ?>">
        <button type="button" class="sh-button sh-primary sh-add"><?php echo $store ? esc_html__('Add order', 'embed-forms') : esc_html__('Add entry', 'embed-forms'); ?></button>
        <?php if ($store) : ?>
          <a class="sh-button" href="<?php echo esc_url($link(['ef_action' => 'print'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Packing list', 'embed-forms'); ?></a>
        <?php endif; ?>
        <a class="sh-button" href="<?php echo esc_url($link(['ef_action' => 'csv'])); ?>"><?php esc_html_e('CSV', 'embed-forms'); ?></a>
        <?php if (Xlsx::available()) : ?>
          <a class="sh-button" href="<?php echo esc_url($link(['ef_action' => 'xlsx'])); ?>"><?php esc_html_e('Excel', 'embed-forms'); ?></a>
        <?php endif; ?>
        <a class="sh-button sh-quiet" href="<?php echo esc_url(wp_logout_url(self::url(0))); ?>"><?php esc_html_e('Sign out', 'embed-forms'); ?></a>
      </div>
    </header>
    <div class="sh-summary">
      <p class="sh-totals" aria-live="polite"></p>
      <?php echo Page::filters($form, $rows); // Escaped inside. ?>
    </div>
    <?php if (!$rows) : ?>
      <p class="sh-empty"><?php esc_html_e('No entries yet. They appear here as soon as someone submits the form, or add one by hand.', 'embed-forms'); ?></p>
    <?php endif; ?>
    <?php echo Page::bulkBar(); // Escaped inside. ?>
    <?php echo Page::table($columns, $rows, TRUE, TRUE); // Escaped inside. ?>
    <div class="sh-backdrop" hidden></div>
    <aside class="sh-panel" hidden aria-modal="true" role="dialog" aria-labelledby="sh-panel-title" tabindex="-1"><div class="sh-panel-body"></div></aside>
    <div class="sh-toast" role="status" aria-live="polite" hidden></div>
    <?php
    $config = [
      'url' => self::url((int) $form['id']),
      'csrf' => wp_create_nonce('ef_orders_' . $form['id']),
    ] + Page::config($form, $columns, TRUE);
    $config['strings'] += [
      'loading' => __('Loading…', 'embed-forms'),
      'close' => __('Close', 'embed-forms'),
    ];
    $config['shippedEmail'] = $store && !empty($form['settings']['orders']['shipped_email']);
    Page::shell($form['title'], $form, (string) ob_get_clean(), $config, ['assets/orders.js'], 'sh-manager');
  }

  // ------------------------------------------------------------- changes

  private function respond(array $form, array $entry, array $result, bool $panel = TRUE): void {
    $fresh = Entries::find((int) $entry['id']);
    $columns = Page::columns($form, TRUE);
    $out = $result + ['row' => Page::tr($columns, Page::row($form, $columns, $fresh), TRUE, TRUE)];
    if ($panel) {
      $out['panel'] = $this->panel($form, $fresh);
    }
    wp_send_json($out, $result['ok'] ? 200 : 422);
  }

  /**
   * A cell changed in the sheet: an answer, the order status or tracking.
   */
  private function save(array $form): void {
    $post = wp_unslash($_POST);
    $entry = $this->entry($form, (int) ($post['entry'] ?? 0));
    $columns = Page::columns($form, TRUE);
    $key = (string) ($post['column'] ?? '');
    if ($key !== 'order' && $key !== 'tracking') {
      SheetModule::saveCell($form, $entry, $columns, $post, sprintf(__('%s changed on the manager page:', 'embed-forms'), Service::who()) . ' %1$s from "%2$s" to "%3$s".', TRUE);
    }
    $before = $key === 'order' ? Status::orderLabel((string) $entry['order_status']) : (string) $entry['tracking'];
    if ($before !== (string) ($post['before'] ?? '')) {
      $index = array_search($key, array_column($columns, 'key'), TRUE);
      $cell = Page::row($form, $columns, $entry)['cells'][$index];
      wp_send_json(['ok' => FALSE, 'conflict' => TRUE, 'message' => __('Someone else changed this cell a moment ago; it now shows their answer.', 'embed-forms')] + $cell, 409);
    }
    $value = (string) ($post['value'] ?? '');
    $result = $key === 'order'
      ? Service::setOrder($entry, $form, $value)
      : Service::setOrder($entry, $form, (string) $entry['order_status'], $value);
    if ($result['ok'] && $key === 'order' && $value === 'shipped' && !empty($form['settings']['orders']['shipped_email'])) {
      $result['message'] .= ' ' . __('To email the customer, open the order.', 'embed-forms');
    }
    $this->respond($form, $entry, $result, FALSE);
  }

  /**
   * One column set to one value on the chosen entries: an answer or the
   * order status (tracking numbers and money are changed one at a time).
   */
  private function bulk(array $form): void {
    $post = wp_unslash($_POST);
    $columns = Page::columns($form, TRUE);
    $key = (string) ($post['column'] ?? '');
    $index = array_search($key, array_column($columns, 'key'), TRUE);
    $value = (string) ($post['value'] ?? '');
    if ($index === FALSE || $key === 'tracking' || $columns[$index]['edit'] === NULL || ($key !== 'order' && $columns[$index]['field'] === NULL)) {
      wp_send_json(['ok' => FALSE, 'message' => __('This column cannot be changed in bulk.', 'embed-forms')], 400);
    }
    if ($key === 'order') {
      $notify = !empty($post['notify']) && $value === 'shipped';
      $apply = static function (array $entry) use ($form, $value, $notify): array {
        if (($entry['order_status'] ?? '') === $value) {
          return ['ok' => TRUE, 'changed' => FALSE, 'message' => ''];
        }
        $result = Service::setOrder($entry, $form, $value, NULL, $notify);
        return ['ok' => $result['ok'], 'changed' => $result['ok'], 'message' => $result['message']];
      };
    }
    else {
      $note = sprintf(__('%s changed on the manager page (bulk):', 'embed-forms'), Service::who()) . ' %1$s from "%2$s" to "%3$s".';
      $apply = static fn(array $entry): array => Page::applyAnswer($form, $entry, $columns, $index, $value, $note);
    }
    wp_send_json(Page::bulk($form, $columns, (array) ($post['entries'] ?? []), $apply, TRUE));
  }

  /**
   * An action from an order's panel.
   */
  private function act(array $form): void {
    $post = wp_unslash($_POST);
    $entry = $this->entry($form, (int) ($post['entry'] ?? 0));
    $store = Status::isStore($form['schema']);
    switch (sanitize_key((string) ($post['op'] ?? ''))) {
      case 'order':
        $result = $store ? Service::setOrder($entry, $form, (string) ($post['order_status'] ?? ''), (string) ($post['tracking'] ?? ''), !empty($post['notify'])) : NULL;
        break;

      case 'quantities':
        $result = $store ? Service::changeQuantities($entry, $form, (array) ($post['q'] ?? [])) : NULL;
        break;

      case 'refund':
        $result = Service::refund($entry, (int) ($post['payment'] ?? 0), (string) ($post['amount'] ?? ''));
        break;

      case 'payment':
        $result = Service::recordPayment($entry, $form, (string) ($post['amount'] ?? ''), (string) ($post['method'] ?? ''), (string) ($post['reference'] ?? ''));
        break;

      case 'cancel':
        $result = $store ? Service::cancel($entry, $form, !empty($post['refund'])) : NULL;
        break;

      case 'reopen':
        $result = $store ? Service::reopen($entry, $form) : NULL;
        break;

      case 'note':
        $result = Service::note($entry, (string) ($post['note'] ?? ''));
        break;

      case 'receipt':
        $result = Service::resendReceipt($entry, $form);
        break;

      case 'shipped_email':
        $result = $store ? Service::sendShipped($entry, $form) : NULL;
        break;

      case 'stop_recurring':
        $result = Service::stopRecurring($entry, (int) ($post['subscription'] ?? 0));
        break;

      default:
        $result = NULL;
    }
    $this->respond($form, $entry, $result ?? ['ok' => FALSE, 'message' => __('That cannot be done here.', 'embed-forms')]);
  }

  private function create(array $form): void {
    $post = wp_unslash($_POST);
    $result = Service::create(
      $form,
      (array) ($post['values'] ?? []),
      ['paid' => !empty($post['paid']), 'amount' => (string) ($post['amount'] ?? ''), 'method' => (string) ($post['method'] ?? ''), 'reference' => (string) ($post['reference'] ?? '')],
      !empty($post['notify']),
      (string) ($post['note'] ?? '')
    );
    if (!$result['ok']) {
      wp_send_json(['ok' => FALSE, 'message' => $result['message'], 'panel' => $this->newForm($form, $post, $result['errors'])], 422);
    }
    $this->respond($form, Entries::find($result['entry_id']), $result);
  }

  // ------------------------------------------------------------- panel

  private function panel(array $form, array $entry): string {
    $schema = Forms::schemaAt($form, (int) $entry['form_version']);
    $store = Status::isStore($form['schema']);
    $sum = Service::summary($entry);
    $subscriptions = Subscriptions::forEntry((int) $entry['id']);
    $notes = Notes::forEntry((int) $entry['id']);
    $cancelled = $entry['order_status'] === 'cancelled';
    $date = static fn(?string $d) => $d ? wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($d . ' UTC')) : '';
    $email = (string) $entry['payer_email'];
    $products = Status::products($schema);
    $kinds = ['charge' => __('Payment', 'embed-forms'), 'renewal' => __('Renewal', 'embed-forms'), 'refund' => __('Refund', 'embed-forms')];
    $title = $store ? sprintf(__('Order #%d', 'embed-forms'), $entry['id']) : sprintf(__('Entry #%d', 'embed-forms'), $entry['id']);
    $who = '';
    foreach (FormSchema::inputs($schema) as $id => $field) {
      if ($field['type'] === 'name') {
        $who = trim(Display::text($field, $entry['data'][$id] ?? NULL));
        break;
      }
    }
    ob_start();
    ?>
    <div class="sh-panel-head" data-entry="<?php echo (int) $entry['id']; ?>">
      <div>
        <h2 id="sh-panel-title"><?php echo esc_html($title); ?></h2>
        <p><?php echo esc_html(implode(', ', array_filter([$who, $email, $date($entry['created_at'])]))); ?></p>
        <p class="sh-badges">
          <span class="sh-badge sh-pay-<?php echo esc_attr($sum['state']); ?>"><?php echo esc_html(Status::paymentLabel($sum['state'])); ?></span>
          <?php if ($store) : ?><span class="sh-badge sh-order-<?php echo esc_attr($entry['order_status'] ?: 'new'); ?>"><?php echo esc_html(Status::orderLabel((string) $entry['order_status'])); ?></span><?php endif; ?>
        </p>
      </div>
      <button type="button" class="sh-close" aria-label="<?php esc_attr_e('Close', 'embed-forms'); ?>">×</button>
    </div>

    <?php if ($store && !$cancelled) : ?>
      <section class="sh-part">
        <h3><?php esc_html_e('Order', 'embed-forms'); ?></h3>
        <form data-op="order" class="sh-form">
          <div class="sh-row">
            <label><span><?php esc_html_e('Status', 'embed-forms'); ?></span>
              <select name="order_status" class="sh-order-select">
                <?php foreach (Status::ORDER as $key => $label) :
                  if ($key === 'cancelled') {
                    continue;
                  } ?>
                  <option value="<?php echo esc_attr($key); ?>" <?php selected((string) $entry['order_status'], $key); ?>><?php echo esc_html(Status::orderLabel($key)); ?></option>
                <?php endforeach; ?>
              </select></label>
            <label class="sh-grow"><span><?php esc_html_e('Tracking number', 'embed-forms'); ?></span>
              <input type="text" name="tracking" value="<?php echo esc_attr((string) $entry['tracking']); ?>" autocomplete="off" spellcheck="false"></label>
          </div>
          <?php if ($entry['tracking'] !== '') :
            $track = Tracking::link((string) $entry['tracking']);
            if ($track['url'] !== '') : ?>
              <p class="sh-hint"><a href="<?php echo esc_url($track['url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($track['carrier'] !== '' ? sprintf(__('Track with %s', 'embed-forms'), $track['carrier']) : __('Track the package', 'embed-forms')); ?></a></p>
            <?php endif;
          endif; ?>
          <?php if (!empty($form['settings']['orders']['shipped_email']) && is_email($email)) : ?>
            <label class="sh-check" data-show-when="shipped"><input type="checkbox" name="notify" value="1" <?php checked($entry['order_status'] !== 'shipped'); ?>> <?php echo esc_html(sprintf(__('Email %s that it shipped', 'embed-forms'), $email)); ?></label>
          <?php endif; ?>
          <button type="submit" class="sh-button sh-primary"><?php esc_html_e('Save order', 'embed-forms'); ?></button>
        </form>
      </section>
    <?php endif; ?>

    <?php
    $own = array_filter($products, static fn($p) => $p['own']);
    if ($store && $own && !$subscriptions && !$cancelled) : ?>
      <section class="sh-part">
        <h3><?php esc_html_e('Items', 'embed-forms'); ?></h3>
        <form data-op="quantities" class="sh-form">
          <table class="sh-items">
            <?php foreach ($products as $id => $product) :
              $q = max(0, (int) ($entry['data'][$id] ?? 0)); ?>
              <tr>
                <th scope="row"><?php echo esc_html($product['label']); ?> <small><?php echo esc_html(Money::format($product['price'])); ?></small></th>
                <td>
                  <?php if ($product['own']) : ?>
                    <input type="number" name="q[<?php echo esc_attr($id); ?>]" value="<?php echo (int) $q; ?>" min="0" max="<?php echo (int) $product['max']; ?>" step="1" inputmode="numeric" aria-label="<?php echo esc_attr(sprintf(__('Quantity of %s', 'embed-forms'), $product['label'])); ?>">
                  <?php elseif ($product['follows'] !== '' && isset($products[$product['follows']])) : ?>
                    <span class="sh-hint"><?php echo esc_html(sprintf(__('%1$d (one per %2$s)', 'embed-forms'), $q, $products[$product['follows']]['label'])); ?></span>
                  <?php else : ?>
                    <span class="sh-hint"><?php echo (int) $q; ?></span>
                  <?php endif; ?>
                </td>
                <td class="is-num"><?php echo esc_html(Money::format(Money::fromCents($q * (Money::toCents($product['price']) ?? 0)))); ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="sh-items-total"><th scope="row"><?php esc_html_e('Total', 'embed-forms'); ?></th><td></td><td class="is-num"><?php echo esc_html(Money::format((string) ($entry['amount'] ?? '0'))); ?></td></tr>
          </table>
          <p class="sh-hint"><?php esc_html_e('The total is worked out again. The card is not charged again: a higher total shows as a balance to collect, a lower one as a refund to give.', 'embed-forms'); ?></p>
          <button type="submit" class="sh-button"><?php esc_html_e('Save quantities', 'embed-forms'); ?></button>
        </form>
      </section>
    <?php endif; ?>

    <section class="sh-part">
      <h3><?php esc_html_e('Payment', 'embed-forms'); ?></h3>
      <dl class="sh-money">
        <?php if ($entry['amount'] !== NULL) : ?>
          <div><dt><?php echo $store ? esc_html__('Order total', 'embed-forms') : esc_html__('Amount', 'embed-forms'); ?></dt><dd><?php echo esc_html(Money::format((string) $entry['amount'])); ?></dd></div>
        <?php endif; ?>
        <div><dt><?php esc_html_e('Received', 'embed-forms'); ?></dt><dd><?php echo esc_html(Money::format(Money::fromCents($sum['received']))); ?></dd></div>
        <?php if ($sum['refunded'] > 0) : ?>
          <div><dt><?php esc_html_e('Refunded', 'embed-forms'); ?></dt><dd><?php echo esc_html(Money::format(Money::fromCents($sum['refunded']))); ?></dd></div>
        <?php endif; ?>
        <?php if ($sum['due'] > 0) : ?>
          <div class="sh-warn"><dt><?php esc_html_e('Balance due', 'embed-forms'); ?></dt><dd><?php echo esc_html(Money::format(Money::fromCents($sum['due']))); ?></dd></div>
        <?php endif; ?>
        <?php if ($sum['credit'] > 0) : ?>
          <div class="sh-warn"><dt><?php esc_html_e('To refund', 'embed-forms'); ?></dt><dd><?php echo esc_html(Money::format(Money::fromCents($sum['credit']))); ?></dd></div>
        <?php endif; ?>
      </dl>

      <?php if ($sum['payments']) : ?>
        <ul class="sh-payments">
          <?php foreach (array_reverse($sum['payments']) as $p) :
            $left = Service::left($p);
            $offline = $p['gateway'] === Processor::OFFLINE;
            $how = $offline ? OfflineDonations::methodLabel((string) $p['method']) : trim(($p['card_brand'] ?: Processor::label($p['gateway'], $p['account'])) . ($p['card_last4'] ? ' •••• ' . $p['card_last4'] : ''));
            ?>
            <li class="<?php echo $p['kind'] === 'refund' ? 'is-refund' : ''; ?>">
              <div class="sh-pay-line">
                <span><?php echo esc_html(($kinds[$p['kind']] ?? $p['kind']) . ', ' . $how); ?><?php echo $p['mode'] === 'sandbox' ? ' <em>' . esc_html__('test', 'embed-forms') . '</em>' : ''; ?><br><small><?php echo esc_html($date($p['created_at'])); ?><?php echo $p['refnum'] !== '' ? ' · ' . esc_html($p['refnum']) : ''; ?><?php echo !in_array($p['status'], ['approved'], TRUE) ? ' · ' . esc_html(ucfirst(str_replace('_', ' ', (string) $p['status']))) : ''; ?></small></span>
                <strong><?php echo esc_html(($p['kind'] === 'refund' ? '−' : '') . Money::format((string) $p['amount'])); ?></strong>
              </div>
              <?php if ($left > 0) :
                $suggest = $sum['credit'] > 0 ? min($sum['credit'], $left) : $left;
                $confirm = $offline
                  ? __('Record %s given back? Nothing is sent anywhere: give the money back yourself.', 'embed-forms')
                  /* translators: 1: amount, 2: card */
                  : sprintf(__('Refund %1$s to %2$s? The money goes back to the card. This cannot be undone.', 'embed-forms'), '%s', $how);
                ?>
                <form data-op="refund" class="sh-form sh-inline" data-confirm="<?php echo esc_attr($confirm); ?>">
                  <input type="hidden" name="payment" value="<?php echo (int) $p['id']; ?>">
                  <label><span class="screen-reader-text"><?php esc_html_e('Amount', 'embed-forms'); ?></span>$ <input type="text" name="amount" inputmode="decimal" value="<?php echo esc_attr(Money::fromCents($suggest)); ?>" size="8" class="sh-amount"></label>
                  <button type="submit" class="sh-button sh-danger-quiet"><?php echo $offline ? esc_html__('Record money given back', 'embed-forms') : esc_html__('Refund', 'embed-forms'); ?></button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <details class="sh-more"<?php echo $sum['due'] > 0 ? ' open' : ''; ?>>
        <summary><?php esc_html_e('Record a payment (cash, check…)', 'embed-forms'); ?></summary>
        <form data-op="payment" class="sh-form">
          <div class="sh-row">
            <label><span><?php esc_html_e('Amount', 'embed-forms'); ?></span><input type="text" name="amount" inputmode="decimal" value="<?php echo $sum['due'] > 0 ? esc_attr(Money::fromCents($sum['due'])) : ''; ?>" size="8" required></label>
            <label><span><?php esc_html_e('Paid by', 'embed-forms'); ?></span><select name="method">
              <?php foreach (OfflineDonations::METHODS as $method) : ?>
                <option value="<?php echo esc_attr($method); ?>"><?php echo esc_html(OfflineDonations::methodLabel($method)); ?></option>
              <?php endforeach; ?>
            </select></label>
            <label class="sh-grow"><span><?php esc_html_e('Reference', 'embed-forms'); ?></span><input type="text" name="reference" placeholder="<?php esc_attr_e('Check number…', 'embed-forms'); ?>"></label>
          </div>
          <button type="submit" class="sh-button"><?php esc_html_e('Record payment', 'embed-forms'); ?></button>
        </form>
      </details>

      <?php foreach ($subscriptions as $sub) :
        if (!in_array($sub['status'], ['active', 'failing'], TRUE)) {
          continue;
        } ?>
        <form data-op="stop_recurring" class="sh-form" data-confirm="<?php esc_attr_e('Stop this recurring payment? No more payments will be taken.', 'embed-forms'); ?>">
          <input type="hidden" name="subscription" value="<?php echo (int) $sub['id']; ?>">
          <p class="sh-hint"><?php echo esc_html(sprintf(__('Recurring: %1$s %2$s.', 'embed-forms'), Money::format((string) $sub['amount']), strtolower(Fields::frequencyLabel($sub['interval_unit'])))); ?></p>
          <button type="submit" class="sh-button sh-danger-quiet"><?php esc_html_e('Stop recurring payment', 'embed-forms'); ?></button>
        </form>
      <?php endforeach; ?>
    </section>

    <?php if ($store) : ?>
      <section class="sh-part">
        <?php if ($cancelled) : ?>
          <h3><?php esc_html_e('Cancelled', 'embed-forms'); ?></h3>
          <form data-op="reopen" class="sh-form">
            <button type="submit" class="sh-button"><?php esc_html_e('Reopen order', 'embed-forms'); ?></button>
          </form>
        <?php else : ?>
          <details class="sh-more sh-cancel">
            <summary><?php esc_html_e('Cancel order', 'embed-forms'); ?></summary>
            <form data-op="cancel" class="sh-form" data-confirm="<?php esc_attr_e('Cancel this order?', 'embed-forms'); ?>">
              <?php if ($sum['net'] > 0) : ?>
                <label class="sh-check"><input type="checkbox" name="refund" value="1" checked> <?php echo esc_html(sprintf(__('Also refund the %s paid (cards are refunded; cash and checks are recorded as given back)', 'embed-forms'), Money::format(Money::fromCents($sum['net'])))); ?></label>
              <?php endif; ?>
              <button type="submit" class="sh-button sh-danger"><?php esc_html_e('Cancel order', 'embed-forms'); ?></button>
            </form>
          </details>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="sh-part">
      <h3><?php esc_html_e('Emails', 'embed-forms'); ?></h3>
      <div class="sh-actions">
        <?php if (!empty($form['settings']['autoresponder']['enabled'])) : ?>
          <form data-op="receipt" class="sh-form sh-inline"><button type="submit" class="sh-button"><?php esc_html_e('Resend confirmation', 'embed-forms'); ?></button></form>
        <?php endif; ?>
        <?php if ($store && $entry['order_status'] === 'shipped' && is_email($email)) : ?>
          <form data-op="shipped_email" class="sh-form sh-inline"><button type="submit" class="sh-button"><?php esc_html_e('Send shipped email', 'embed-forms'); ?></button></form>
        <?php endif; ?>
      </div>
      <?php if (empty($form['settings']['autoresponder']['enabled']) && !($store && $entry['order_status'] === 'shipped')) : ?>
        <p class="sh-hint"><?php esc_html_e('This form sends no confirmation email.', 'embed-forms'); ?></p>
      <?php endif; ?>
    </section>

    <section class="sh-part">
      <h3><?php esc_html_e('Notes and history', 'embed-forms'); ?></h3>
      <form data-op="note" class="sh-form">
        <label><span class="screen-reader-text"><?php esc_html_e('Note', 'embed-forms'); ?></span><textarea name="note" rows="2" placeholder="<?php esc_attr_e('Add a note, for example: called, picking up Sunday', 'embed-forms'); ?>"></textarea></label>
        <button type="submit" class="sh-button"><?php esc_html_e('Add note', 'embed-forms'); ?></button>
      </form>
      <ol class="sh-history">
        <?php foreach ($notes as $note) : ?>
          <li class="<?php echo ($note['kind'] ?? '') === 'note' ? 'is-note' : ''; ?>"><time><?php echo esc_html($date($note['created_at'])); ?></time> <?php echo esc_html($note['content']); ?></li>
        <?php endforeach; ?>
      </ol>
    </section>

    <section class="sh-part">
      <h3><?php esc_html_e('Answers', 'embed-forms'); ?></h3>
      <dl class="sh-answers">
        <?php foreach (Display::rows($schema, $entry['data']) as $row) : ?>
          <div><dt><?php echo esc_html($row['label']); ?></dt><dd><?php echo nl2br(esc_html($row['value'])); ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <p class="sh-hint"><?php esc_html_e('Change answers in the sheet: click a white cell.', 'embed-forms'); ?></p>
    </section>
    <?php
    return (string) ob_get_clean();
  }

  /**
   * Adding an order or entry by hand.
   */
  private function newForm(array $form, array $posted = [], array $errors = []): string {
    $schema = $form['schema'];
    $store = Status::isStore($schema);
    $values = (array) ($posted['values'] ?? []);
    $hasPayment = FormSchema::hasPayment($schema);
    ob_start();
    ?>
    <div class="sh-panel-head">
      <div><h2 id="sh-panel-title"><?php echo $store ? esc_html__('Add an order', 'embed-forms') : esc_html__('Add an entry', 'embed-forms'); ?></h2>
        <p><?php esc_html_e('For orders taken by phone, in person or by mail. Fill in what you have.', 'embed-forms'); ?></p></div>
      <button type="button" class="sh-close" aria-label="<?php esc_attr_e('Close', 'embed-forms'); ?>">×</button>
    </div>
    <form class="sh-form sh-new" data-create="1" novalidate>
      <?php foreach ($schema['fields'] as $field) :
        if ($field['type'] === 'section') : ?>
          <h3 class="sh-new-section"><?php echo esc_html($field['label']); ?></h3>
        <?php endif;
        if (!Fields::isInput($field['type']) || $field['type'] === 'frequency') {
          continue;
        }
        $id = $field['id'];
        if ($field['type'] === 'product' && empty($field['quantity'])) {
          continue;
        }
        $label = $field['label'] !== '' ? $field['label'] : $id;
        ?>
        <div class="sh-field<?php echo isset($errors[$id]) ? ' has-error' : ''; ?>">
          <label class="sh-field-label" for="ef-v-<?php echo esc_attr($id); ?>"><?php echo esc_html($field['type'] === 'anonymous' ? __('Anonymous', 'embed-forms') : $label); ?></label>
          <?php if ($field['type'] === 'product') : ?>
            <?php if (($field['quantity_of'] ?? '') !== '') : ?>
              <p class="sh-hint"><?php esc_html_e('Follows the quantity it goes with.', 'embed-forms'); ?></p>
            <?php else : ?>
              <input type="number" id="ef-v-<?php echo esc_attr($id); ?>" name="values[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr((string) ($values[$id] ?? '0')); ?>" min="0" max="<?php echo (int) $field['max_quantity']; ?>" step="1" inputmode="numeric"> <span class="sh-hint"><?php echo esc_html(sprintf(__('× %s', 'embed-forms'), Money::format((string) $field['price']))); ?></span>
            <?php endif; ?>
          <?php elseif ($field['type'] === 'amount') : ?>
            $ <input type="text" id="ef-v-<?php echo esc_attr($id); ?>" name="values[<?php echo esc_attr($id); ?>]" value="<?php echo esc_attr((string) ($values[$id] ?? '')); ?>" inputmode="decimal" size="10">
          <?php else :
            FieldInputs::render($field, $values[$id] ?? NULL);
          endif; ?>
          <?php if (isset($errors[$id])) : ?><p class="sh-field-error"><?php echo esc_html($errors[$id]); ?></p><?php endif; ?>
          <?php if (!empty($field['conditions'])) : ?><p class="sh-hint"><?php esc_html_e('Kept only when the answers it depends on match, as on the form.', 'embed-forms'); ?></p><?php endif; ?>
        </div>
      <?php endforeach; ?>

      <?php if ($hasPayment) : ?>
        <h3 class="sh-new-section"><?php esc_html_e('Money received', 'embed-forms'); ?></h3>
        <fieldset class="sh-field">
          <legend class="screen-reader-text"><?php esc_html_e('Money received', 'embed-forms'); ?></legend>
          <label class="sh-check"><input type="radio" name="paid" value="" <?php checked(empty($posted['paid'])); ?>> <?php esc_html_e('Not paid yet', 'embed-forms'); ?></label>
          <label class="sh-check"><input type="radio" name="paid" value="1" <?php checked(!empty($posted['paid'])); ?>> <?php esc_html_e('Paid by cash, check or another way', 'embed-forms'); ?></label>
          <div class="sh-row" data-show-when-paid>
            <label><span><?php esc_html_e('Amount received', 'embed-forms'); ?></span><input type="text" name="amount" inputmode="decimal" size="8" value="<?php echo esc_attr((string) ($posted['amount'] ?? '')); ?>" placeholder="<?php esc_attr_e('The total', 'embed-forms'); ?>"></label>
            <label><span><?php esc_html_e('Paid by', 'embed-forms'); ?></span><select name="method">
              <?php foreach (OfflineDonations::METHODS as $method) : ?>
                <option value="<?php echo esc_attr($method); ?>" <?php selected((string) ($posted['method'] ?? 'cash'), $method); ?>><?php echo esc_html(OfflineDonations::methodLabel($method)); ?></option>
              <?php endforeach; ?>
            </select></label>
            <label class="sh-grow"><span><?php esc_html_e('Reference', 'embed-forms'); ?></span><input type="text" name="reference" value="<?php echo esc_attr((string) ($posted['reference'] ?? '')); ?>"></label>
          </div>
          <p class="sh-hint"><?php esc_html_e('Cards cannot be charged here. To take a card, send the customer the form.', 'embed-forms'); ?></p>
        </fieldset>
      <?php endif; ?>

      <div class="sh-field">
        <label class="sh-field-label" for="sh-new-note"><?php esc_html_e('Note', 'embed-forms'); ?></label>
        <textarea id="sh-new-note" name="note" rows="2"><?php echo esc_textarea((string) ($posted['note'] ?? '')); ?></textarea>
      </div>
      <label class="sh-check"><input type="checkbox" name="notify" value="1" <?php checked(!empty($posted['notify'])); ?>> <?php esc_html_e('Send the form\'s emails (notification, and the confirmation to the customer)', 'embed-forms'); ?></label>
      <p class="sh-submit"><button type="submit" class="sh-button sh-primary"><?php echo $store ? esc_html__('Add order', 'embed-forms') : esc_html__('Add entry', 'embed-forms'); ?></button></p>
    </form>
    <?php
    return (string) ob_get_clean();
  }

  // ------------------------------------------------------------- packing list

  private function printList(array $form): void {
    $show = sanitize_key((string) ($_GET['show'] ?? 'open'));
    $filters = [
      'open' => __('To do (new and ready)', 'embed-forms'),
      'new' => __('New', 'embed-forms'),
      'ready' => __('Ready', 'embed-forms'),
      'shipped' => __('Shipped', 'embed-forms'),
      'picked_up' => __('Picked up', 'embed-forms'),
    ];
    $show = isset($filters[$show]) ? $show : 'open';
    $schema = $form['schema'];
    $inputs = FormSchema::inputs($schema);
    $products = Status::products($schema);
    $entries = [];
    foreach (Entries::each(['form_id' => $form['id'], 'status' => '', 'order_status' => $show]) as $entry) {
      if (in_array($entry['status'], Page::STATUSES, TRUE)) {
        $entries[] = $entry;
      }
    }
    $totals = [];
    ob_start();
    foreach ($entries as $entry) {
      $schemaAt = Forms::schemaAt($form, (int) $entry['form_version']);
      $sum = Service::summary($entry);
      $name = $phone = '';
      $address = [];
      $choices = [];
      foreach (FormSchema::inputs($schemaAt) as $id => $field) {
        $text = trim(Display::text($field, $entry['data'][$id] ?? NULL));
        if ($text === '') {
          continue;
        }
        if ($field['type'] === 'name' && $name === '') {
          $name = $text;
        }
        elseif ($field['type'] === 'phone' && $phone === '') {
          $phone = $text;
        }
        elseif ($field['type'] === 'address' && stripos($field['label'], 'billing') === FALSE) {
          $address[] = $text;
        }
        elseif (in_array($field['type'], ['radio', 'select'], TRUE)) {
          $choices[] = $text;
        }
      }
      $items = [];
      foreach ($products as $id => $product) {
        $q = max(0, (int) ($entry['data'][$id] ?? 0));
        if ($q > 0 && $product['follows'] === '') {
          $items[] = $q . ' × ' . $product['label'];
          $totals[$product['label']] = ($totals[$product['label']] ?? 0) + $q;
        }
      }
      $notes = array_filter(Notes::forEntry((int) $entry['id']), static fn($n) => ($n['kind'] ?? '') === 'note');
      ?>
      <tr>
        <td class="sh-box" aria-hidden="true"></td>
        <td><strong>#<?php echo (int) $entry['id']; ?></strong><br><small><?php echo esc_html(Status::orderLabel((string) $entry['order_status'])); ?></small></td>
        <td><strong><?php echo esc_html($name ?: $entry['payer_email']); ?></strong><?php echo $phone !== '' ? '<br>' . esc_html($phone) : ''; ?><?php echo $entry['payer_email'] !== '' && $name !== '' ? '<br><small>' . esc_html($entry['payer_email']) . '</small>' : ''; ?></td>
        <td><?php echo esc_html(implode(', ', $choices)); ?><?php echo $address ? '<br>' . nl2br(esc_html(implode("\n", $address))) : ''; ?></td>
        <td><?php echo esc_html(implode(', ', $items)); ?></td>
        <td><?php if (!in_array($sum['state'], ['paid', 'partly_refunded'], TRUE)) : ?><strong class="sh-warn"><?php echo esc_html(Status::paymentLabel($sum['state'])); ?></strong><?php else : ?><?php echo esc_html(Status::paymentLabel($sum['state'])); ?><?php endif; ?></td>
        <td><?php foreach ($notes as $n) : ?><div><?php echo esc_html($n['content']); ?></div><?php endforeach; ?></td>
      </tr>
      <?php
    }
    $rowsHtml = (string) ob_get_clean();
    ob_start();
    ?>
    <div class="sh-print">
      <header class="sh-print-head">
        <div>
          <h1><?php echo esc_html($form['title']); ?></h1>
          <p><?php echo esc_html(sprintf(__('Packing list: %1$s. Printed %2$s.', 'embed-forms'), $filters[$show], wp_date(get_option('date_format') . ' ' . get_option('time_format')))); ?></p>
          <p class="sh-print-totals"><strong><?php echo esc_html(sprintf(_n('%s order', '%s orders', count($entries), 'embed-forms'), number_format_i18n(count($entries)))); ?></strong><?php foreach ($totals as $label => $n) : ?> <span><strong><?php echo (int) $n; ?></strong> <?php echo esc_html($label); ?></span><?php endforeach; ?></p>
        </div>
        <nav class="sh-noprint">
          <?php foreach ($filters as $key => $label) : ?>
            <a class="sh-button<?php echo $key === $show ? ' is-current' : ''; ?>" href="<?php echo esc_url(self::url((int) $form['id'], ['ef_action' => 'print', 'show' => $key])); ?>"<?php echo $key === $show ? ' aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
          <?php endforeach; ?>
          <button type="button" class="sh-button sh-primary" onclick="window.print()"><?php esc_html_e('Print', 'embed-forms'); ?></button>
        </nav>
      </header>
      <?php if (!$entries) : ?>
        <p class="sh-empty"><?php esc_html_e('No orders here.', 'embed-forms'); ?></p>
      <?php else : ?>
        <table class="sh-print-table">
          <thead><tr><th><span class="screen-reader-text"><?php esc_html_e('Done', 'embed-forms'); ?></span></th><th><?php esc_html_e('Order', 'embed-forms'); ?></th><th><?php esc_html_e('Customer', 'embed-forms'); ?></th><th><?php esc_html_e('Delivery', 'embed-forms'); ?></th><th><?php esc_html_e('Items', 'embed-forms'); ?></th><th><?php esc_html_e('Payment', 'embed-forms'); ?></th><th><?php esc_html_e('Notes', 'embed-forms'); ?></th></tr></thead>
          <tbody><?php echo $rowsHtml; // Escaped above. ?></tbody>
        </table>
      <?php endif; ?>
    </div>
    <?php
    Page::shell(sprintf(__('Packing list: %s', 'embed-forms'), $form['title']), $form, (string) ob_get_clean(), NULL, [], 'sh-print-page');
  }

  // ------------------------------------------------------------- admin

  public function editorSettings(array $form): void {
    $store = Status::isStore($form['schema']);
    $o = $form['settings']['orders'];
    $managers = Access::managers((int) $form['id']);
    Sections::open('orders', __('Manager page', 'embed-forms'), $managers ? sprintf(_n('%s person', '%s people', count($managers), 'embed-forms'), count($managers)) : '');
    ?>
    <p class="ef-section-intro"><?php echo $store
      ? esc_html__('A page to run this form\'s orders: order status and tracking, quantities, refunds, cancelling, notes, orders taken by phone, and a packing list. People sign in with their WordPress account.', 'embed-forms')
      : esc_html__('A page to run this form\'s entries: refunds, payments by cash or check, notes, entries added by hand. People sign in with their WordPress account.', 'embed-forms'); ?></p>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Page', 'embed-forms'); ?></th>
        <td>
          <div class="ef-code-row">
            <input type="text" readonly class="large-text code" value="<?php echo esc_attr(self::url((int) $form['id'])); ?>" onclick="this.select()">
            <button type="button" class="button ef-copy"><?php esc_html_e('Copy', 'embed-forms'); ?></button>
            <a class="button" href="<?php echo esc_url(self::url((int) $form['id'])); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open', 'embed-forms'); ?></a>
          </div>
          <p class="description"><?php esc_html_e('Administrators can always open it. Anyone else needs to be signed in as one of the people below.', 'embed-forms'); ?></p>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('People', 'embed-forms'); ?></th>
        <td>
          <?php if ($managers) : ?>
            <ul class="ef-plain-list">
              <?php foreach ($managers as $user) : ?>
                <li><a href="<?php echo esc_url(get_edit_user_link($user->ID) . '#embed-forms-orders'); ?>"><?php echo esc_html($user->display_name); ?></a> <span class="description"><?php echo esc_html($user->user_email); ?></span></li>
              <?php endforeach; ?>
            </ul>
          <?php else : ?>
            <p><?php esc_html_e('No form managers for this form yet.', 'embed-forms'); ?></p>
          <?php endif; ?>
          <p class="description"><?php echo wp_kses(sprintf(
            /* translators: %s: link to add a user */
            __('To add someone, <a href="%s">add a user</a> with the Form manager role, then tick this form on their profile.', 'embed-forms'),
            esc_url(admin_url('user-new.php'))
          ), ['a' => ['href' => []]]); ?></p>
        </td>
      </tr>
      <?php if ($store) : ?>
        <tr>
          <th scope="row"><?php esc_html_e('Shipped email', 'embed-forms'); ?></th>
          <td>
            <input type="hidden" name="settings[orders][shipped_email]" value="0">
            <label><input type="checkbox" name="settings[orders][shipped_email]" value="1" <?php checked($o['shipped_email']); ?>> <?php esc_html_e('Offer to email the customer when an order is marked shipped', 'embed-forms'); ?></label>
          </td>
        </tr>
        <tr data-ef-show-if="settings[orders][shipped_email]">
          <th scope="row"><label for="ef-shipped-subject"><?php esc_html_e('Subject', 'embed-forms'); ?></label></th>
          <td><input type="text" id="ef-shipped-subject" name="settings[orders][shipped_subject]" value="<?php echo esc_attr($o['shipped_subject']); ?>" class="large-text"></td>
        </tr>
        <tr data-ef-show-if="settings[orders][shipped_email]">
          <th scope="row"><label for="ef-shipped-body"><?php esc_html_e('Message', 'embed-forms'); ?></label></th>
          <td><textarea id="ef-shipped-body" name="settings[orders][shipped_body]" rows="8" class="large-text"><?php echo esc_textarea($o['shipped_body']); ?></textarea>
            <p class="description"><?php esc_html_e('Tags: {tracking_number}, {tracking_link} (a "Track your package" link for UPS, USPS and FedEx numbers), {entry_id}, {form_title}, {site_name}, {all_fields}, and the form\'s field tags.', 'embed-forms'); ?></p></td>
        </tr>
      <?php else : ?>
        <input type="hidden" name="settings[orders][shipped_email]" value="<?php echo $o['shipped_email'] ? '1' : '0'; ?>">
        <input type="hidden" name="settings[orders][shipped_subject]" value="<?php echo esc_attr($o['shipped_subject']); ?>">
        <input type="hidden" name="settings[orders][shipped_body]" value="<?php echo esc_attr($o['shipped_body']); ?>">
      <?php endif; ?>
    </table>
    <?php
    Sections::close();
  }

  /**
   * The order box on the entry screen (store forms).
   */
  public function entrySidebar(array $entry, array $form): void {
    if (!Status::isStore($form['schema']) || !in_array($entry['status'], Page::STATUSES, TRUE)) {
      return;
    }
    $sum = Service::summary($entry);
    ?>
    <div class="postbox ef-order-box">
      <h2 class="hndle"><?php esc_html_e('Order', 'embed-forms'); ?></h2>
      <div class="inside">
        <p><strong><?php esc_html_e('Payment:', 'embed-forms'); ?></strong> <?php echo esc_html(Status::paymentLabel($sum['state'])); ?>
          <?php if ($sum['due'] > 0) : ?><br><?php echo esc_html(sprintf(__('Balance due: %s', 'embed-forms'), Money::format(Money::fromCents($sum['due'])))); ?><?php endif; ?>
          <?php if ($sum['credit'] > 0) : ?><br><?php echo esc_html(sprintf(__('To refund: %s', 'embed-forms'), Money::format(Money::fromCents($sum['credit'])))); ?><?php endif; ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
          <input type="hidden" name="action" value="embed_forms_order_update">
          <input type="hidden" name="entry" value="<?php echo (int) $entry['id']; ?>">
          <?php wp_nonce_field('embed_forms_order_update_' . $entry['id']); ?>
          <p><label for="ef-order-status"><strong><?php esc_html_e('Order status', 'embed-forms'); ?></strong></label><br>
            <select id="ef-order-status" name="order_status">
              <?php foreach (Status::ORDER as $key => $label) : ?>
                <option value="<?php echo esc_attr($key); ?>" <?php selected((string) $entry['order_status'], $key); ?>><?php echo esc_html(Status::orderLabel($key)); ?></option>
              <?php endforeach; ?>
            </select></p>
          <p><label for="ef-order-tracking"><strong><?php esc_html_e('Tracking number', 'embed-forms'); ?></strong></label><br>
            <input type="text" id="ef-order-tracking" name="tracking" value="<?php echo esc_attr((string) $entry['tracking']); ?>" class="widefat"></p>
          <?php if (!empty($form['settings']['orders']['shipped_email']) && is_email((string) $entry['payer_email'])) : ?>
            <p><label><input type="checkbox" name="notify" value="1"> <?php esc_html_e('When shipped, email the customer', 'embed-forms'); ?></label></p>
          <?php endif; ?>
          <p><button type="submit" class="button"><?php esc_html_e('Save order', 'embed-forms'); ?></button>
            <a href="<?php echo esc_url(self::url((int) $form['id'])); ?>" target="_blank" rel="noopener" style="margin-left:8px"><?php esc_html_e('Manager page', 'embed-forms'); ?></a></p>
        </form>
      </div>
    </div>
    <?php
  }

  public function adminUpdate(): void {
    Menu::requireCapability();
    $id = (int) ($_POST['entry'] ?? 0);
    check_admin_referer('embed_forms_order_update_' . $id);
    $entry = Entries::find($id);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if ($entry && $form) {
      $result = Service::setOrder($entry, $form, sanitize_key((string) ($_POST['order_status'] ?? '')), sanitize_text_field(wp_unslash((string) ($_POST['tracking'] ?? ''))), !empty($_POST['notify']));
      Menu::flash($result['message'], $result['ok'] ? 'success' : 'error');
    }
    wp_safe_redirect(Menu::entriesUrl(['entry' => $id]));
    exit;
  }

}
