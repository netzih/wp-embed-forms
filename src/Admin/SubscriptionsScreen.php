<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Forms;
use EmbedForms\Db\Subscriptions;
use EmbedForms\Payments\Money;
use EmbedForms\Payments\Schedule;
use EmbedForms\Schema\Fields;

/**
 * Embed Forms > Subscriptions: every recurring payment, with the renewal
 * worker's "run now" button.
 */
final class SubscriptionsScreen {

  public function render(): void {
    Menu::requireCapability();
    $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $page = max(1, (int) ($_GET['paged'] ?? 1));
    $result = Subscriptions::query(['status' => $status, 'search' => $search, 'limit' => 50, 'offset' => ($page - 1) * 50]);
    $forms = [];
    $date = static fn(?string $d) => $d ? wp_date(get_option('date_format'), strtotime($d . ' UTC')) : '—';
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php esc_html_e('Subscriptions', 'embed-forms'); ?></h1>
      <a class="page-title-action" href="<?php echo esc_url(Menu::actionUrl('embed_forms_run_renewals')); ?>"><?php esc_html_e('Run renewals now', 'embed-forms'); ?></a>
      <hr class="wp-header-end">
      <?php Menu::renderFlash(); ?>
      <p class="description"><?php printf(esc_html__('Recurring payments are charged by this site every hour when due, against the card USAePay saved at signup. A declined payment is retried every %1$d days, %2$d attempts in all, then cancelled.', 'embed-forms'), Schedule::RETRY_DAYS, Schedule::MAX_ATTEMPTS); ?></p>
      <ul class="subsubsub">
        <?php foreach (['' => __('All', 'embed-forms'), 'active' => __('Active', 'embed-forms'), 'failing' => __('Failing', 'embed-forms'), 'cancelled' => __('Cancelled', 'embed-forms'), 'completed' => __('Completed', 'embed-forms')] as $key => $label) : ?>
          <li><a href="<?php echo esc_url(add_query_arg(['page' => Menu::PAGE_SUBSCRIPTIONS, 'status' => $key ?: NULL], admin_url('admin.php'))); ?>"<?php echo $status === $key ? ' class="current"' : ''; ?>><?php echo esc_html($label); ?></a> |</li>
        <?php endforeach; ?>
      </ul>
      <form method="get" class="search-box">
        <input type="hidden" name="page" value="<?php echo esc_attr(Menu::PAGE_SUBSCRIPTIONS); ?>">
        <input type="search" name="s" value="<?php echo esc_attr($search); ?>" placeholder="<?php esc_attr_e('Payer email', 'embed-forms'); ?>">
        <button class="button"><?php esc_html_e('Search', 'embed-forms'); ?></button>
      </form>
      <table class="widefat striped">
        <thead><tr>
          <th><?php esc_html_e('Entry', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Form', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Payer', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Amount', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Payments', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Next payment', 'embed-forms'); ?></th>
          <th><?php esc_html_e('Status', 'embed-forms'); ?></th>
        </tr></thead>
        <tbody>
          <?php foreach ($result['rows'] as $sub) :
            $forms[$sub['form_id']] = $forms[$sub['form_id']] ?? Forms::find($sub['form_id']);
            ?>
            <tr>
              <td><a href="<?php echo esc_url(Menu::entriesUrl(['entry' => $sub['entry_id']])); ?>">#<?php echo (int) $sub['entry_id']; ?></a></td>
              <td><?php echo esc_html($forms[$sub['form_id']]['title'] ?? '—'); ?></td>
              <td><?php echo esc_html($sub['payer_email'] ?? ''); ?></td>
              <td><?php echo esc_html(Money::format((string) $sub['amount']) . ' ' . strtolower(Fields::frequencyLabel($sub['interval_unit']))); ?></td>
              <td><?php echo esc_html($sub['payments_made'] . ($sub['recurring_times'] ? ' / ' . $sub['recurring_times'] : '')); ?></td>
              <td><?php echo esc_html($date($sub['next_charge'])); ?></td>
              <td><span class="ef-status ef-status-<?php echo esc_attr($sub['status']); ?>"><?php echo esc_html(ucfirst($sub['status'])); ?></span><?php echo $sub['mode'] === 'sandbox' ? ' <em>' . esc_html__('sandbox', 'embed-forms') . '</em>' : ''; ?></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$result['rows']) : ?>
            <tr><td colspan="7"><?php esc_html_e('No recurring payments yet.', 'embed-forms'); ?></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php
      $pages = (int) ceil($result['total'] / 50);
      if ($pages > 1) {
        echo '<div class="tablenav"><div class="tablenav-pages">' . paginate_links(['base' => add_query_arg('paged', '%#%'), 'format' => '', 'current' => $page, 'total' => $pages]) . '</div></div>';
      }
      ?>
    </div>
    <?php
  }

}
