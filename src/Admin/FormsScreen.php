<?php

namespace EmbedForms\Admin;

use EmbedForms\Db\Forms;
use EmbedForms\Embed;
use EmbedForms\Plugin;
use EmbedForms\Schema\FormSettings;
use EmbedForms\Settings;

/**
 * Embed Forms > All Forms: the list, and the editor for one form
 * (?page=embed-forms&form=ID) with Fields, Settings and Embed tabs. The
 * whole editor is one HTML form posted to admin-post.php; the fields tab
 * keeps the schema as JSON in a hidden input that the builder writes.
 */
final class FormsScreen {

  public function registerActions(): void {
    add_action('admin_post_embed_forms_create', [$this, 'create']);
    add_action('admin_post_embed_forms_save', [$this, 'save']);
    add_action('admin_post_embed_forms_duplicate', [$this, 'duplicate']);
    add_action('admin_post_embed_forms_delete', [$this, 'delete']);
  }

  public function load(): void {
    if (!empty($_GET['form'])) {
      /**
       * The form editor is opening; the builder enqueues its assets here.
       */
      do_action('embed_forms_editor_load', (int) $_GET['form']);
    }
  }

  public function render(): void {
    Menu::requireCapability();
    if (!empty($_GET['form'])) {
      $form = Forms::find((int) $_GET['form']);
      if (!$form) {
        echo '<div class="wrap"><h1>' . esc_html__('Form not found', 'embed-forms') . '</h1></div>';
        return;
      }
      $this->editor($form);
      return;
    }
    $table = new FormsTable();
    $table->prepare_items();
    ?>
    <div class="wrap">
      <h1 class="wp-heading-inline"><?php esc_html_e('Forms', 'embed-forms'); ?></h1>
      <a href="<?php echo esc_url(Menu::actionUrl('embed_forms_create')); ?>" class="page-title-action"><?php esc_html_e('Add New Form', 'embed-forms'); ?></a>
      <hr class="wp-header-end">
      <?php Menu::renderFlash(); ?>
      <?php if (!Plugin::paymentsAvailable()) : ?>
        <div class="notice notice-info"><p><?php esc_html_e('Payment fields need the USAePay Payments plugin. Forms without payments work without it.', 'embed-forms'); ?></p></div>
      <?php endif; ?>
      <?php $table->views(); ?>
      <form method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr(Menu::PAGE_FORMS); ?>">
        <?php $table->search_box(__('Search forms', 'embed-forms'), 'ef-forms'); ?>
      </form>
      <?php $table->display(); ?>
    </div>
    <?php
  }

  private function editor(array $form): void {
    $s = $form['settings'];
    $notification = $s['notifications'][0] ?? FormSettings::defaults()['notifications'][0];
    $tab = isset($_GET['tab']) && in_array($_GET['tab'], ['fields', 'settings', 'embed'], TRUE) ? (string) $_GET['tab'] : 'fields';
    $emailFields = array_filter($form['schema']['fields'], static fn($f) => $f['type'] === 'email');
    ?>
    <div class="wrap ef-editor">
      <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="ef-editor-form">
        <input type="hidden" name="action" value="embed_forms_save">
        <input type="hidden" name="form" value="<?php echo (int) $form['id']; ?>">
        <input type="hidden" name="tab" value="<?php echo esc_attr($tab); ?>" id="ef-current-tab">
        <?php wp_nonce_field('embed_forms_save_' . $form['id']); ?>

        <div class="ef-editor-header">
          <a class="ef-back" href="<?php echo esc_url(Menu::formsUrl()); ?>">&larr; <?php esc_html_e('All forms', 'embed-forms'); ?></a>
          <input type="text" name="title" class="ef-title-input" value="<?php echo esc_attr($form['title']); ?>" placeholder="<?php esc_attr_e('Form title', 'embed-forms'); ?>" aria-label="<?php esc_attr_e('Form title', 'embed-forms'); ?>" required>
          <select name="status" aria-label="<?php esc_attr_e('Status', 'embed-forms'); ?>">
            <?php foreach (['draft' => __('Draft', 'embed-forms'), 'live' => __('Live', 'embed-forms'), 'closed' => __('Closed', 'embed-forms')] as $value => $label) : ?>
              <option value="<?php echo esc_attr($value); ?>" <?php selected($form['status'], $value); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
          </select>
          <a class="button" href="<?php echo esc_url(Embed::url($form)); ?>" target="_blank" rel="noopener"><?php esc_html_e('Preview', 'embed-forms'); ?></a>
          <button type="submit" class="button button-primary"><?php esc_html_e('Save', 'embed-forms'); ?></button>
        </div>
        <?php Menu::renderFlash(); ?>

        <nav class="nav-tab-wrapper ef-tabs">
          <?php foreach (['fields' => __('Fields', 'embed-forms'), 'settings' => __('Settings', 'embed-forms'), 'embed' => __('Embed & share', 'embed-forms')] as $key => $label) : ?>
            <a href="#<?php echo esc_attr($key); ?>" data-tab="<?php echo esc_attr($key); ?>" class="nav-tab<?php echo $tab === $key ? ' nav-tab-active' : ''; ?>"><?php echo esc_html($label); ?></a>
          <?php endforeach; ?>
        </nav>

        <section class="ef-tab-panel" data-panel="fields" <?php echo $tab === 'fields' ? '' : 'hidden'; ?>>
          <?php if (has_action('embed_forms_render_builder')) : ?>
            <?php
            /**
             * The drag-and-drop builder prints itself here and keeps
             * #ef-schema up to date.
             */
            do_action('embed_forms_render_builder', $form);
            ?>
            <textarea name="schema" id="ef-schema" hidden><?php echo esc_textarea(wp_json_encode($form['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
          <?php else : ?>
            <p class="description"><?php esc_html_e('The form definition as JSON: a "fields" list, each field with an id, type and label. Types: text, textarea, email, phone, number, select, radio, checkbox, date, name, address, hidden, html, section, page.', 'embed-forms'); ?></p>
            <textarea name="schema" id="ef-schema" class="large-text code" rows="30"><?php echo esc_textarea(wp_json_encode($form['schema'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)); ?></textarea>
          <?php endif; ?>
        </section>

        <section class="ef-tab-panel" data-panel="settings" <?php echo $tab === 'settings' ? '' : 'hidden'; ?>>
          <h2><?php esc_html_e('Form', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><label for="ef-slug"><?php esc_html_e('Link name', 'embed-forms'); ?></label></th>
              <td><code><?php echo esc_html(home_url('/' . Embed::base() . '/')); ?></code><input type="text" id="ef-slug" name="slug" value="<?php echo esc_attr($form['slug']); ?>" class="regular-text"></td>
            </tr>
            <tr>
              <th scope="row"><?php esc_html_e('Title', 'embed-forms'); ?></th>
              <td><label><input type="checkbox" name="settings[show_title]" value="1" <?php checked($s['show_title']); ?>> <?php esc_html_e('Show the title above the form', 'embed-forms'); ?></label></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-description"><?php esc_html_e('Description', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-description" name="settings[description]" rows="3" class="large-text"><?php echo esc_textarea($s['description']); ?></textarea>
                <p class="description"><?php esc_html_e('Shown under the title. Basic HTML allowed.', 'embed-forms'); ?></p></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-submit-label"><?php esc_html_e('Submit button', 'embed-forms'); ?></label></th>
              <td><input type="text" id="ef-submit-label" name="settings[submit_label]" value="<?php echo esc_attr($s['submit_label']); ?>" class="regular-text"></td>
            </tr>
          </table>

          <h2><?php esc_html_e('After submission', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><?php esc_html_e('Confirmation', 'embed-forms'); ?></th>
              <td>
                <label><input type="radio" name="settings[confirmation][type]" value="message" <?php checked($s['confirmation']['type'], 'message'); ?>> <?php esc_html_e('Show a message', 'embed-forms'); ?></label><br>
                <label><input type="radio" name="settings[confirmation][type]" value="redirect" <?php checked($s['confirmation']['type'], 'redirect'); ?>> <?php esc_html_e('Go to a web page', 'embed-forms'); ?></label>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-confirmation-message"><?php esc_html_e('Message', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-confirmation-message" name="settings[confirmation][message]" rows="4" class="large-text"><?php echo esc_textarea($s['confirmation']['message']); ?></textarea>
                <p class="description"><?php esc_html_e('HTML allowed. Merge tags: {field:ID}, {all_fields}, {form_title}, {entry_id}.', 'embed-forms'); ?></p></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-confirmation-url"><?php esc_html_e('Page address', 'embed-forms'); ?></label></th>
              <td><input type="url" id="ef-confirmation-url" name="settings[confirmation][url]" value="<?php echo esc_attr($s['confirmation']['url']); ?>" class="large-text" placeholder="https://">
                <p class="description"><?php esc_html_e('Used when "Go to a web page" is chosen. Merge tags are URL-encoded. In an embedded form the whole page goes there, not only the frame.', 'embed-forms'); ?></p></td>
            </tr>
          </table>

          <h2><?php esc_html_e('Notification email', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><?php esc_html_e('Send', 'embed-forms'); ?></th>
              <td><label><input type="checkbox" name="settings[notifications][0][enabled]" value="1" <?php checked($notification['enabled']); ?>> <?php esc_html_e('Email every new entry', 'embed-forms'); ?></label></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-n-to"><?php esc_html_e('To', 'embed-forms'); ?></label></th>
              <td><input type="text" id="ef-n-to" name="settings[notifications][0][to]" value="<?php echo esc_attr($notification['to']); ?>" class="large-text">
                <p class="description"><?php esc_html_e('Addresses separated by commas. {admin_email} is the site admin address.', 'embed-forms'); ?></p></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-n-subject"><?php esc_html_e('Subject', 'embed-forms'); ?></label></th>
              <td><input type="text" id="ef-n-subject" name="settings[notifications][0][subject]" value="<?php echo esc_attr($notification['subject']); ?>" class="large-text"></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-n-body"><?php esc_html_e('Message', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-n-body" name="settings[notifications][0][body]" rows="5" class="large-text"><?php echo esc_textarea($notification['body']); ?></textarea></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-n-reply"><?php esc_html_e('Reply-To', 'embed-forms'); ?></label></th>
              <td><input type="text" id="ef-n-reply" name="settings[notifications][0][reply_to]" value="<?php echo esc_attr($notification['reply_to']); ?>" class="large-text" placeholder="{field:email}"></td>
            </tr>
          </table>
          <?php foreach (array_slice($s['notifications'], 1) as $i => $extra) : ?>
            <?php foreach ($extra as $key => $value) : ?>
              <input type="hidden" name="settings[notifications][<?php echo (int) $i + 1; ?>][<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr(is_bool($value) ? ($value ? '1' : '') : (string) $value); ?>">
            <?php endforeach; ?>
          <?php endforeach; ?>

          <h2><?php esc_html_e('Confirmation email to the person who submitted', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><?php esc_html_e('Send', 'embed-forms'); ?></th>
              <td><label><input type="checkbox" name="settings[autoresponder][enabled]" value="1" <?php checked($s['autoresponder']['enabled']); ?>> <?php esc_html_e('Email a confirmation', 'embed-forms'); ?></label></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-a-field"><?php esc_html_e('Email field', 'embed-forms'); ?></label></th>
              <td>
                <select id="ef-a-field" name="settings[autoresponder][to_field]">
                  <option value=""><?php esc_html_e('Choose…', 'embed-forms'); ?></option>
                  <?php foreach ($emailFields as $field) : ?>
                    <option value="<?php echo esc_attr($field['id']); ?>" <?php selected($s['autoresponder']['to_field'], $field['id']); ?>><?php echo esc_html($field['label'] ?: $field['id']); ?></option>
                  <?php endforeach; ?>
                </select>
                <?php if (!$emailFields) : ?><p class="description"><?php esc_html_e('Add an email field to the form first.', 'embed-forms'); ?></p><?php endif; ?>
              </td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-a-subject"><?php esc_html_e('Subject', 'embed-forms'); ?></label></th>
              <td><input type="text" id="ef-a-subject" name="settings[autoresponder][subject]" value="<?php echo esc_attr($s['autoresponder']['subject']); ?>" class="large-text"></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-a-body"><?php esc_html_e('Message', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-a-body" name="settings[autoresponder][body]" rows="5" class="large-text"><?php echo esc_textarea($s['autoresponder']['body']); ?></textarea></td>
            </tr>
          </table>

          <h2><?php esc_html_e('Access and spam', 'embed-forms'); ?></h2>
          <table class="form-table" role="presentation">
            <tr>
              <th scope="row"><label for="ef-domains"><?php esc_html_e('Allowed websites', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-domains" name="settings[embed_domains]" rows="3" class="large-text code" placeholder="example.org&#10;*.example.org"><?php echo esc_textarea(implode("\n", $s['embed_domains'])); ?></textarea>
                <p class="description"><?php esc_html_e('Domains that may embed this form, one per line; *.example.org also covers its subdomains. Browsers refuse to show the form on any other website. Leave empty to allow any website.', 'embed-forms'); ?></p></td>
            </tr>
            <tr>
              <th scope="row"><?php esc_html_e('Direct link', 'embed-forms'); ?></th>
              <td><label><input type="checkbox" name="settings[allow_direct]" value="1" <?php checked($s['allow_direct']); ?>> <?php esc_html_e('Allow opening the form on its own page (share link)', 'embed-forms'); ?></label>
                <p class="description"><?php esc_html_e('Only applies when allowed websites are set. Turn off to make the form available only inside those websites. Administrators can always preview it.', 'embed-forms'); ?></p></td>
            </tr>
            <tr>
              <th scope="row"><?php esc_html_e('Turnstile', 'embed-forms'); ?></th>
              <td><label><input type="checkbox" name="settings[turnstile]" value="1" <?php checked($s['turnstile']); ?>> <?php esc_html_e('Require the Cloudflare Turnstile check', 'embed-forms'); ?></label>
                <?php if (!Settings::turnstileConfigured()) : ?>
                  <p class="description"><?php printf(esc_html__('Turnstile keys are not set yet (%s), so no check is shown.', 'embed-forms'), '<a href="' . esc_url(admin_url('admin.php?page=' . Menu::PAGE_SETTINGS)) . '">' . esc_html__('Settings', 'embed-forms') . '</a>'); ?></p>
                <?php endif; ?></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-max"><?php esc_html_e('Entry limit', 'embed-forms'); ?></label></th>
              <td><input type="number" min="0" id="ef-max" name="settings[max_entries]" value="<?php echo (int) $s['max_entries']; ?>" class="small-text"> <span class="description"><?php esc_html_e('0 for no limit', 'embed-forms'); ?></span></td>
            </tr>
            <tr>
              <th scope="row"><label for="ef-closed"><?php esc_html_e('Closed message', 'embed-forms'); ?></label></th>
              <td><textarea id="ef-closed" name="settings[closed_message]" rows="2" class="large-text"><?php echo esc_textarea($s['closed_message']); ?></textarea></td>
            </tr>
          </table>
          <?php
          /**
           * More settings sections (payments) print here, inside the form.
           */
          do_action('embed_forms_editor_settings', $form);
          ?>
        </section>

        <section class="ef-tab-panel" data-panel="embed" <?php echo $tab === 'embed' ? '' : 'hidden'; ?>>
          <?php if ($form['status'] !== 'live') : ?>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This form is not live yet: visitors will not see it until its status is Live.', 'embed-forms'); ?></p></div>
          <?php endif; ?>
          <?php $this->codeBlock(__('Embed on any website', 'embed-forms'), Embed::scriptSnippet($form), __('Paste where the form should appear. It resizes to fit and passes the page\'s link parameters on for prefilling.', 'embed-forms')); ?>
          <?php $this->codeBlock(__('Share link', 'embed-forms'), Embed::url($form), __('A page with just the form.', 'embed-forms')); ?>
          <?php $this->codeBlock(__('On this WordPress site', 'embed-forms'), '[embed_form id="' . $form['uuid'] . '"]', __('Shortcode for pages and posts here.', 'embed-forms')); ?>
          <?php $this->codeBlock(__('Plain iframe', 'embed-forms'), Embed::iframeSnippet($form), __('For site builders that do not allow scripts. It cannot resize itself.', 'embed-forms')); ?>
          <h3><?php esc_html_e('Preview', 'embed-forms'); ?></h3>
          <div class="ef-preview-frame" data-embed-form="<?php echo esc_attr($form['uuid']); ?>" data-base="<?php echo esc_attr(home_url('/' . Embed::base() . '/')); ?>"></div>
          <script src="<?php echo esc_url(add_query_arg('ver', Plugin::instance()->assetVersion('assets/embed.js'), Embed::loaderUrl())); ?>" async></script>
        </section>
      </form>
    </div>
    <?php
  }

  private function codeBlock(string $title, string $code, string $help): void {
    ?>
    <div class="ef-code-block">
      <h3><?php echo esc_html($title); ?></h3>
      <p class="description"><?php echo esc_html($help); ?></p>
      <div class="ef-code-row">
        <textarea readonly rows="<?php echo substr_count($code, "\n") + 1; ?>" class="large-text code" onclick="this.select()"><?php echo esc_textarea($code); ?></textarea>
        <button type="button" class="button ef-copy"><?php esc_html_e('Copy', 'embed-forms'); ?></button>
      </div>
    </div>
    <?php
  }

  public function create(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_create');
    $id = Forms::create(__('Untitled form', 'embed-forms'), [
      'fields' => [
        ['id' => 'name', 'type' => 'name', 'label' => __('Name', 'embed-forms'), 'required' => TRUE],
        ['id' => 'email', 'type' => 'email', 'label' => __('Email', 'embed-forms'), 'required' => TRUE],
        ['id' => 'message', 'type' => 'textarea', 'label' => __('Message', 'embed-forms')],
      ],
    ]);
    wp_safe_redirect(Menu::formsUrl(['form' => $id]));
    exit;
  }

  public function save(): void {
    Menu::requireCapability();
    $id = isset($_POST['form']) ? (int) $_POST['form'] : 0;
    check_admin_referer('embed_forms_save_' . $id);
    $tab = isset($_POST['tab']) ? sanitize_key((string) $_POST['tab']) : 'fields';
    $post = wp_unslash($_POST);
    $settings = is_array($post['settings'] ?? NULL) ? $post['settings'] : [];
    // Unchecked boxes are absent from the post.
    foreach (['show_title', 'turnstile', 'allow_direct'] as $flag) {
      $settings[$flag] = !empty($settings[$flag]);
    }
    $settings['autoresponder']['enabled'] = !empty($settings['autoresponder']['enabled']);
    if (isset($settings['notifications'][0])) {
      $settings['notifications'][0]['enabled'] = !empty($settings['notifications'][0]['enabled']);
    }
    if (!current_user_can('unfiltered_html')) {
      $settings['description'] = wp_kses_post((string) ($settings['description'] ?? ''));
      $settings['confirmation']['message'] = wp_kses_post((string) ($settings['confirmation']['message'] ?? ''));
    }
    $form = Forms::find($id);
    if ($form) {
      // Sections added by other code (payments) keep their stored values
      // unless they posted new ones.
      $settings = array_replace($form['settings'], $settings);
    }
    /**
     * Settings about to be saved from the editor.
     */
    $settings = apply_filters('embed_forms_save_settings', $settings, $post, $form);
    try {
      $schema = (string) ($post['schema'] ?? '');
      if (!current_user_can('unfiltered_html')) {
        $decoded = json_decode($schema, TRUE);
        if (is_array($decoded) && isset($decoded['fields']) && is_array($decoded['fields'])) {
          foreach ($decoded['fields'] as &$field) {
            if (is_array($field) && isset($field['content'])) {
              $field['content'] = wp_kses_post((string) $field['content']);
            }
          }
          unset($field);
          $schema = $decoded;
        }
      }
      Forms::update($id, [
        'title' => (string) ($post['title'] ?? ''),
        'status' => (string) ($post['status'] ?? 'draft'),
        'slug' => (string) ($post['slug'] ?? ($form['slug'] ?? '')),
        'settings' => $settings,
        'schema' => $schema,
      ]);
      Menu::flash(__('Form saved.', 'embed-forms'));
    }
    catch (\InvalidArgumentException $e) {
      Menu::flash(sprintf(__('Not saved: %s', 'embed-forms'), $e->getMessage()), 'error');
    }
    wp_safe_redirect(Menu::formsUrl(['form' => $id, 'tab' => $tab]));
    exit;
  }

  public function duplicate(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_duplicate');
    $id = Forms::duplicate((int) ($_GET['form'] ?? 0));
    Menu::flash(__('Form duplicated. The copy is a draft.', 'embed-forms'));
    wp_safe_redirect(Menu::formsUrl(['form' => $id]));
    exit;
  }

  public function delete(): void {
    Menu::requireCapability();
    check_admin_referer('embed_forms_delete');
    $id = (int) ($_GET['form'] ?? 0);
    if (\EmbedForms\Db\Entries::query(['form_id' => $id, 'limit' => 1, 'status' => ''])['total'] > 0) {
      Menu::flash(__('This form has entries, so it was not deleted. Set it to Closed instead, or delete its entries first.', 'embed-forms'), 'error');
    }
    else {
      Forms::delete($id);
      Menu::flash(__('Form deleted.', 'embed-forms'));
    }
    wp_safe_redirect(Menu::formsUrl());
    exit;
  }

}
