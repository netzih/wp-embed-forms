<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Schema\Display;
use EmbedForms\Schema\FormSchema;

if (!class_exists('\WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class EntriesTable extends \WP_List_Table {

  private array $form;

  /**
   * @var array<string, array>
   *   The first few answer columns, from the current schema.
   */
  private array $preview = [];

  public const STATUS_LABELS = [
    'submitted' => 'Submitted',
    'pending_payment' => 'Payment pending',
    'paid' => 'Paid',
    'failed' => 'Payment failed',
    'refunded' => 'Refunded',
    'spam' => 'Spam',
    'trash' => 'Trash',
  ];

  public function __construct(array $form) {
    parent::__construct(['singular' => 'entry', 'plural' => 'entries', 'ajax' => FALSE]);
    $this->form = $form;
    foreach (FormSchema::inputs($form['schema']) as $id => $field) {
      if ($field['type'] === 'hidden') {
        continue;
      }
      $this->preview[$id] = $field;
      if (count($this->preview) >= 4) {
        break;
      }
    }
  }

  public static function statusLabel(string $status): string {
    return __(self::STATUS_LABELS[$status] ?? $status, 'embed-forms');
  }

  public function get_columns(): array {
    $columns = ['cb' => '<input type="checkbox">', 'id' => __('#', 'embed-forms')];
    foreach ($this->preview as $id => $field) {
      $columns['f_' . $id] = esc_html($field['label'] ?: $id);
    }
    $columns['status'] = __('Status', 'embed-forms');
    $columns['created_at'] = __('Date', 'embed-forms');
    return $columns;
  }

  protected function get_sortable_columns(): array {
    return ['id' => ['id', TRUE], 'created_at' => ['created_at', TRUE], 'status' => ['status', FALSE]];
  }

  protected function get_bulk_actions(): array {
    $status = $this->currentStatus();
    if ($status === 'trash') {
      return ['restore' => __('Restore', 'embed-forms'), 'delete' => __('Delete permanently', 'embed-forms')];
    }
    if ($status === 'spam') {
      return ['notspam' => __('Not spam', 'embed-forms'), 'trash' => __('Move to Trash', 'embed-forms')];
    }
    return ['spam' => __('Mark as spam', 'embed-forms'), 'trash' => __('Move to Trash', 'embed-forms')];
  }

  public function currentStatus(): string {
    $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
    return in_array($status, Entries::STATUSES, TRUE) ? $status : '';
  }

  public function filters(): array {
    return [
      'form_id' => $this->form['id'],
      'status' => $this->currentStatus(),
      'search' => isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '',
      'from' => isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : '',
      'to' => isset($_GET['to']) ? sanitize_text_field((string) $_GET['to']) : '',
    ];
  }

  public function prepare_items(): void {
    $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
    $perPage = 25;
    $page = $this->get_pagenum();
    $result = Entries::query($this->filters() + [
      'orderby' => isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'id',
      'order' => isset($_GET['order']) ? sanitize_key((string) $_GET['order']) : 'desc',
      'limit' => $perPage,
      'offset' => ($page - 1) * $perPage,
    ]);
    $this->items = $result['rows'];
    $this->set_pagination_args(['total_items' => $result['total'], 'per_page' => $perPage]);
  }

  protected function get_views(): array {
    $counts = Entries::counts($this->form['id']);
    $current = $this->currentStatus();
    $all = array_sum(array_diff_key($counts, ['spam' => 0, 'trash' => 0]));
    $views = ['all' => sprintf('<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url(Menu::entriesUrl(['form' => $this->form['id']])), $current === '' ? ' class="current"' : '', esc_html__('All', 'embed-forms'), number_format_i18n($all))];
    foreach (Entries::STATUSES as $status) {
      if (empty($counts[$status])) {
        continue;
      }
      $views[$status] = sprintf('<a href="%s"%s>%s <span class="count">(%s)</span></a>', esc_url(Menu::entriesUrl(['form' => $this->form['id'], 'status' => $status])), $current === $status ? ' class="current"' : '', esc_html(self::statusLabel($status)), number_format_i18n($counts[$status]));
    }
    return $views;
  }

  public function column_cb($entry): string {
    return sprintf('<input type="checkbox" name="entries[]" value="%d">', (int) $entry['id']);
  }

  public function column_id(array $entry): string {
    $url = Menu::entriesUrl(['entry' => $entry['id']]);
    $label = sprintf('<a href="%s"%s>%d</a>', esc_url($url), $entry['is_read'] ? '' : ' class="ef-unread"', (int) $entry['id']);
    return $label . $this->row_actions(['view' => sprintf('<a href="%s">%s</a>', esc_url($url), esc_html__('View', 'embed-forms'))]);
  }

  public function column_status(array $entry): string {
    return sprintf('<span class="ef-status ef-status-%1$s">%2$s</span>', esc_attr($entry['status']), esc_html(self::statusLabel($entry['status'])));
  }

  public function column_created_at(array $entry): string {
    return esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($entry['created_at'] . ' UTC')));
  }

  public function column_default($entry, $column): string {
    if (str_starts_with($column, 'f_')) {
      $id = substr($column, 2);
      $text = isset($this->preview[$id]) ? Display::text($this->preview[$id], $entry['data'][$id] ?? NULL) : '';
      return esc_html(wp_trim_words(str_replace("\n", ', ', $text), 12));
    }
    return '';
  }

  public function no_items(): void {
    esc_html_e('No entries found.', 'embed-forms');
  }

  protected function extra_tablenav($which): void {
    if ($which !== 'top') {
      return;
    }
    $f = $this->filters();
    ?>
    <div class="alignleft actions">
      <label class="screen-reader-text" for="ef-from"><?php esc_html_e('From', 'embed-forms'); ?></label>
      <input type="date" id="ef-from" name="from" value="<?php echo esc_attr($f['from']); ?>">
      <label class="screen-reader-text" for="ef-to"><?php esc_html_e('To', 'embed-forms'); ?></label>
      <input type="date" id="ef-to" name="to" value="<?php echo esc_attr($f['to']); ?>">
      <?php submit_button(__('Filter', 'embed-forms'), '', 'filter_action', FALSE); ?>
      <a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_export', array_filter($f, static fn($v) => $v !== '' && $v !== 0))); ?>"><?php esc_html_e('Export CSV', 'embed-forms'); ?></a>
    </div>
    <?php
  }

}
