<?php

namespace EmbedForms\Campaign;

use EmbedForms\Db\Forms;
use EmbedForms\Embed;
use EmbedForms\Payments\Money;
use EmbedForms\Plugin;
use EmbedForms\Rest\SubmitController;
use EmbedForms\Schema\Fields;

/**
 * Campaigns: a form's running total and public donor list, for a campaign
 * page to show with assets/campaign.js.
 *
 * - GET /embed-forms/v1/forms/{ref}/campaign answers with the totals and
 *   recent donors (Totals), only while the form's campaign is switched on.
 * - The editor's Settings tab gets a Campaign section.
 * - The public form previews how the person will appear on the list.
 */
final class Module {

  public function register(): void {
    add_action('rest_api_init', [$this, 'routes']);
    add_filter('embed_forms_page_config', [$this, 'pageConfig'], 10, 2);
    foreach (['embed_forms_payment_completed', 'embed_forms_renewal_charged'] as $hook) {
      add_action($hook, static function ($payment, $entry = NULL): void {
        Totals::flushForEntry((int) ($payment['entry_id'] ?? 0));
      }, 10, 2);
    }
    add_action('embed_forms_payment_refunded', static function ($payment): void {
      Totals::flushForEntry((int) ($payment['entry_id'] ?? 0));
    });
    add_action('embed_forms_entry_changed', [Totals::class, 'flush']);
    if (is_admin()) {
      add_action('embed_forms_editor_settings', [$this, 'editorSettings'], 20);
    }
  }

  public function routes(): void {
    register_rest_route(SubmitController::NAMESPACE, '/forms/(?P<ref>[A-Za-z0-9_-]+)/campaign', [
      'methods' => 'GET',
      'callback' => [$this, 'campaign'],
      'permission_callback' => '__return_true',
    ]);
  }

  public function campaign(\WP_REST_Request $request): \WP_REST_Response {
    $form = Forms::findPublic((string) $request['ref']);
    if (!$form || $form['status'] === 'draft' || empty($form['settings']['campaign']['enabled'])) {
      $response = new \WP_REST_Response(['ok' => FALSE, 'message' => __('This campaign is not available.', 'embed-forms')], 404);
      $response->header('Cache-Control', 'no-store');
      return $response;
    }
    $response = new \WP_REST_Response(['ok' => TRUE] + Totals::forForm($form), 200);
    // Shared by every visitor; a short public cache keeps busy pages light.
    $response->header('Cache-Control', 'public, max-age=20');
    $response->header('Access-Control-Allow-Origin', '*');
    return $response;
  }

  public function pageConfig(array $config, array $form): array {
    $types = array_column($form['schema']['fields'], 'type');
    if (!empty($form['settings']['campaign']['enabled']) && array_intersect($types, Fields::DONOR_TYPES)) {
      $config['donorList'] = [
        'fallback' => $form['settings']['campaign']['name_fallback'],
        'anonymousLabel' => Donors::anonymousLabel(),
        /* translators: %s: the name as it will be shown */
        'preview' => __('Shown on the donor list as %s', 'embed-forms'),
      ];
    }
    return $config;
  }

  public static function scriptUrl(): string {
    return Plugin::instance()->url('assets/campaign.js');
  }

  public function editorSettings(array $form): void {
    $c = $form['settings']['campaign'];
    $types = array_column($form['schema']['fields'], 'type');
    ?>
    <h2><?php esc_html_e('Campaign', 'embed-forms'); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Donor list', 'embed-forms'); ?></th>
        <td>
          <input type="hidden" name="settings[campaign][enabled]" value="0">
          <label><input type="checkbox" name="settings[campaign][enabled]" value="1" <?php checked($c['enabled']); ?>> <?php esc_html_e('Publish this form\'s running total and donor list for a campaign page', 'embed-forms'); ?></label>
          <p class="description"><?php esc_html_e('Only the name to display (or Anonymous), and the amount and city if you choose below, are published. Test payments count only while the form is in test mode; offline donations always count.', 'embed-forms'); ?></p>
          <?php if (!array_intersect($types, Fields::DONOR_TYPES)) : ?>
            <p class="description"><strong><?php esc_html_e('Tip: add the Display name and Give anonymously fields (Donor list group) so donors choose how they appear.', 'embed-forms'); ?></strong></p>
          <?php endif; ?>
        </td>
      </tr>
      <tr>
        <th scope="row"><label for="ef-goal"><?php esc_html_e('Goal', 'embed-forms'); ?></label></th>
        <td>$ <input type="text" inputmode="decimal" id="ef-goal" name="settings[campaign][goal]" value="<?php echo esc_attr($c['goal']); ?>" class="regular-text" style="width:10em"> <span class="description"><?php esc_html_e('Optional', 'embed-forms'); ?></span></td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('Show', 'embed-forms'); ?></th>
        <td>
          <input type="hidden" name="settings[campaign][show_amounts]" value="0">
          <label><input type="checkbox" name="settings[campaign][show_amounts]" value="1" <?php checked($c['show_amounts']); ?>> <?php esc_html_e('Each donor\'s amount', 'embed-forms'); ?></label><br>
          <input type="hidden" name="settings[campaign][show_city]" value="0">
          <label><input type="checkbox" name="settings[campaign][show_city]" value="1" <?php checked($c['show_city']); ?>> <?php esc_html_e('Each donor\'s city (from the address field; never for anonymous donors)', 'embed-forms'); ?></label>
        </td>
      </tr>
      <tr>
        <th scope="row"><?php esc_html_e('No display name', 'embed-forms'); ?></th>
        <td><fieldset>
          <label><input type="radio" name="settings[campaign][name_fallback]" value="initial" <?php checked($c['name_fallback'], 'initial'); ?>> <?php esc_html_e('Show the first name and last initial ("Mendel K.")', 'embed-forms'); ?></label><br>
          <label><input type="radio" name="settings[campaign][name_fallback]" value="anonymous" <?php checked($c['name_fallback'], 'anonymous'); ?>> <?php esc_html_e('Show Anonymous', 'embed-forms'); ?></label>
        </fieldset></td>
      </tr>
      <tr>
        <th scope="row"><label for="ef-offset"><?php esc_html_e('Raised elsewhere', 'embed-forms'); ?></label></th>
        <td>$ <input type="text" inputmode="decimal" id="ef-offset" name="settings[campaign][offset_amount]" value="<?php echo esc_attr($c['offset_amount']); ?>" style="width:10em">
          <?php esc_html_e('from', 'embed-forms'); ?> <input type="number" min="0" name="settings[campaign][offset_donors]" value="<?php echo (int) $c['offset_donors']; ?>" class="small-text" aria-label="<?php esc_attr_e('Donors elsewhere', 'embed-forms'); ?>"> <?php esc_html_e('donors', 'embed-forms'); ?>
          <p class="description"><?php esc_html_e('Added to the total and donor count, for money raised outside this form that you do not enter one by one. To list a donor by name, add an offline donation from the Entries screen instead.', 'embed-forms'); ?></p></td>
      </tr>
      <?php if ($c['enabled']) :
        $totals = Totals::forForm($form);
        ?>
        <tr>
          <th scope="row"><?php esc_html_e('Now', 'embed-forms'); ?></th>
          <td><?php echo esc_html(sprintf(_n('%1$s raised from %2$s donor', '%1$s raised from %2$s donors', $totals['donors'], 'embed-forms'), Money::format($totals['raised']), number_format_i18n($totals['donors']))); ?>
            <?php echo $totals['goal'] ? esc_html(sprintf(__('(%s%% of the goal)', 'embed-forms'), $totals['percent'])) : ''; ?></td>
        </tr>
        <tr>
          <th scope="row"><?php esc_html_e('On the campaign page', 'embed-forms'); ?></th>
          <td><textarea class="large-text code" rows="7" readonly><?php echo esc_textarea($this->snippet($form)); ?></textarea>
            <p class="description"><?php esc_html_e('campaign.js fills the elements marked data-ef-raised, data-ef-goal, data-ef-donors, data-ef-percent and data-ef-progress (its width), refreshes every 30 seconds and right after a donation, and sends the events embedforms:campaign (all data) and embedforms:donation (each new donor) for the page to show donors its own way. Without its own list, a data-ef-donor-list element gets a simple one.', 'embed-forms'); ?></p></td>
        </tr>
      <?php endif; ?>
    </table>
    <?php
  }

  private function snippet(array $form): string {
    return '<div data-ef-campaign="' . $form['uuid'] . '">' . "\n"
      . '  <p><strong data-ef-raised></strong> raised of <span data-ef-goal></span> · <span data-ef-donors></span> donors</p>' . "\n"
      . '  <div style="background:#eee;border-radius:9px"><div data-ef-progress style="height:9px;border-radius:9px;background:currentColor"></div></div>' . "\n"
      . '  <ul data-ef-donor-list></ul>' . "\n"
      . '  <div data-embed-form="' . $form['uuid'] . '"></div>' . "\n"
      . '</div>' . "\n"
      . '<script src="' . esc_url(self::scriptUrl()) . '" defer></script>' . "\n"
      . '<script src="' . esc_url(Embed::loaderUrl()) . '" async></script>';
  }

}
