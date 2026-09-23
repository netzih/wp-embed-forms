<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Notifications\Mailer;
use EmbedForms\Schema\Display;
use EmbedForms\Schema\FormSchema;

/**
 * Embed Forms > Entries: a form's entries with filters and bulk actions,
 * one entry's detail (?entry=ID), and the CSV export.
 */
final class EntriesScreen {

  public function registerActions(): void {
    add_action('admin_post_embed_forms_export', [$this, 'export']);
    add_action('admin_post_embed_forms_entry_status', [$this, 'changeStatus']);
    add_action('admin_post_embed_forms_entry_delete', [$this, 'deleteEntry']);
    add_action('admin_post_embed_forms_entry_resend', [$this, 'resend']);
  }

  /**
   * Bulk actions arrive as a GET of this page (the list table's form).
   */
  public function load(): void {
    $action = $this->bulkAction();
    if ($action === '' || empty($_GET['entries'])) {
      return;
    }
    Menu::requireCapability();
    check_admin_referer('bulk-entries');
    $ids = array_map('intval', (array) $_GET['entries']);
    $map = ['spam' => 'spam', 'notspam' => 'submitted', 'trash' => 'trash', 'restore' => 'submitted'];
    foreach ($ids as $id) {
      if ($action === 'delete') {
        $entry = Entries::find($id);
        if ($entry && $entry['status'] === 'trash') {
          Entries::delete($id);
        }
      }
      elseif (isset($map[$action])) {
        $entry = Entries::find($id);
        if ($entry) {
          Entries::setStatus($id, $this->restoredStatus($entry, $map[$action]));
        }
      }
    }
    Menu::flash(sprintf(_n('%d entry updated.', '%d entries updated.', count($ids), 'embed-forms'), count($ids)));
    wp_safe_redirect(remove_query_arg(['action', 'action2', 'entries', '_wpnonce', '_wp_http_referer']));
    exit;
  }

  private function bulkAction(): string {
    foreach (['action', 'action2'] as $key) {
      $value = isset($_GET[$key]) ? sanitize_key((string) $_GET[$key]) : '';
      if ($value !== '' && $value !== '-1') {
        return $value;
      }
    }
    return '';
  }

  /**
   * An entry brought back from spam or trash returns to what its payment
   * state says, not to a plain "submitted".
   */
  private function restoredStatus(array $entry, string $target): string {
    if ($target !== 'submitted') {
      return $target;
    }
    return (string) apply_filters('embed_forms_restored_status', 'submitted', $entry);
  }

  public function render(): void {
    Menu::requireCapability();
    if (!empty($_GET['entry'])) {
      $this->detail((int) $_GET['entry']);
      return;
    }
    $forms = Forms::all();
    $formId = isset($_GET['form']) ? (int) $_GET['form'] : (int) ($forms[0]['id'] ?? 0);
    $form = $formId ? Forms::find($formId) : NULL;
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php esc_html_e('Entries', 'embed-forms'); ?></h1>
      <hr class="wp-header-end">
      <?php Menu::renderFlash(); ?>
      <?php if (!$forms) : ?>
        <p><?php esc_html_e('No forms yet.', 'embed-forms'); ?></p>
        <?php echo '</div>'; return; ?>
      <?php endif; ?>
      <form method="get" class="ef-form-picker">
        <input type="hidden" name="page" value="<?php echo esc_attr(Menu::PAGE_ENTRIES); ?>">
        <label for="ef-form-picker"><?php esc_html_e('Form', 'embed-forms'); ?></label>
        <select id="ef-form-picker" name="form" onchange="this.form.submit()">
          <?php foreach ($forms as $f) : ?>
            <option value="<?php echo (int) $f['id']; ?>" <?php selected($formId, $f['id']); ?>><?php echo esc_html($f['title'] ?: __('(no title)', 'embed-forms')); ?></option>
          <?php endforeach; ?>
        </select>
        <noscript><button class="button"><?php esc_html_e('Show', 'embed-forms'); ?></button></noscript>
        <?php if ($form) : ?>
          <a href="<?php echo esc_url(Menu::formsUrl(['form' => $form['id']])); ?>"><?php esc_html_e('Edit form', 'embed-forms'); ?></a>
        <?php endif; ?>
      </form>
      <?php if ($form) :
        $table = new EntriesTable($form);
        $table->prepare_items();
        $table->views();
        ?>
        <form method="get">
          <input type="hidden" name="page" value="<?php echo esc_attr(Menu::PAGE_ENTRIES); ?>">
          <input type="hidden" name="form" value="<?php echo (int) $form['id']; ?>">
          <?php if ($table->currentStatus() !== '') : ?><input type="hidden" name="status" value="<?php echo esc_attr($table->currentStatus()); ?>"><?php endif; ?>
          <?php $table->search_box(__('Search entries', 'embed-forms'), 'ef-entries'); ?>
          <?php $table->display(); ?>
        </form>
      <?php endif; ?>
    </div>
    <?php
  }

  private function detail(int $id): void {
    $entry = Entries::find($id);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if (!$entry || !$form) {
      echo '<div class="wrap"><h1>' . esc_html__('Entry not found', 'embed-forms') . '</h1></div>';
      return;
    }
    if (!$entry['is_read']) {
      Entries::update($id, ['is_read' => 1]);
    }
    $schema = Forms::schemaAt($form, $entry['form_version']);
    $rows = Display::rows($schema, $entry['data'], TRUE);
    $back = Menu::entriesUrl(['form' => $form['id']]);
    $date = wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['created_at'] . ' UTC'));
    ?>
    <div class="wrap ef-entry">
      <a class="ef-back" href="<?php echo esc_url($back); ?>">&larr; <?php echo esc_html(sprintf(__('Entries of %s', 'embed-forms'), $form['title'])); ?></a>
      <h1><?php echo esc_html(sprintf(__('Entry #%d', 'embed-forms'), $entry['id'])); ?></h1>
      <?php Menu::renderFlash(); ?>
      <div class="ef-entry-layout">
        <div class="ef-entry-main">
          <table class="widefat striped ef-answers">
            <tbody>
              <?php foreach ($rows as $row) : ?>
                <tr>
                  <th scope="row"><?php echo esc_html($row['label']); ?></th>
                  <td><?php echo $row['value'] === '' ? '<span class="ef-empty">&mdash;</span>' : nl2br(esc_html($row['value'])); ?></td>
                </tr>
              <?php endforeach; ?>
              <?php if (!$rows) : ?>
                <tr><td colspan="2"><?php esc_html_e('No answers.', 'embed-forms'); ?></td></tr>
              <?php endif; ?>
            </tbody>
          </table>
          <?php
          /**
           * More about the entry below its answers (payments, subscriptions).
           */
          do_action('embed_forms_entry_detail', $entry, $form);
          ?>
        </div>
        <aside class="ef-entry-side">
          <div class="postbox">
            <h2 class="hndle"><?php esc_html_e('Entry', 'embed-forms'); ?></h2>
            <div class="inside">
              <p><strong><?php esc_html_e('Status:', 'embed-forms'); ?></strong> <span class="ef-status ef-status-<?php echo esc_attr($entry['status']); ?>"><?php echo esc_html(EntriesTable::statusLabel($entry['status'])); ?></span></p>
              <p><strong><?php esc_html_e('Submitted:', 'embed-forms'); ?></strong> <?php echo esc_html($date); ?></p>
              <?php if ($entry['payer_email'] !== '') : ?>
                <p><strong><?php esc_html_e('Email:', 'embed-forms'); ?></strong> <a href="mailto:<?php echo esc_attr($entry['payer_email']); ?>"><?php echo esc_html($entry['payer_email']); ?></a></p>
              <?php endif; ?>
              <?php if (!empty($entry['source_url'])) : ?>
                <p><strong><?php esc_html_e('Submitted on:', 'embed-forms'); ?></strong> <a href="<?php echo esc_url($entry['source_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(wp_parse_url($entry['source_url'], PHP_URL_HOST) . wp_parse_url($entry['source_url'], PHP_URL_PATH)); ?></a></p>
              <?php endif; ?>
              <p><strong><?php esc_html_e('IP:', 'embed-forms'); ?></strong> <?php echo esc_html($entry['ip'] ?: '—'); ?></p>
              <p class="ef-ua"><strong><?php esc_html_e('Browser:', 'embed-forms'); ?></strong> <?php echo esc_html($entry['user_agent'] ?: '—'); ?></p>
              <p><strong><?php esc_html_e('Form version:', 'embed-forms'); ?></strong> <?php echo (int) $entry['form_version']; ?></p>
            </div>
            <div class="ef-entry-actions">
              <?php if ($entry['status'] === 'spam') : ?>
                <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_status', ['entry' => $id, 'to' => 'notspam'])); ?>"><?php esc_html_e('Not spam', 'embed-forms'); ?></a>
              <?php elseif ($entry['status'] !== 'trash') : ?>
                <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_status', ['entry' => $id, 'to' => 'spam'])); ?>"><?php esc_html_e('Mark as spam', 'embed-forms'); ?></a>
              <?php endif; ?>
              <?php if ($entry['status'] === 'trash') : ?>
                <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_status', ['entry' => $id, 'to' => 'restore'])); ?>"><?php esc_html_e('Restore', 'embed-forms'); ?></a>
                <a class="button button-link-delete ef-confirm-delete" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_delete', ['entry' => $id])); ?>"><?php esc_html_e('Delete permanently', 'embed-forms'); ?></a>
              <?php else : ?>
                <a class="button button-link-delete" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_status', ['entry' => $id, 'to' => 'trash'])); ?>"><?php esc_html_e('Move to Trash', 'embed-forms'); ?></a>
              <?php endif; ?>
              <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_entry_resend', ['entry' => $id])); ?>"><?php esc_html_e('Resend emails', 'embed-forms'); ?></a>
            </div>
          </div>
          <?php do_action('embed_forms_entry_sidebar', $entry, $form); ?>
        </aside>
      </div>
    </div>
    <?php
  }

  public function changeStatus(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_entry_status');
    $id = (int) ($_GET['entry'] ?? 0);
    $to = sanitize_key((string) ($_GET['to'] ?? ''));
    $entry = Entries::find($id);
    $map = ['spam' => 'spam', 'notspam' => 'submitted', 'trash' => 'trash', 'restore' => 'submitted'];
    if ($entry && isset($map[$to])) {
      Entries::setStatus($id, $this->restoredStatus($entry, $map[$to]));
      Menu::flash(__('Entry updated.', 'embed-forms'));
    }
    $target = $to === 'trash' && $entry ? Menu::entriesUrl(['form' => $entry['form_id']]) : Menu::entriesUrl(['entry' => $id]);
    wp_safe_redirect($target);
    exit;
  }

  public function deleteEntry(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_entry_delete');
    $id = (int) ($_GET['entry'] ?? 0);
    $entry = Entries::find($id);
    if ($entry && $entry['status'] === 'trash') {
      Entries::delete($id);
      Menu::flash(__('Entry deleted.', 'embed-forms'));
    }
    wp_safe_redirect(Menu::entriesUrl($entry ? ['form' => $entry['form_id']] : []));
    exit;
  }

  public function resend(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_entry_resend');
    $id = (int) ($_GET['entry'] ?? 0);
    $entry = Entries::find($id);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if ($entry && $form) {
      Mailer::entrySubmitted($form, $entry);
      Menu::flash(__('Emails sent again.', 'embed-forms'));
    }
    wp_safe_redirect(Menu::entriesUrl(['entry' => $id]));
    exit;
  }

  public function export(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_export');
    $form = Forms::find((int) ($_GET['form_id'] ?? 0));
    if (!$form) {
      wp_die(esc_html__('Form not found.', 'embed-forms'));
    }
    $filters = [
      'form_id' => $form['id'],
      'status' => sanitize_key((string) ($_GET['status'] ?? '')),
      'search' => sanitize_text_field(wp_unslash((string) ($_GET['search'] ?? ''))),
      'from' => sanitize_text_field((string) ($_GET['from'] ?? '')),
      'to' => sanitize_text_field((string) ($_GET['to'] ?? '')),
    ];
    $inputs = FormSchema::inputs($form['schema']);
    $columns = CsvExport::columns($inputs);
    $filename = sanitize_file_name(($form['slug'] ?: 'form') . '-entries-' . gmdate('Y-m-d') . '.csv');

    nocache_headers();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, array_merge(['Entry', 'Date (UTC)', 'Status', 'Email', 'Amount'], array_column($columns, 'label'), ['Submitted on', 'IP']));
    foreach (Entries::each($filters) as $entry) {
      fputcsv($out, CsvExport::row($entry, $columns, $inputs));
    }
    fclose($out);
    exit;
  }

}
