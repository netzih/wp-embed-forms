<?php

namespace EmbedForms\Sheet;

use EmbedForms\Admin\CsvExport;
use EmbedForms\Db\Entries;
use EmbedForms\Orders\Status;
use EmbedForms\Payments\Money;
use EmbedForms\Plugin;
use EmbedForms\Render\Design;
use EmbedForms\Schema\FormSchema;

/**
 * What the shared entries page (Sheet\Module) and the manager page
 * (Orders\Module) have in common: a bare page, the rows with their money
 * and order status, the sheet table with filters, sorting and totals
 * (assets/sheet.js), and the downloads.
 */
final class Page {

  /**
   * Entries shown: not spam, trash, failed or still being paid.
   */
  public const STATUSES = ['submitted', 'paid', 'refunded'];

  private const MAX_ROWS = 3000;

  /**
   * Entries one bulk change may touch.
   */
  public const MAX_BULK = 300;

  /**
   * Preset accent colours (form.css), for the page's buttons.
   */
  private const ACCENTS = ['blue' => '#1666aa', 'teal' => '#007475', 'green' => '#21763c', 'violet' => '#6453a7', 'rose' => '#a33949', 'ink' => '#2b2d31'];

  /**
   * Never cached (also not at Cloudflare's edge), indexed or framed.
   */
  public static function headers(): void {
    if (!defined('DONOTCACHEPAGE')) {
      define('DONOTCACHEPAGE', TRUE);
    }
    nocache_headers();
    header('X-Robots-Tag: noindex, nofollow');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('cf-edge-cache: no-cache');
  }

  public static function columns(array $form, bool $manager = FALSE): array {
    return Grid::columns(FormSchema::inputs($form['schema']), ['store' => Status::isStore($form['schema']), 'manager' => $manager]);
  }

  /**
   * One entry as a row: its cells, money and quantities.
   *
   * @return array{entry: array, cells: array, money: array, quantities: array<string, int>}
   */
  public static function row(array $form, array $columns, array $entry): array {
    $inputs = FormSchema::inputs($form['schema']);
    $money = \EmbedForms\Orders\Service::summary($entry);
    $charge = NULL;
    foreach ($money['payments'] as $payment) {
      if ($payment['kind'] === 'charge') {
        $charge = $payment;
        break;
      }
    }
    $balance = '';
    if ($money['due'] > 0) {
      $balance = Money::fromCents($money['due']);
    }
    elseif ($money['credit'] > 0) {
      $balance = '-' . Money::fromCents($money['credit']);
    }
    $extra = ['payment' => Status::paymentLabel($money['state']), 'balance' => $balance];
    $date = get_date_from_gmt((string) $entry['created_at'], 'Y-m-d H:i');
    $cells = [];
    foreach ($columns as $column) {
      $cells[] = Grid::cell($column, $entry, $inputs, $date, $charge, $extra);
    }
    unset($money['payments']);
    return ['entry' => $entry, 'cells' => $cells, 'money' => $money, 'quantities' => Status::quantities($form['schema'], $entry['data'])];
  }

  /**
   * The rows to show, newest first.
   */
  public static function rows(array $form, array $columns, array $args = []): array {
    $entries = [];
    foreach (Entries::each(['form_id' => $form['id'], 'status' => ''] + $args) as $entry) {
      if (in_array($entry['status'], self::STATUSES, TRUE)) {
        $entries[] = $entry;
      }
    }
    $rows = [];
    foreach (array_slice(array_reverse($entries), 0, self::MAX_ROWS) as $entry) {
      $rows[] = self::row($form, $columns, $entry);
    }
    return $rows;
  }

  public static function tr(array $columns, array $row, bool $editable, bool $openable = FALSE): string {
    $entry = $row['entry'];
    $attrs = sprintf(
      ' data-entry="%d" data-pay="%s" data-order="%s" data-net="%d" data-due="%d" data-credit="%d" data-q="%s"',
      (int) $entry['id'],
      esc_attr($row['money']['state']),
      esc_attr((string) ($entry['order_status'] ?? '')),
      $row['money']['net'],
      $row['money']['due'],
      $row['money']['credit'],
      esc_attr((string) wp_json_encode((object) $row['quantities']))
    );
    $out = '<tr' . $attrs . (($entry['order_status'] ?? '') === 'cancelled' ? ' class="is-cancelled"' : '') . '>';
    foreach ($columns as $i => $column) {
      $cell = $row['cells'][$i];
      $edit = $editable && $column['edit'] !== NULL;
      $classes = [$edit ? 'is-edit' : 'is-fixed'];
      if ($column['numeric']) {
        $classes[] = 'is-num';
      }
      if ($column['key'] === 'status') {
        $classes[] = 'sh-pay sh-pay-' . sanitize_html_class($row['money']['state']);
      }
      if ($column['key'] === 'order') {
        $classes[] = 'sh-order sh-order-' . sanitize_html_class($cell['value'] !== '' ? $cell['value'] : 'new');
      }
      if ($column['key'] === 'balance' && $row['money']['credit'] > 0) {
        $classes[] = 'sh-credit';
      }
      $content = esc_html($cell['text']);
      if ($openable && $column['key'] === 'id') {
        /* translators: %d: entry number */
        $content = '<button type="button" class="sh-open" aria-label="' . esc_attr(sprintf(__('Open order %d', 'embed-forms'), (int) $entry['id'])) . '">' . $content . '</button>';
      }
      if ($editable && $column['key'] === 'id') {
        // Ticked for a bulk change.
        /* translators: %d: entry number */
        $content = '<input type="checkbox" class="sh-pick" aria-label="' . esc_attr(sprintf(__('Select entry %d', 'embed-forms'), (int) $entry['id'])) . '"> ' . $content;
      }
      $out .= sprintf(
        '<td class="%1$s" data-col="%2$d"%3$s%4$s title="%5$s">%6$s</td>',
        esc_attr(implode(' ', $classes)),
        (int) $i,
        $column['edit'] === 'select' ? ' data-value="' . esc_attr($cell['value']) . '"' : '',
        $edit ? ' tabindex="0"' : '',
        esc_attr($cell['text']),
        $content
      );
    }
    return $out . '</tr>';
  }

  public static function table(array $columns, array $rows, bool $editable, bool $openable = FALSE): string {
    $out = '<div class="sh-scroll" tabindex="0" aria-label="' . esc_attr__('Entries', 'embed-forms') . '"><table class="sh-grid' . ($editable ? ' is-editable' : '') . '"><thead><tr>';
    foreach ($columns as $i => $column) {
      $out .= sprintf(
        '<th scope="col" class="%1$s" aria-sort="none">%5$s<button type="button" class="sh-sort" data-col="%2$d"%3$s>%4$s</button></th>',
        $editable && $column['edit'] !== NULL ? '' : 'is-fixed',
        (int) $i,
        $column['numeric'] ? ' data-numeric="1"' : '',
        esc_html($column['label']),
        $editable && $column['key'] === 'id' ? '<input type="checkbox" class="sh-pick-all" aria-label="' . esc_attr__('Select all shown', 'embed-forms') . '">' : ''
      );
    }
    $out .= '</tr></thead><tbody>';
    foreach ($rows as $row) {
      $out .= self::tr($columns, $row, $editable, $openable);
    }
    return $out . '</tbody></table></div><p class="sh-none" hidden>' . esc_html__('No entries match.', 'embed-forms') . '</p>';
  }

  /**
   * Change one answer of an entry (no check of what the cell showed: the
   * caller does that, or the person confirmed a bulk change).
   *
   * @param string $note
   *   The history line: 1 column, 2 before, 3 after.
   *
   * @return array{ok: bool, changed: bool, message: string, cell: array}
   */
  public static function applyAnswer(array $form, array $entry, array $columns, int $index, string $value, string $note): array {
    $column = $columns[$index];
    $current = self::row($form, $columns, $entry)['cells'][$index];
    $result = Grid::change(\EmbedForms\Db\Forms::schemaAt($form, $entry['form_version']), $entry['data'], $column, mb_substr($value, 0, 5000));
    if (!$result['ok']) {
      return ['ok' => FALSE, 'changed' => FALSE, 'message' => $result['message'], 'cell' => $current];
    }
    $entry['data'] = $result['values'];
    $after = self::row($form, $columns, $entry)['cells'][$index];
    if ($after['text'] === $current['text']) {
      return ['ok' => TRUE, 'changed' => FALSE, 'message' => '', 'cell' => $after];
    }
    Entries::update((int) $entry['id'], [
      'data' => $result['values'],
      'payer_email' => \EmbedForms\Submit\SubmissionHandler::payerEmail($form, $result['values']) ?: $entry['payer_email'],
    ]);
    \EmbedForms\Db\Notes::add((int) $entry['id'], sprintf($note, $column['label'], $current['text'], $after['text']));
    /** This hook is documented in src/Admin/EntryEditor.php */
    do_action('embed_forms_entry_edited', Entries::find((int) $entry['id']), $form, [$column['label']]);
    do_action('embed_forms_entry_changed', (int) $form['id']);
    return ['ok' => TRUE, 'changed' => TRUE, 'message' => '', 'cell' => $after];
  }

  /**
   * A bulk change: $apply runs on each chosen entry of the form (not spam,
   * trash or failed) and answers like applyAnswer(). The answer holds each
   * entry's row as it now is and what was skipped and why.
   *
   * @param array $ids
   *   Entry ids, at most MAX_BULK.
   */
  public static function bulk(array $form, array $columns, array $ids, callable $apply, bool $openable = FALSE): array {
    $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::MAX_BULK);
    if (!$ids) {
      return ['ok' => FALSE, 'message' => __('Choose the entries to change first.', 'embed-forms')];
    }
    $changed = 0;
    $same = 0;
    $skipped = [];
    $rows = [];
    foreach ($ids as $id) {
      $entry = Entries::find($id);
      if (!$entry || (int) $entry['form_id'] !== (int) $form['id'] || !in_array($entry['status'], self::STATUSES, TRUE)) {
        $skipped[] = sprintf('#%1$d: %2$s', $id, __('no longer here', 'embed-forms'));
        continue;
      }
      $result = $apply($entry);
      if (!$result['ok']) {
        $skipped[] = sprintf('#%1$d: %2$s', $id, $result['message']);
      }
      elseif ($result['changed']) {
        $changed++;
      }
      else {
        $same++;
      }
      $rows[] = self::tr($columns, self::row($form, $columns, Entries::find($id)), TRUE, $openable);
    }
    $parts = [sprintf(_n('%s entry changed', '%s entries changed', $changed, 'embed-forms'), number_format_i18n($changed))];
    if ($same) {
      $parts[] = sprintf(_n('%s already had it', '%s already had it', $same, 'embed-forms'), number_format_i18n($same));
    }
    if ($skipped) {
      $parts[] = sprintf(_n('%s skipped', '%s skipped', count($skipped), 'embed-forms'), number_format_i18n(count($skipped)));
    }
    return [
      'ok' => !$skipped,
      'message' => implode(', ', $parts) . '.' . ($skipped ? ' ' . implode('; ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '') : ''),
      'rows' => $rows,
      'changed' => $changed,
      'skipped' => count($skipped),
    ];
  }

  /**
   * The bulk change bar, shown by sheet.js once entries are ticked.
   */
  public static function bulkBar(): string {
    return '<div class="sh-bulk" hidden role="region" aria-label="' . esc_attr__('Change the selected entries', 'embed-forms') . '">'
      . '<strong class="sh-bulk-count"></strong>'
      . '<label><span>' . esc_html__('Set', 'embed-forms') . '</span><select class="sh-bulk-column"></select></label>'
      . '<label><span>' . esc_html__('to', 'embed-forms') . '</span><span class="sh-bulk-value"></span></label>'
      . '<label class="sh-bulk-notify" hidden><input type="checkbox" checked> ' . esc_html__('Email each customer that it shipped', 'embed-forms') . '</label>'
      . '<button type="button" class="sh-button sh-primary sh-bulk-apply">' . esc_html__('Review change', 'embed-forms') . '</button>'
      . '<button type="button" class="sh-button sh-quiet sh-bulk-clear">' . esc_html__('Clear selection', 'embed-forms') . '</button>'
      . '</div>'
      . '<dialog class="sh-dialog" aria-labelledby="sh-dialog-title"><form method="dialog">'
      . '<h2 id="sh-dialog-title"></h2><p class="sh-dialog-text"></p><p class="sh-dialog-list"></p>'
      . '<div class="sh-dialog-buttons"><button value="cancel" class="sh-button">' . esc_html__('Go back', 'embed-forms') . '</button>'
      . '<button value="ok" class="sh-button sh-primary sh-dialog-ok"></button></div></form></dialog>';
  }

  /**
   * Status filters and the totals line, filled in by sheet.js.
   */
  public static function filters(array $form, array $rows): string {
    $store = Status::isStore($form['schema']);
    $states = array_unique(array_map(static fn($r) => $r['money']['state'], $rows));
    $out = '<div class="sh-filters">';
    if (count($states) > 1 || ($states && !in_array('none', $states, TRUE))) {
      $out .= '<label><span>' . esc_html__('Payment', 'embed-forms') . '</span><select class="sh-filter" data-attr="pay"><option value="">' . esc_html__('All', 'embed-forms') . '</option>';
      foreach (array_keys(Status::PAYMENT) as $key) {
        if (in_array($key, $states, TRUE)) {
          $out .= '<option value="' . esc_attr($key) . '">' . esc_html(Status::paymentLabel($key)) . '</option>';
        }
      }
      $out .= '</select></label>';
    }
    if ($store) {
      $out .= '<label><span>' . esc_html__('Order', 'embed-forms') . '</span><select class="sh-filter" data-attr="order"><option value="">' . esc_html__('All', 'embed-forms') . '</option><option value="open">' . esc_html__('Open (new and ready)', 'embed-forms') . '</option>';
      foreach (Status::ORDER as $key => $label) {
        $out .= '<option value="' . esc_attr($key === '' ? 'new' : $key) . '">' . esc_html(Status::orderLabel($key)) . '</option>';
      }
      $out .= '</select></label>';
    }
    return $out . '</div>';
  }

  /**
   * The settings sheet.js needs for filters, sorting and totals.
   */
  public static function config(array $form, array $columns, bool $editable): array {
    $products = [];
    foreach (Status::products($form['schema']) as $id => $product) {
      if ($product['own'] || $product['follows'] === '') {
        $products[] = ['id' => $id, 'label' => $product['label']];
      }
    }
    return [
      'editable' => $editable,
      'store' => Status::isStore($form['schema']),
      'products' => $products,
      'columns' => array_map(static fn($c) => ['key' => $c['key'], 'label' => $c['label'], 'edit' => $editable ? $c['edit'] : NULL, 'options' => $c['options']], $columns),
      'strings' => [
        'saved' => __('Saved', 'embed-forms'),
        'notSaved' => __('Not saved: %s', 'embed-forms'),
        'offline' => __('Not saved: the site could not be reached. Check your connection and try again.', 'embed-forms'),
        'choose' => __('(none)', 'embed-forms'),
        /* translators: 1: rows shown, 2: all rows */
        'showing' => __('%1$s of %2$s', 'embed-forms'),
        'entries' => __('entries', 'embed-forms'),
        'orders' => __('orders', 'embed-forms'),
        'entry' => __('entry', 'embed-forms'),
        'order' => __('order', 'embed-forms'),
        'received' => __('received', 'embed-forms'),
        'due' => __('to collect', 'embed-forms'),
        'credit' => __('to refund', 'embed-forms'),
        'cancelled' => __('cancelled', 'embed-forms'),
        /* translators: %s: number of entries */
        'selected' => __('%s selected', 'embed-forms'),
        /* translators: 1: column, 2: new value, 3: number of entries */
        'confirmTitle' => __('Set %1$s to "%2$s" on %3$s?', 'embed-forms'),
        'confirmText' => __('Each entry\'s history records the old and new value. Entries where this question does not apply are skipped.', 'embed-forms'),
        'confirmEmail' => __('Each customer with an email address gets the shipped email.', 'embed-forms'),
        /* translators: %s: number of entries */
        'confirmButton' => __('Change %s', 'embed-forms'),
        'blank' => __('(empty)', 'embed-forms'),
        /* translators: %s: number of entries left out of the list */
        'more' => __('and %s more', 'embed-forms'),
      ],
    ];
  }

  public static function download(array $form, array $columns, array $rows, string $kind): void {
    $header = array_column($columns, 'label');
    $out = [];
    foreach ($rows as $row) {
      $line = [];
      foreach ($columns as $i => $column) {
        $text = $row['cells'][$i]['text'];
        if ($column['key'] === 'id') {
          $line[] = (int) $text;
        }
        elseif (in_array($column['key'], ['amount', 'balance'], TRUE) && $text !== '') {
          $line[] = (float) $text;
        }
        else {
          $line[] = $text;
        }
      }
      $out[] = $line;
    }
    $name = sanitize_file_name(($form['slug'] ?: 'form') . '-entries-' . wp_date('Y-m-d'));
    if ($kind === 'xlsx' && Xlsx::available()) {
      $file = (string) tempnam(get_temp_dir(), 'efx');
      Xlsx::write($file, $form['title'], $header, $out);
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
      header('Content-Length: ' . filesize($file));
      readfile($file);
      @unlink($file);
      return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, $header);
    foreach ($out as $line) {
      fputcsv($fh, array_map(static fn($v) => is_string($v) ? CsvExport::cell($v) : $v, $line));
    }
    fclose($fh);
  }

  /**
   * A bare page with the sheet's stylesheet and, given a config, its
   * scripts.
   *
   * @param string[] $scripts
   *   Asset paths, after sheet.js.
   */
  public static function shell(string $title, ?array $form, string $body, ?array $config = NULL, array $scripts = [], string $bodyClass = ''): void {
    $plugin = Plugin::instance();
    $settings = $form['settings'] ?? [];
    $accent = ($settings['accent'] ?? '') === 'custom' && ($settings['accent_color'] ?? '') !== '' ? $settings['accent_color'] : (self::ACCENTS[$settings['accent'] ?? 'blue'] ?? self::ACCENTS['blue']);
    $vars = '';
    foreach (Design::accentVariables($accent) as $name => $value) {
      $vars .= $name . ':' . $value . ';';
    }
    $asset = static fn(string $path) => add_query_arg('ver', $plugin->assetVersion($path), $plugin->url($path));
    ?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($title); ?></title>
<link rel="preload" href="<?php echo esc_url($plugin->url('assets/fonts/Geist-Variable.woff2')); ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url($asset('assets/sheet.css')); ?>">
<style>:root{<?php echo str_replace(['<', '>'], '', $vars); // Validated colours only. ?>}
@font-face{font-family:Geist;src:url("<?php echo esc_url($plugin->url('assets/fonts/Geist-Variable.woff2')); ?>") format("woff2");font-weight:100 900;font-display:swap}</style>
</head>
<body class="sh-body<?php echo $bodyClass !== '' ? ' ' . esc_attr($bodyClass) : ''; ?>">
<?php echo $body; // Built from escaped parts. ?>
<?php if ($config !== NULL) : ?>
<script>window.EmbedFormsSheet = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo esc_url($asset('assets/sheet.js')); ?>"></script>
<?php foreach ($scripts as $script) : ?>
<script src="<?php echo esc_url($asset($script)); ?>"></script>
<?php endforeach; ?>
<?php endif; ?>
</body>
</html>
<?php
  }

}
