<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Embed;

if (!class_exists('\WP_List_Table')) {
  require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class FormsTable extends \WP_List_Table {

  public function __construct() {
    parent::__construct(['singular' => 'form', 'plural' => 'forms', 'ajax' => FALSE]);
  }

  public function get_columns(): array {
    return [
      'title' => __('Title', 'embed-forms'),
      'status' => __('Status', 'embed-forms'),
      'entries' => __('Entries', 'embed-forms'),
      'link' => __('Link', 'embed-forms'),
      'updated' => __('Last modified', 'embed-forms'),
    ];
  }

  public function prepare_items(): void {
    $this->_column_headers = [$this->get_columns(), [], []];
    $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
    $search = isset($_GET['s']) ? sanitize_text_field(wp_unslash((string) $_GET['s'])) : '';
    $this->items = Forms::all(['status' => $status, 'search' => $search]);
  }

  protected function get_views(): array {
    $current = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
    $views = ['' => __('All', 'embed-forms'), 'live' => __('Live', 'embed-forms'), 'draft' => __('Draft', 'embed-forms'), 'closed' => __('Closed', 'embed-forms')];
    $out = [];
    foreach ($views as $key => $label) {
      $out[$key ?: 'all'] = sprintf('<a href="%s"%s>%s</a>', esc_url(Menu::formsUrl($key ? ['status' => $key] : [])), $current === $key ? ' class="current"' : '', esc_html($label));
    }
    return $out;
  }

  public function column_title(array $form): string {
    $edit = Menu::formsUrl(['form' => $form['id']]);
    $actions = [
      'edit' => sprintf('<a href="%s">%s</a>', esc_url($edit), esc_html__('Edit', 'embed-forms')),
      'entries' => sprintf('<a href="%s">%s</a>', esc_url(Menu::entriesUrl(['form' => $form['id']])), esc_html__('Entries', 'embed-forms')),
      'view' => sprintf('<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url(Embed::url($form)), esc_html__('View', 'embed-forms')),
      'duplicate' => sprintf('<a href="%s">%s</a>', esc_url(Menu::actionUrl('embed_forms_duplicate', ['form' => $form['id']])), esc_html__('Duplicate', 'embed-forms')),
      'delete' => sprintf('<a href="%s" class="ef-confirm-delete">%s</a>', esc_url(Menu::actionUrl('embed_forms_delete', ['form' => $form['id']])), esc_html__('Delete', 'embed-forms')),
    ];
    return sprintf('<strong><a class="row-title" href="%s">%s</a></strong>', esc_url($edit), esc_html($form['title'] ?: __('(no title)', 'embed-forms'))) . $this->row_actions($actions);
  }

  public function column_status(array $form): string {
    $labels = ['live' => __('Live', 'embed-forms'), 'draft' => __('Draft', 'embed-forms'), 'closed' => __('Closed', 'embed-forms')];
    return sprintf('<span class="ef-status ef-status-%1$s">%2$s</span>', esc_attr($form['status']), esc_html($labels[$form['status']] ?? $form['status']));
  }

  public function column_entries(array $form): string {
    $counts = Entries::counts($form['id']);
    unset($counts['spam'], $counts['trash']);
    $total = array_sum($counts);
    return sprintf('<a href="%s">%s</a>', esc_url(Menu::entriesUrl(['form' => $form['id']])), esc_html(number_format_i18n($total)));
  }

  public function column_link(array $form): string {
    return '<code>/' . esc_html(Embed::base() . '/' . $form['slug']) . '/</code>';
  }

  public function column_updated(array $form): string {
    return esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($form['updated_at'] . ' UTC')));
  }

  public function no_items(): void {
    esc_html_e('No forms yet.', 'embed-forms');
  }

}
