<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Entries;
use EmbedForms\Db\Forms;
use EmbedForms\Db\Notes;
use EmbedForms\Payments\Money;
use EmbedForms\Schema\Display;
use EmbedForms\Schema\Fields;
use EmbedForms\Schema\FormSchema;
use EmbedForms\Schema\Validator;

/**
 * Entries > Edit (?entry=ID&edit=1) and Entries > Add offline donation
 * (?form=ID&add=1): the form's fields as a plain admin form.
 *
 * Editing changes the answers (each change is written to the history) but
 * never a card payment: the amount, product and frequency of an entry paid
 * online stay as charged; refunds are on the entry screen. An offline
 * donation's amount, method, reference and date can be changed. Required
 * fields may be left empty here: an administrator enters what they have.
 */
final class EntryEditor {

  /**
   * @var array<string, string>
   */
  private array $errors = [];

  private array $posted = [];

  public static function editUrl(int $entryId): string {
    return Menu::entriesUrl(['entry' => $entryId, 'edit' => 1]);
  }

  public static function addUrl(int $formId): string {
    return Menu::entriesUrl(['form' => $formId, 'add' => 1]);
  }

  public static function wants(): bool {
    return (!empty($_GET['entry']) && !empty($_GET['edit'])) || (!empty($_GET['form']) && !empty($_GET['add']));
  }

  /**
   * Saves a posted editor form (on the screen's load hook, before output),
   * redirecting on success and keeping the errors for render() otherwise.
   */
  public function load(): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !isset($_POST['ef_entry_editor'])) {
      return;
    }
    Menu::requireCapability();
    $post = wp_unslash($_POST);
    $this->posted = $post;
    if (!empty($_GET['add'])) {
      check_admin_referer('embed_forms_add_offline');
      $this->create((int) $_GET['form'], $post);
    }
    else {
      check_admin_referer('embed_forms_edit_entry_' . (int) $_GET['entry']);
      $this->save((int) $_GET['entry'], $post);
    }
  }

  private function create(int $formId, array $post): void {
    $form = Forms::find($formId);
    if (!$form) {
      wp_die(esc_html__('Form not found.', 'embed-forms'));
    }
    [$details, $detailErrors] = OfflineDonations::details((array) ($post['offline'] ?? []));
    $result = $this->validate($form['schema'], (array) ($post['values'] ?? []), $details['amount']);
    $this->errors = $result['errors'] + array_combine(array_map(static fn($k) => 'offline_' . $k, array_keys($detailErrors)), $detailErrors);
    if ($this->errors) {
      return;
    }
    $entryId = OfflineDonations::record($form, $result['values'], $details);
    if (!$entryId) {
      $this->errors['_'] = __('The donation could not be saved. Please try again.', 'embed-forms');
      return;
    }
    Menu::flash(sprintf(__('Offline donation of %s added.', 'embed-forms'), Money::format($details['amount'])));
    wp_safe_redirect(!empty($post['add_another']) ? self::addUrl($formId) : Menu::entriesUrl(['entry' => $entryId]));
    exit;
  }

  private function save(int $entryId, array $post): void {
    $entry = Entries::find($entryId);
    $form = $entry ? Forms::find($entry['form_id']) : NULL;
    if (!$entry || !$form) {
      wp_die(esc_html__('Entry not found.', 'embed-forms'));
    }
    $schema = Forms::schemaAt($form, $entry['form_version']);
    $offline = OfflineDonations::paymentOf($entryId);
    $details = NULL;
    $detailErrors = [];
    if ($offline) {
      [$details, $detailErrors] = OfflineDonations::details((array) ($post['offline'] ?? []));
    }
    $input = (array) ($post['values'] ?? []);
    if (!$offline) {
      // Payment answers stay as they were charged.
      foreach (FormSchema::inputs($schema) as $id => $field) {
        if (in_array($field['type'], ['amount', 'product', 'frequency'], TRUE) && array_key_exists($id, $entry['data'])) {
          $input[$id] = $entry['data'][$id];
        }
      }
    }
    $result = $this->validate($schema, $input, $details['amount'] ?? NULL);
    $this->errors = $result['errors'] + array_combine(array_map(static fn($k) => 'offline_' . $k, array_keys($detailErrors)), $detailErrors);
    if ($this->errors) {
      return;
    }
    $values = $result['values'];
    if (!$offline) {
      // A field hidden by its conditions keeps no answer, except payment
      // answers, which describe what was charged.
      foreach (FormSchema::inputs($schema) as $id => $field) {
        if (in_array($field['type'], ['amount', 'product', 'frequency'], TRUE) && array_key_exists($id, $entry['data'])) {
          $values[$id] = $entry['data'][$id];
        }
      }
    }

    $changed = [];
    foreach (FormSchema::inputs($schema) as $id => $field) {
      $before = Display::text($field, $entry['data'][$id] ?? NULL);
      $after = Display::text($field, $values[$id] ?? NULL);
      if ($before !== $after) {
        $changed[] = $field['label'] !== '' ? $field['label'] : $id;
      }
    }
    $paymentChanges = $offline ? OfflineDonations::update($entry, $offline, $details) : [];

    if ($changed || $paymentChanges) {
      Entries::update($entryId, [
        'data' => $values,
        'payer_email' => \EmbedForms\Submit\SubmissionHandler::payerEmail($form, $values) ?: $entry['payer_email'],
      ]);
      $parts = array_merge($changed, $paymentChanges);
      Notes::add($entryId, sprintf(__('Edited by %1$s: %2$s.', 'embed-forms'), OfflineDonations::userName(), implode(', ', $parts)));
      /**
       * An administrator changed an entry's answers or offline payment.
       */
      do_action('embed_forms_entry_edited', Entries::find($entryId), $form, $parts);
      do_action('embed_forms_entry_changed', (int) $form['id']);
      Menu::flash(__('Entry saved.', 'embed-forms'));
    }
    else {
      Menu::flash(__('Nothing changed.', 'embed-forms'), 'info');
    }
    wp_safe_redirect(Menu::entriesUrl(['entry' => $entryId]));
    exit;
  }

  /**
   * @return array{values: array, errors: array<string, string>}
   */
  private function validate(array $schema, array $input, ?string $offlineAmount): array {
    if ($offlineAmount !== NULL) {
      $input = OfflineDonations::withAmount($schema, $input, $offlineAmount !== '' ? $offlineAmount : '0.00');
    }
    $result = Validator::validate($schema, $input, ['required' => FALSE, 'skip' => ['amount', 'product', 'frequency']]);
    if ($offlineAmount !== NULL && $offlineAmount !== '') {
      $result['values'] = OfflineDonations::withAmount($schema, $result['values'], $offlineAmount);
    }
    return $result;
  }

  // ------------------------------------------------------------- screen

  public function render(): void {
    $adding = !empty($_GET['add']);
    if ($adding) {
      $form = Forms::find((int) $_GET['form']);
      $entry = NULL;
      $schema = $form['schema'] ?? NULL;
    }
    else {
      $entry = Entries::find((int) $_GET['entry']);
      $form = $entry ? Forms::find($entry['form_id']) : NULL;
      $schema = $form ? Forms::schemaAt($form, $entry['form_version']) : NULL;
    }
    if (!$form || $schema === NULL) {
      echo '<div class="wrap"><h1>' . esc_html__('Not found', 'embed-forms') . '</h1></div>';
      return;
    }
    $offline = $entry ? OfflineDonations::paymentOf($entry['id']) : NULL;
    $values = $this->posted ? (array) ($this->posted['values'] ?? []) : ($entry['data'] ?? []);
    $back = $entry ? Menu::entriesUrl(['entry' => $entry['id']]) : Menu::entriesUrl(['form' => $form['id']]);
    $paymentTypes = ['amount', 'product', 'frequency'];
    $hasPayment = (bool) array_intersect(array_column($schema['fields'], 'type'), Fields::PAYMENT_TYPES);
    ?>
    <div class="wrap ef-entry ef-entry-editor">
      <a class="ef-back" href="<?php echo esc_url($back); ?>">&larr; <?php echo esc_html($entry ? sprintf(__('Entry #%d', 'embed-forms'), $entry['id']) : sprintf(__('Entries of %s', 'embed-forms'), $form['title'])); ?></a>
      <h1><?php echo esc_html($adding ? sprintf(__('Add an offline donation to %s', 'embed-forms'), $form['title']) : sprintf(__('Edit entry #%d', 'embed-forms'), $entry['id'])); ?></h1>
      <?php if ($this->errors) : ?>
        <div class="notice notice-error"><p><?php echo esc_html($this->errors['_'] ?? __('Please correct the highlighted fields.', 'embed-forms')); ?></p></div>
      <?php endif; ?>
      <form method="post" novalidate>
        <input type="hidden" name="ef_entry_editor" value="1">
        <?php wp_nonce_field($adding ? 'embed_forms_add_offline' : 'embed_forms_edit_entry_' . $entry['id']); ?>

        <?php if ($adding || $offline) :
          $o = $this->posted['offline'] ?? ($offline ? [
            'amount' => $offline['amount'],
            'method' => $offline['method'],
            'reference' => $offline['refnum'],
            'date' => OfflineDonations::localDate($offline['created_at']),
          ] : ['amount' => '', 'method' => 'check', 'reference' => '', 'date' => wp_date('Y-m-d'), 'notify' => '']);
          ?>
          <h2><?php esc_html_e('Donation', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr class="<?php echo isset($this->errors['offline_amount']) ? 'ef-has-error' : ''; ?>">
              <th scope="row"><label for="ef-o-amount"><?php esc_html_e('Amount received', 'embed-forms'); ?></label></th>
              <td>$ <input type="text" inputmode="decimal" id="ef-o-amount" name="offline[amount]" value="<?php echo esc_attr((string) ($o['amount'] ?? '')); ?>" style="width:10em" required>
                <?php $this->error('offline_amount'); ?></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-o-method"><?php esc_html_e('Paid by', 'embed-forms'); ?></label></th>
              <td><select id="ef-o-method" name="offline[method]">
                <?php foreach (OfflineDonations::METHODS as $method) : ?>
                  <option value="<?php echo esc_attr($method); ?>" <?php selected($o['method'] ?? 'check', $method); ?>><?php echo esc_html(OfflineDonations::methodLabel($method)); ?></option>
                <?php endforeach; ?>
              </select>
              <label for="ef-o-ref" style="margin-left:12px"><?php esc_html_e('Reference', 'embed-forms'); ?></label>
              <input type="text" id="ef-o-ref" name="offline[reference]" value="<?php echo esc_attr((string) ($o['reference'] ?? '')); ?>" placeholder="<?php esc_attr_e('Check number, wire reference…', 'embed-forms'); ?>" class="regular-text"></td>
            </tr>
            <tr class="<?php echo isset($this->errors['offline_date']) ? 'ef-has-error' : ''; ?>">
              <th scope="row"><label for="ef-o-date"><?php esc_html_e('Received on', 'embed-forms'); ?></label></th>
              <td><input type="date" id="ef-o-date" name="offline[date]" value="<?php echo esc_attr((string) ($o['date'] ?? '')); ?>"><?php $this->error('offline_date'); ?></td>
            </tr>
            <?php if ($adding) : ?>
              <tr>
                <th scope="row"><label for="ef-o-note"><?php esc_html_e('Internal note', 'embed-forms'); ?></label></th>
                <td><textarea id="ef-o-note" name="offline[note]" rows="2" class="large-text"><?php echo esc_textarea((string) ($o['note'] ?? '')); ?></textarea></td>
              </tr>
              <tr>
                <th scope="row"><?php esc_html_e('Emails', 'embed-forms'); ?></th>
                <td><label><input type="checkbox" name="offline[notify]" value="1" <?php checked(!empty($o['notify'])); ?>> <?php esc_html_e('Send the form\'s emails now (notification, and the confirmation to the donor\'s email)', 'embed-forms'); ?></label></td>
              </tr>
            <?php endif; ?>
          </table>
          <?php if (!empty($form['settings']['campaign']['enabled'])) : ?>
            <p class="description"><?php esc_html_e('This donation counts toward the campaign total and appears on the donor list under the display name below.', 'embed-forms'); ?></p>
          <?php endif; ?>
        <?php endif; ?>

        <h2><?php esc_html_e('Answers', 'embed-forms'); ?></h2>
        <?php if ($hasPayment && !$adding && !$offline) : ?>
          <p class="description"><?php esc_html_e('The amount and frequency were charged as shown and cannot be changed here. To give money back, refund the payment on the entry screen.', 'embed-forms'); ?></p>
        <?php endif; ?>
        <table class="form-table" role="presentation">
          <?php foreach ($schema['fields'] as $field) :
            if ($field['type'] === 'section') : ?>
              <tr><th colspan="2"><h3 style="margin:8px 0 0"><?php echo esc_html($field['label']); ?></h3></th></tr>
            <?php endif;
            if (!Fields::isInput($field['type'])) {
              continue;
            }
            $id = $field['id'];
            $label = $field['label'] !== '' ? $field['label'] : $id;
            ?>
            <tr class="<?php echo isset($this->errors[$id]) ? 'ef-has-error' : ''; ?>">
              <th scope="row"><label for="ef-v-<?php echo esc_attr($id); ?>"><?php echo esc_html($field['type'] === 'anonymous' ? __('Anonymous', 'embed-forms') : $label); ?></label></th>
              <td>
                <?php
                if (in_array($field['type'], $paymentTypes, TRUE) && ($adding || $offline)) {
                  echo '<span class="description">' . esc_html__('From the donation above.', 'embed-forms') . '</span>';
                }
                elseif (in_array($field['type'], $paymentTypes, TRUE)) {
                  $text = Display::text($field, $entry['data'][$id] ?? NULL);
                  echo $text === '' ? '<span class="ef-empty">&mdash;</span>' : esc_html($text);
                }
                else {
                  FieldInputs::render($field, $values[$id] ?? NULL);
                }
                $this->error($id);
                if (!empty($field['conditions'])) {
                  echo '<p class="description">' . esc_html__('Shown on the form only under some answers; if those do not match, this answer is not kept.', 'embed-forms') . '</p>';
                }
                ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
        <p class="submit">
          <button type="submit" class="button button-primary"><?php echo esc_html($adding ? __('Add donation', 'embed-forms') : __('Save changes', 'embed-forms')); ?></button>
          <?php if ($adding) : ?>
            <button type="submit" name="add_another" value="1" class="button"><?php esc_html_e('Add and enter another', 'embed-forms'); ?></button>
          <?php endif; ?>
          <a class="button-link" style="margin-left:10px" href="<?php echo esc_url($back); ?>"><?php esc_html_e('Cancel', 'embed-forms'); ?></a>
        </p>
      </form>
    </div>
    <?php
  }

  private function error(string $key): void {
    if (isset($this->errors[$key])) {
      echo '<p class="ef-field-error">' . esc_html($this->errors[$key]) . '</p>';
    }
  }

}
