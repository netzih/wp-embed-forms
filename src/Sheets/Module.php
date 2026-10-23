<?php

namespace EmbedForms\Sheets;

use EmbedForms\Admin\Sections;
use EmbedForms\Admin\Menu;
use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Payments;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Settings;

/**
 * Google Sheets: a row in a spreadsheet for every new entry of a form.
 *
 * - A new entry (paid, when the form takes payments; offline donations
 *   too) is sent from WP-Cron right after the submission, so the person
 *   never waits for Google. A failed send is tried again 4 more times over
 *   about an hour and a half; the last result shows in the editor.
 * - Columns follow the sheet's header row by name (Rows); the header is
 *   written when the sheet is empty and gains a column for a new field.
 * - "Send all entries" in the editor sends the form's entries that are not
 *   in the sheet yet (by the Entry column), e.g. those made before.
 */
final class Module {

  public const CRON = 'embed_forms_sheets_send';

  public const STATUS_OPTION = 'embed_forms_sheets_status';

  public const MAX_ATTEMPTS = 5;

  /**
   * Entry statuses that are sent; failed and unfinished payments are not.
   */
  private const SENT_STATUSES = ['submitted', 'paid', 'refunded'];

  public function register(): void {
    add_action('embed_forms_entry_submitted', [$this, 'queue'], 20, 2);
    add_action(self::CRON, [$this, 'run'], 10, 2);
    if (is_admin()) {
      add_action('embed_forms_editor_settings', [$this, 'editorSettings'], 30);
      add_action('admin_post_embed_forms_sheets_send_all', [$this, 'sendAllAction']);
    }
  }

  public static function active(array $form): bool {
    return !empty($form['settings']['sheets']['enabled']) && $form['settings']['sheets']['spreadsheet'] !== '';
  }

  public function queue(array $entry, array $form): void {
    if (!self::active($form) || !in_array($entry['status'], self::SENT_STATUSES, TRUE)) {
      return;
    }
    wp_schedule_single_event(time(), self::CRON, [(int) $entry['id'], 1]);
  }

  public function run(int $entryId, int $attempt = 1): void {
    $entry = Entries::find($entryId);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if (!$entry || !$form || !self::active($form)) {
      return;
    }
    try {
      self::send($form, [$entry]);
      self::recordStatus((int) $form['id'], TRUE, sprintf(__('Entry %d sent.', 'embed-forms'), $entryId));
    }
    catch (\Throwable $e) {
      $retry = $attempt < self::MAX_ATTEMPTS;
      error_log(sprintf('[embed-forms] Google Sheets: entry %d not sent (attempt %d of %d): %s', $entryId, $attempt, self::MAX_ATTEMPTS, $e->getMessage()));
      self::recordStatus((int) $form['id'], FALSE, sprintf(
        $retry ? __('Entry %1$d not sent yet (attempt %2$d of %3$d, trying again): %4$s', 'embed-forms') : __('Entry %1$d not sent after %3$d attempts: %4$s Use "Send all entries" once it is fixed.', 'embed-forms'),
        $entryId, $attempt, self::MAX_ATTEMPTS, $e->getMessage()
      ));
      if ($retry) {
        // 5, 20, 45 and 80 minutes later.
        wp_schedule_single_event(time() + $attempt * $attempt * 5 * MINUTE_IN_SECONDS, self::CRON, [$entryId, $attempt + 1]);
      }
    }
  }

  /**
   * Add entries to the form's sheet, skipping those already in it.
   *
   * @return array{sent: int, skipped: int}
   *
   * @throws \RuntimeException
   */
  public static function send(array $form, array $entries, ?Client $client = NULL): array {
    $client ??= Client::fromSettings();
    $spreadsheet = $form['settings']['sheets']['spreadsheet'];
    $tab = $form['settings']['sheets']['tab'] !== '' ? $form['settings']['sheets']['tab'] : $client->firstTab($spreadsheet);
    $inputs = FormSchema::inputs($form['schema']);
    $ours = Rows::headers($inputs);

    $existing = $client->get($spreadsheet, Rows::range($tab, '1:1'))[0] ?? [];
    $header = Rows::mergeHeader($existing, $ours);
    if ($header !== Rows::mergeHeader($existing, [])) {
      $client->put($spreadsheet, Rows::range($tab, 'A1'), [$header]);
    }

    $entryColumn = Rows::columnLetter((int) array_search('entry', array_map('strtolower', $header), TRUE));
    $inSheet = [];
    foreach ($client->get($spreadsheet, Rows::range($tab, $entryColumn . '2:' . $entryColumn)) as $cells) {
      $inSheet[(int) ($cells[0] ?? 0)] = TRUE;
    }

    $rows = [];
    foreach ($entries as $entry) {
      if (isset($inSheet[(int) $entry['id']])) {
        continue;
      }
      $inSheet[(int) $entry['id']] = TRUE;
      $date = function_exists('get_date_from_gmt') ? get_date_from_gmt((string) $entry['created_at'], 'Y-m-d H:i') : (string) $entry['created_at'];
      $rows[] = Rows::align($header, $ours, Rows::values($entry, $inputs, $date, self::charge((int) $entry['id'])));
    }
    foreach (array_chunk($rows, 500) as $chunk) {
      $client->append($spreadsheet, Rows::range($tab, 'A1'), $chunk);
    }
    return ['sent' => count($rows), 'skipped' => count($entries) - count($rows)];
  }

  private static function charge(int $entryId): ?array {
    foreach (Payments::forEntry($entryId) as $payment) {
      if ($payment['kind'] === 'charge') {
        return $payment;
      }
    }
    return NULL;
  }

  private static function recordStatus(int $formId, bool $ok, string $message): void {
    $all = get_option(self::STATUS_OPTION, []);
    $all = is_array($all) ? $all : [];
    $all[$formId] = ['ok' => $ok, 'at' => time(), 'message' => $message];
    update_option(self::STATUS_OPTION, $all, FALSE);
  }

  public function sendAllAction(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_sheets_send_all');
    $form = Forms::find((int) ($_GET['form'] ?? 0));
    if (!$form) {
      wp_die(esc_html__('Form not found.', 'embed-forms'));
    }
    if ($form['settings']['sheets']['spreadsheet'] === '') {
      Menu::flash(__('Add the spreadsheet link under Google Sheets and save the form first.', 'embed-forms'), 'error');
    }
    else {
      if (function_exists('set_time_limit')) {
        @set_time_limit(300);
      }
      $entries = [];
      foreach (Entries::each(['form_id' => $form['id'], 'status' => '']) as $entry) {
        if (in_array($entry['status'], self::SENT_STATUSES, TRUE)) {
          $entries[] = $entry;
        }
      }
      try {
        $result = self::send($form, $entries);
        $message = sprintf(__('Sent %1$d entries to Google Sheets; %2$d were already there.', 'embed-forms'), $result['sent'], $result['skipped']);
        self::recordStatus((int) $form['id'], TRUE, $message);
        Menu::flash($message);
      }
      catch (\Throwable $e) {
        self::recordStatus((int) $form['id'], FALSE, $e->getMessage());
        Menu::flash(sprintf(__('Not sent: %s', 'embed-forms'), $e->getMessage()), 'error');
      }
    }
    wp_safe_redirect(Menu::formsUrl(['form' => $form['id'], 'tab' => 'settings', 'section' => 'sheets']));
    exit;
  }

  public function editorSettings(array $form): void {
    $sh = $form['settings']['sheets'];
    $account = Settings::googleServiceAccount();
    $status = get_option(self::STATUS_OPTION, [])[$form['id']] ?? NULL;
    ?>
    <?php Sections::open('sheets', __('Google Sheets', 'embed-forms'), '', 'settings[sheets][enabled]'); ?>
    <?php if (!$account) : ?>
      <p class="description"><?php printf(esc_html__('Connect Google once in %s (about 10 minutes, step by step), then come back here.', 'embed-forms'), '<a href="' . esc_url(admin_url('admin.php?page=' . Menu::PAGE_SETTINGS . '&section=sheets')) . '">' . esc_html__('Embed Forms > Settings', 'embed-forms') . '</a>'); ?></p>
    <?php endif; ?>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Send', 'embed-forms'); ?></th>
        <td>
          <input type="hidden" name="settings[sheets][enabled]" value="0">
          <label><input type="checkbox" name="settings[sheets][enabled]" value="1" <?php checked($sh['enabled']); ?>> <?php esc_html_e('Add a row to a Google Sheet for every new entry', 'embed-forms'); ?></label>
          <p class="description"><?php esc_html_e('Each entry is sent a moment after it is submitted (or paid), offline donations too. Later edits and refunds are not sent.', 'embed-forms'); ?></p>
        </td>
      </tr>
      <tr data-ef-show-if="settings[sheets][enabled]">
        <th scope="row"><label for="ef-sheet"><?php esc_html_e('Spreadsheet', 'embed-forms'); ?></label></th>
        <td><input type="text" id="ef-sheet" name="settings[sheets][spreadsheet]" value="<?php echo esc_attr($sh['spreadsheet'] !== '' ? 'https://docs.google.com/spreadsheets/d/' . $sh['spreadsheet'] . '/edit' : ''); ?>" class="large-text code" placeholder="https://docs.google.com/spreadsheets/d/…">
          <p class="description">
            <?php if ($account) : ?>
              <?php printf(esc_html__('The spreadsheet\'s link. Share it with %s as Editor.', 'embed-forms'), '<code>' . esc_html($account['client_email']) . '</code>'); ?>
            <?php else : ?>
              <?php esc_html_e('The spreadsheet\'s link.', 'embed-forms'); ?>
            <?php endif; ?>
            <?php if ($sh['spreadsheet'] !== '') : ?>
              <a href="<?php echo esc_url('https://docs.google.com/spreadsheets/d/' . $sh['spreadsheet'] . '/edit'); ?>" target="_blank" rel="noopener"><?php esc_html_e('Open the spreadsheet', 'embed-forms'); ?></a>
            <?php endif; ?>
          </p></td>
      </tr>
      <tr data-ef-show-if="settings[sheets][enabled]">
        <th scope="row"><label for="ef-sheet-tab"><?php esc_html_e('Tab', 'embed-forms'); ?></label></th>
        <td><input type="text" id="ef-sheet-tab" name="settings[sheets][tab]" value="<?php echo esc_attr($sh['tab']); ?>" class="regular-text" placeholder="<?php esc_attr_e('The first tab', 'embed-forms'); ?>">
          <p class="description"><?php esc_html_e('As named at the bottom of the sheet. Row 1 gets the column names; you can reorder columns or add your own, but keep the names.', 'embed-forms'); ?></p></td>
      </tr>
      <?php if ($account && $sh['spreadsheet'] !== '') : ?>
        <tr data-ef-show-if="settings[sheets][enabled]">
          <th scope="row"><?php esc_html_e('Earlier entries', 'embed-forms'); ?></th>
          <td><a class="button" href="<?php echo esc_url(Menu::actionUrl('embed_forms_sheets_send_all', ['form' => $form['id']])); ?>"><?php esc_html_e('Send all entries', 'embed-forms'); ?></a>
            <p class="description"><?php esc_html_e('Adds entries that are not in the sheet yet; safe to click again. Save the form first if you changed the link.', 'embed-forms'); ?></p></td>
        </tr>
      <?php endif; ?>
      <?php if (is_array($status)) : ?>
        <tr data-ef-show-if="settings[sheets][enabled]">
          <th scope="row"><?php esc_html_e('Last result', 'embed-forms'); ?></th>
          <td><span style="color:<?php echo $status['ok'] ? '#00762f' : '#b32d2e'; ?>"><?php echo esc_html($status['message']); ?></span>
            <span class="description">(<?php echo esc_html(wp_date('Y-m-d H:i', (int) $status['at'])); ?>)</span></td>
        </tr>
      <?php endif; ?>
    </table>
    <?php Sections::close(); ?>
    <?php
  }

}
