<?php

namespace EmbedForms\Sheet;

use EmbedForms\Admin\CsvExport;
use EmbedForms\Admin\Sections;
use EmbedForms\Db\Db;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Db\Payments;
use EmbedForms\Plugin;
use EmbedForms\Render\Design;
use EmbedForms\Schema\Display;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Security\RateLimit;

/**
 * A form's entries as a sheet on a page of its own, for people without a
 * WordPress login: Settings > Shared entries page on the form.
 *
 * - The page is at a secret link (/?ef-sheet=TOKEN) and asks for a
 *   password; both are set per form. A signed cookie keeps the person in
 *   for 12 hours. A new password or a new link signs everyone out.
 * - Password tries are limited per IP address and per form.
 * - Answers can be changed cell by cell when the form allows it (Grid
 *   decides what can change); each change is written to the entry's
 *   history. Payments, status and amounts cannot be changed.
 * - Downloads as CSV or Excel.
 * - The page is never cached (also not by Cloudflare's edge cache),
 *   indexed or framed, and sends no referrer.
 */
final class Module {

  public const QUERY_VAR = 'ef-sheet';

  private const SESSION = 12 * HOUR_IN_SECONDS;

  private const STATUSES = ['submitted', 'paid', 'refunded'];

  private const MAX_ROWS = 3000;

  /**
   * Preset accent colours (form.css), for the page's buttons.
   */
  private const ACCENTS = ['blue' => '#1666aa', 'teal' => '#007475', 'green' => '#21763c', 'violet' => '#6453a7', 'rose' => '#a33949', 'ink' => '#2b2d31'];

  public function register(): void {
    add_action('template_redirect', [$this, 'maybeServe'], 0);
    // The Cloudflare plugin marks pages for its edge cache (APO) for anyone
    // not logged in to WordPress; this page must never be cached there.
    add_filter('cloudflare_use_cache', static fn($cache) => isset($_GET[self::QUERY_VAR]) ? FALSE : $cache, 99);
    if (is_admin()) {
      add_action('embed_forms_editor_settings', [$this, 'editorSettings'], 40);
      add_filter('embed_forms_save_settings', [$this, 'saveSettings'], 10, 3);
    }
  }

  public static function url(array $form): string {
    return add_query_arg(self::QUERY_VAR, $form['settings']['sheet']['token'], home_url('/'));
  }

  // ------------------------------------------------------------- page

  public function maybeServe(): void {
    if (!isset($_GET[self::QUERY_VAR])) {
      return;
    }
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

    $token = (string) $_GET[self::QUERY_VAR];
    $form = preg_match('/^[a-f0-9]{32}$/', $token) ? self::findByToken($token) : NULL;
    if (!$form || empty($form['settings']['sheet']['enabled'])) {
      status_header(404);
      $this->shell(__('Link not active', 'embed-forms'), NULL, '<div class="sh-gate"><h1>' . esc_html__('This link is not active', 'embed-forms') . '</h1><p>' . esc_html__('Ask the person who sent it for a new link.', 'embed-forms') . '</p></div>');
      exit;
    }

    $action = isset($_REQUEST['ef_action']) ? sanitize_key((string) $_REQUEST['ef_action']) : '';
    $post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if ($action === 'login' && $post) {
      $this->login($form);
      exit;
    }
    if ($action === 'logout') {
      self::clearCookie($form);
      wp_safe_redirect(self::url($form));
      exit;
    }
    if (!self::signedIn($form)) {
      if ($action === 'save') {
        wp_send_json(['ok' => FALSE, 'message' => __('You were signed out. Reload the page and enter the password again.', 'embed-forms')], 401);
      }
      $this->gate($form);
      exit;
    }
    if ($action === 'save' && $post) {
      $this->save($form);
      exit;
    }
    if ($action === 'csv' || $action === 'xlsx') {
      $this->download($form, $action);
      exit;
    }
    $this->sheet($form);
    exit;
  }

  private static function findByToken(string $token): ?array {
    $db = Db::wpdb();
    $ids = $db->get_col($db->prepare('SELECT id FROM ' . Db::table('forms') . ' WHERE settings_json LIKE %s LIMIT 5', '%' . $db->esc_like('"token":"' . $token . '"') . '%'));
    foreach ((array) $ids as $id) {
      $form = Forms::find((int) $id);
      if ($form && hash_equals($form['settings']['sheet']['token'], $token)) {
        return $form;
      }
    }
    return NULL;
  }

  // ------------------------------------------------------------- sign in

  private static function cookieName(array $form): string {
    return 'ef_sheet_' . (int) $form['id'];
  }

  /**
   * The cookie's signature covers the link and the password, so a new
   * link or password signs everyone out.
   */
  private static function signature(array $form, int $expires): string {
    $sheet = $form['settings']['sheet'];
    return hash_hmac('sha256', $form['id'] . '|' . $sheet['token'] . '|' . $sheet['password_hash'] . '|' . $expires, wp_salt('auth'));
  }

  private static function signedIn(array $form): bool {
    $cookie = (string) ($_COOKIE[self::cookieName($form)] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $cookie, $m) || (int) $m[1] < time()) {
      return FALSE;
    }
    return hash_equals(self::signature($form, (int) $m[1]), $m[2]);
  }

  /**
   * Sent with every change, so another site cannot make one.
   */
  private static function csrf(array $form): string {
    return hash_hmac('sha256', 'csrf|' . (string) ($_COOKIE[self::cookieName($form)] ?? ''), wp_salt('nonce'));
  }

  private static function setCookie(array $form): void {
    $expires = time() + self::SESSION;
    setcookie(self::cookieName($form), $expires . '.' . self::signature($form, $expires), [
      'expires' => $expires,
      'path' => '/',
      'secure' => is_ssl(),
      'httponly' => TRUE,
      'samesite' => 'Lax',
    ]);
  }

  private static function clearCookie(array $form): void {
    setcookie(self::cookieName($form), '', ['expires' => time() - DAY_IN_SECONDS, 'path' => '/', 'secure' => is_ssl(), 'httponly' => TRUE, 'samesite' => 'Lax']);
  }

  private function login(array $form): void {
    $ip = RateLimit::clientIp();
    // 10 wrong passwords an hour from one address, 50 for the form overall.
    $blocked = RateLimit::exceeded('sheet_login_' . $form['id'], $ip, 10, HOUR_IN_SECONDS)
      || RateLimit::exceeded('sheet_login_form_' . $form['id'], 'all', 50, HOUR_IN_SECONDS);
    if ($blocked) {
      $this->gate($form, __('Too many wrong passwords. Try again in an hour.', 'embed-forms'));
      return;
    }
    $password = (string) wp_unslash($_POST['password'] ?? '');
    if ($password !== '' && wp_check_password($password, $form['settings']['sheet']['password_hash'])) {
      self::setCookie($form);
      wp_safe_redirect(self::url($form));
      return;
    }
    RateLimit::hit('sheet_login_' . $form['id'], $ip, PHP_INT_MAX, HOUR_IN_SECONDS);
    RateLimit::hit('sheet_login_form_' . $form['id'], 'all', PHP_INT_MAX, HOUR_IN_SECONDS);
    status_header(403);
    $this->gate($form, __('That password is not right.', 'embed-forms'));
  }

  private function gate(array $form, string $error = ''): void {
    ob_start();
    ?>
    <form class="sh-gate" method="post" action="<?php echo esc_url(self::url($form)); ?>">
      <h1><?php echo esc_html($form['title']); ?></h1>
      <p><?php esc_html_e('These entries are private. Enter the password you were given.', 'embed-forms'); ?></p>
      <input type="hidden" name="ef_action" value="login">
      <label for="sh-password"><?php esc_html_e('Password', 'embed-forms'); ?></label>
      <input type="password" id="sh-password" name="password" autocomplete="current-password" required autofocus<?php echo $error !== '' ? ' aria-invalid="true" aria-describedby="sh-error"' : ''; ?>>
      <?php if ($error !== '') : ?>
        <p class="sh-error" id="sh-error" role="alert"><?php echo esc_html($error); ?></p>
      <?php endif; ?>
      <button type="submit"><?php esc_html_e('Open entries', 'embed-forms'); ?></button>
    </form>
    <?php
    $this->shell($form['title'], $form, (string) ob_get_clean());
  }

  // ------------------------------------------------------------- sheet

  /**
   * The rows to show, newest first.
   *
   * @return array<int, array{entry: array, cells: array}>
   */
  private static function rows(array $form, array $columns, array $inputs): array {
    $entries = [];
    foreach (Entries::each(['form_id' => $form['id'], 'status' => '']) as $entry) {
      if (in_array($entry['status'], self::STATUSES, TRUE)) {
        $entries[] = $entry;
      }
    }
    $entries = array_slice(array_reverse($entries), 0, self::MAX_ROWS);
    $rows = [];
    foreach ($entries as $entry) {
      $payment = self::charge((int) $entry['id']);
      $date = get_date_from_gmt((string) $entry['created_at'], 'Y-m-d H:i');
      $cells = [];
      foreach ($columns as $column) {
        $cells[] = Grid::cell($column, $entry, $inputs, $date, $payment);
      }
      $rows[] = ['entry' => $entry, 'cells' => $cells];
    }
    return $rows;
  }

  private static function charge(int $entryId): ?array {
    foreach (Payments::forEntry($entryId) as $payment) {
      if ($payment['kind'] === 'charge') {
        return $payment;
      }
    }
    return NULL;
  }

  private function sheet(array $form): void {
    $inputs = FormSchema::inputs($form['schema']);
    $columns = Grid::columns($inputs);
    $rows = self::rows($form, $columns, $inputs);
    $editable = !empty($form['settings']['sheet']['editable']);
    $download = static fn(string $kind) => add_query_arg(['ef_action' => $kind], self::url($form));
    ob_start();
    ?>
    <header class="sh-bar">
      <div class="sh-title">
        <h1><?php echo esc_html($form['title']); ?></h1>
        <p><span class="sh-count" data-total="<?php echo count($rows); ?>"><?php echo esc_html(sprintf(_n('%s entry', '%s entries', count($rows), 'embed-forms'), number_format_i18n(count($rows)))); ?></span>
          <?php echo $editable ? esc_html__('Click a white cell to change it. Shaded columns are fixed.', 'embed-forms') : esc_html__('View only.', 'embed-forms'); ?></p>
      </div>
      <div class="sh-tools">
        <input type="search" class="sh-search" placeholder="<?php esc_attr_e('Find in entries', 'embed-forms'); ?>" aria-label="<?php esc_attr_e('Find in entries', 'embed-forms'); ?>">
        <a class="sh-button" href="<?php echo esc_url($download('csv')); ?>"><?php esc_html_e('Download CSV', 'embed-forms'); ?></a>
        <?php if (Xlsx::available()) : ?>
          <a class="sh-button" href="<?php echo esc_url($download('xlsx')); ?>"><?php esc_html_e('Download Excel', 'embed-forms'); ?></a>
        <?php endif; ?>
        <a class="sh-button sh-quiet" href="<?php echo esc_url($download('logout')); ?>"><?php esc_html_e('Sign out', 'embed-forms'); ?></a>
      </div>
    </header>
    <?php if (!$rows) : ?>
      <p class="sh-empty"><?php esc_html_e('No entries yet. They appear here as soon as someone submits the form.', 'embed-forms'); ?></p>
    <?php else : ?>
      <div class="sh-scroll" tabindex="0" aria-label="<?php esc_attr_e('Entries', 'embed-forms'); ?>">
        <table class="sh-grid<?php echo $editable ? ' is-editable' : ''; ?>">
          <thead><tr>
            <?php foreach ($columns as $column) : ?>
              <th scope="col" class="<?php echo $editable && $column['edit'] !== NULL ? '' : 'is-fixed'; ?>"><?php echo esc_html($column['label']); ?></th>
            <?php endforeach; ?>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $row) : ?>
              <tr data-entry="<?php echo (int) $row['entry']['id']; ?>">
                <?php foreach ($columns as $i => $column) :
                  $cell = $row['cells'][$i];
                  $classes = [];
                  if ($editable && $column['edit'] !== NULL) {
                    $classes[] = 'is-edit';
                  }
                  else {
                    $classes[] = 'is-fixed';
                  }
                  if ($column['numeric']) {
                    $classes[] = 'is-num';
                  }
                  if ($column['key'] === 'status') {
                    $classes[] = 'sh-status sh-status-' . sanitize_html_class($cell['text']);
                  }
                  ?>
                  <td class="<?php echo esc_attr(implode(' ', $classes)); ?>" data-col="<?php echo (int) $i; ?>"<?php echo $column['edit'] === 'select' ? ' data-value="' . esc_attr($cell['value']) . '"' : ''; ?><?php echo $editable && $column['edit'] !== NULL ? ' tabindex="0"' : ''; ?> title="<?php echo esc_attr($cell['text']); ?>"><?php echo esc_html($cell['text']); ?></td>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="sh-toast" role="status" aria-live="polite" hidden></div>
    <?php endif; ?>
    <?php
    $config = [
      'url' => self::url($form),
      'csrf' => self::csrf($form),
      'editable' => $editable,
      'columns' => array_map(static fn($c) => ['key' => $c['key'], 'label' => $c['label'], 'edit' => $editable ? $c['edit'] : NULL, 'options' => $c['options']], $columns),
      'strings' => [
        'saved' => __('Saved', 'embed-forms'),
        'notSaved' => __('Not saved: %s', 'embed-forms'),
        'offline' => __('Not saved: the site could not be reached. Check your connection and try again.', 'embed-forms'),
        'choose' => __('(none)', 'embed-forms'),
        /* translators: 1: rows shown, 2: all rows */
        'showing' => __('%1$s of %2$s entries', 'embed-forms'),
      ],
    ];
    $this->shell($form['title'], $form, (string) ob_get_clean(), $config);
  }

  private function save(array $form): void {
    $sent = (string) ($_SERVER['HTTP_X_EF_SHEET'] ?? '');
    if (!hash_equals(self::csrf($form), $sent)) {
      wp_send_json(['ok' => FALSE, 'message' => __('Reload the page and try again.', 'embed-forms')], 403);
    }
    if (empty($form['settings']['sheet']['editable'])) {
      wp_send_json(['ok' => FALSE, 'message' => __('This sheet is view only.', 'embed-forms')], 403);
    }
    if (!RateLimit::hit('sheet_save_' . $form['id'], RateLimit::clientIp(), 600, HOUR_IN_SECONDS)) {
      wp_send_json(['ok' => FALSE, 'message' => __('Too many changes at once. Wait a few minutes.', 'embed-forms')], 429);
    }
    $post = wp_unslash($_POST);
    $entry = Entries::find((int) ($post['entry'] ?? 0));
    if (!$entry || (int) $entry['form_id'] !== (int) $form['id'] || !in_array($entry['status'], self::STATUSES, TRUE)) {
      wp_send_json(['ok' => FALSE, 'message' => __('This entry is no longer here. Reload the page.', 'embed-forms')], 404);
    }
    $inputs = FormSchema::inputs($form['schema']);
    $column = NULL;
    foreach (Grid::columns($inputs) as $c) {
      if ($c['key'] === (string) ($post['column'] ?? '')) {
        $column = $c;
      }
    }
    if (!$column || $column['edit'] === NULL) {
      wp_send_json(['ok' => FALSE, 'message' => __('This column cannot be changed here.', 'embed-forms')], 400);
    }
    $payment = self::charge((int) $entry['id']);
    $date = get_date_from_gmt((string) $entry['created_at'], 'Y-m-d H:i');
    $current = Grid::cell($column, $entry, $inputs, $date, $payment);
    if ($current['text'] !== (string) ($post['before'] ?? '')) {
      wp_send_json(['ok' => FALSE, 'conflict' => TRUE, 'message' => __('Someone else changed this cell a moment ago; it now shows their answer.', 'embed-forms')] + $current, 409);
    }
    $value = mb_substr((string) ($post['value'] ?? ''), 0, 5000);
    $result = Grid::change(Forms::schemaAt($form, $entry['form_version']), $entry['data'], $column, $value);
    if (!$result['ok']) {
      wp_send_json(['ok' => FALSE, 'message' => $result['message']] + $current, 422);
    }
    $entry['data'] = $result['values'];
    $after = Grid::cell($column, $entry, $inputs, $date, $payment);
    if ($after['text'] !== $current['text']) {
      Entries::update((int) $entry['id'], [
        'data' => $result['values'],
        'payer_email' => \EmbedForms\Submit\SubmissionHandler::payerEmail($form, $result['values']) ?: $entry['payer_email'],
      ]);
      Notes::add((int) $entry['id'], sprintf(__('Changed on the shared entries page: %1$s from "%2$s" to "%3$s".', 'embed-forms'), $column['label'], $current['text'], $after['text']));
      /** This hook is documented in src/Admin/EntryEditor.php */
      do_action('embed_forms_entry_edited', Entries::find((int) $entry['id']), $form, [$column['label']]);
      do_action('embed_forms_entry_changed', (int) $form['id']);
    }
    wp_send_json(['ok' => TRUE] + $after);
  }

  private function download(array $form, string $kind): void {
    $inputs = FormSchema::inputs($form['schema']);
    $columns = Grid::columns($inputs);
    $header = array_column($columns, 'label');
    $rows = [];
    foreach (self::rows($form, $columns, $inputs) as $row) {
      $out = [];
      foreach ($columns as $i => $column) {
        $text = $row['cells'][$i]['text'];
        if ($column['key'] === 'id') {
          $out[] = (int) $text;
        }
        elseif ($column['key'] === 'amount' && $text !== '') {
          $out[] = (float) $text;
        }
        else {
          $out[] = $text;
        }
      }
      $rows[] = $out;
    }
    $name = sanitize_file_name(($form['slug'] ?: 'form') . '-entries-' . wp_date('Y-m-d'));
    if ($kind === 'xlsx' && Xlsx::available()) {
      $file = (string) tempnam(get_temp_dir(), 'efx');
      Xlsx::write($file, $form['title'], $header, $rows);
      header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
      header('Content-Disposition: attachment; filename="' . $name . '.xlsx"');
      header('Content-Length: ' . filesize($file));
      readfile($file);
      @unlink($file);
      return;
    }
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $name . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, $header);
    foreach ($rows as $row) {
      fputcsv($out, array_map(static fn($v) => is_string($v) ? CsvExport::cell($v) : $v, $row));
    }
    fclose($out);
  }

  /**
   * A bare page with the sheet's stylesheet and, given a config, its script.
   */
  private function shell(string $title, ?array $form, string $body, ?array $config = NULL): void {
    $plugin = Plugin::instance();
    $settings = $form['settings'] ?? [];
    $accent = ($settings['accent'] ?? '') === 'custom' && ($settings['accent_color'] ?? '') !== '' ? $settings['accent_color'] : (self::ACCENTS[$settings['accent'] ?? 'blue'] ?? self::ACCENTS['blue']);
    $vars = '';
    foreach (Design::accentVariables($accent) as $name => $value) {
      $vars .= $name . ':' . $value . ';';
    }
    ?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<title><?php echo esc_html($title); ?></title>
<link rel="preload" href="<?php echo esc_url($plugin->url('assets/fonts/Geist-Variable.woff2')); ?>" as="font" type="font/woff2" crossorigin>
<link rel="stylesheet" href="<?php echo esc_url(add_query_arg('ver', $plugin->assetVersion('assets/sheet.css'), $plugin->url('assets/sheet.css'))); ?>">
<style>:root{<?php echo str_replace(['<', '>'], '', $vars); // Validated colours only. ?>}
@font-face{font-family:Geist;src:url("<?php echo esc_url($plugin->url('assets/fonts/Geist-Variable.woff2')); ?>") format("woff2");font-weight:100 900;font-display:swap}</style>
</head>
<body class="sh-body">
<?php echo $body; // Built from escaped parts. ?>
<?php if ($config !== NULL) : ?>
<script>window.EmbedFormsSheet = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<script src="<?php echo esc_url(add_query_arg('ver', $plugin->assetVersion('assets/sheet.js'), $plugin->url('assets/sheet.js'))); ?>"></script>
<?php endif; ?>
</body>
</html>
<?php
  }

  // ------------------------------------------------------------- admin

  public function editorSettings(array $form): void {
    $sh = $form['settings']['sheet'];
    $key = 'ef_sheet_notice_' . get_current_user_id();
    $notice = get_transient($key);
    delete_transient($key);
    Sections::open('sheet', __('Shared entries page', 'embed-forms'), '', 'settings[sheet][enabled]');
    ?>
    <p class="ef-section-intro"><?php esc_html_e('A page with this form\'s entries as a sheet, for people without a WordPress login. They need the link and the password.', 'embed-forms'); ?></p>
    <?php if (is_string($notice) && $notice !== '') : ?>
      <div class="notice notice-warning inline"><p><?php echo esc_html($notice); ?></p></div>
    <?php endif; ?>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Share', 'embed-forms'); ?></th>
        <td>
          <input type="hidden" name="settings[sheet][enabled]" value="0">
          <label><input type="checkbox" name="settings[sheet][enabled]" value="1" <?php checked($sh['enabled']); ?>> <?php esc_html_e('Turn on the shared entries page', 'embed-forms'); ?></label>
          <p class="description"><?php esc_html_e('Anyone with the link and password sees every entry: names, emails, addresses and amounts. Give it only to people who may see donors\' details.', 'embed-forms'); ?></p>
        </td>
      </tr>
      <tr data-ef-show-if="settings[sheet][enabled]">
        <th scope="row"><label for="ef-sheet-password"><?php esc_html_e('Password', 'embed-forms'); ?></label></th>
        <td><input type="password" id="ef-sheet-password" name="settings[sheet][password]" value="" class="regular-text" autocomplete="new-password" minlength="10" placeholder="<?php echo $sh['password_hash'] !== '' ? esc_attr__('Saved (leave empty to keep)', 'embed-forms') : esc_attr__('At least 10 characters', 'embed-forms'); ?>">
          <p class="description"><?php esc_html_e('At least 10 characters. A new password signs everyone out.', 'embed-forms'); ?></p></td>
      </tr>
      <tr data-ef-show-if="settings[sheet][enabled]">
        <th scope="row"><?php esc_html_e('Changes', 'embed-forms'); ?></th>
        <td>
          <input type="hidden" name="settings[sheet][editable]" value="0">
          <label><input type="checkbox" name="settings[sheet][editable]" value="1" <?php checked($sh['editable']); ?>> <?php esc_html_e('Allow changing answers in the sheet', 'embed-forms'); ?></label>
          <p class="description"><?php esc_html_e('Each change is saved in the entry\'s history. Payments, amounts and status cannot be changed there.', 'embed-forms'); ?></p>
        </td>
      </tr>
      <?php if ($sh['token'] !== '') : ?>
        <tr data-ef-show-if="settings[sheet][enabled]">
          <th scope="row"><?php esc_html_e('Link', 'embed-forms'); ?></th>
          <td>
            <div class="ef-code-row">
              <input type="text" readonly class="large-text code" value="<?php echo esc_attr(self::url($form)); ?>" onclick="this.select()">
              <button type="button" class="button ef-copy"><?php esc_html_e('Copy', 'embed-forms'); ?></button>
              <a class="button" href="<?php echo esc_url(self::url($form)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open', 'embed-forms'); ?></a>
            </div>
            <label><input type="checkbox" name="settings[sheet][new_link]" value="1"> <?php esc_html_e('Make a new link (the old one stops working and everyone is signed out)', 'embed-forms'); ?></label>
          </td>
        </tr>
      <?php else : ?>
        <tr data-ef-show-if="settings[sheet][enabled]">
          <th scope="row"><?php esc_html_e('Link', 'embed-forms'); ?></th>
          <td><p class="description"><?php esc_html_e('Set a password and save: the link appears here.', 'embed-forms'); ?></p></td>
        </tr>
      <?php endif; ?>
    </table>
    <?php
    Sections::close();
  }

  /**
   * Hash a new password and make the link when the page is turned on.
   */
  public function saveSettings(array $settings, array $post, ?array $form): array {
    if (!isset($settings['sheet']) || !is_array($settings['sheet'])) {
      return $settings;
    }
    $posted = $settings['sheet'];
    $sheet = ($form['settings']['sheet'] ?? []) + ['token' => '', 'password_hash' => ''];
    $sheet['enabled'] = !empty($posted['enabled']);
    $sheet['editable'] = !empty($posted['editable']);
    $notice = '';
    $password = (string) ($posted['password'] ?? '');
    if ($password !== '') {
      if (mb_strlen($password) >= 10) {
        $sheet['password_hash'] = wp_hash_password($password);
      }
      else {
        $notice = __('The new password was not saved: use at least 10 characters.', 'embed-forms');
      }
    }
    if (($sheet['enabled'] && $sheet['token'] === '') || !empty($posted['new_link'])) {
      $sheet['token'] = bin2hex(random_bytes(16));
    }
    if ($sheet['enabled'] && $sheet['password_hash'] === '') {
      $sheet['enabled'] = FALSE;
      $notice = $notice ?: __('The shared entries page stays off until it has a password.', 'embed-forms');
    }
    if ($notice !== '') {
      set_transient('ef_sheet_notice_' . get_current_user_id(), $notice, 300);
    }
    $settings['sheet'] = $sheet;
    return $settings;
  }

}
