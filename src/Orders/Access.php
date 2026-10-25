<?php

namespace EmbedForms\Orders;

use EmbedForms\Db\Forms;
use EmbedForms\Plugin;

/**
 * Who may open a form's store manager page: administrators (all forms),
 * and people with the Store manager role for the forms ticked on their
 * profile (Users > Edit). Store managers land on their orders after
 * signing in; in wp-admin they have only their profile and an Orders link.
 */
final class Access {

  public const ROLE = 'ef_store_manager';

  public const CAPABILITY = 'embed_forms_manage_orders';

  public const META = 'embed_forms_manager_forms';

  private const ROLE_OPTION = 'embed_forms_roles';

  private const ROLE_VERSION = '1';

  public function register(): void {
    add_action('init', [self::class, 'ensureRole'], 2);
    add_filter('login_redirect', [$this, 'loginRedirect'], 20, 3);
    if (is_admin()) {
      add_action('show_user_profile', [$this, 'profileFields']);
      add_action('edit_user_profile', [$this, 'profileFields']);
      add_action('personal_options_update', [$this, 'saveProfile']);
      add_action('edit_user_profile_update', [$this, 'saveProfile']);
      add_action('admin_menu', [$this, 'menu']);
    }
  }

  public static function ensureRole(): void {
    if (get_option(self::ROLE_OPTION) === self::ROLE_VERSION && get_role(self::ROLE)) {
      return;
    }
    remove_role(self::ROLE);
    add_role(self::ROLE, __('Store manager', 'embed-forms'), ['read' => TRUE, self::CAPABILITY => TRUE]);
    update_option(self::ROLE_OPTION, self::ROLE_VERSION, FALSE);
  }

  public static function isAdmin(?int $userId = NULL): bool {
    return user_can($userId ?? get_current_user_id(), Plugin::CAPABILITY);
  }

  /**
   * The forms a person manages, by id.
   *
   * @return int[]
   */
  public static function formIds(?int $userId = NULL): array {
    $userId = $userId ?? get_current_user_id();
    if (!$userId) {
      return [];
    }
    if (self::isAdmin($userId)) {
      return array_map(static fn($f) => (int) $f['id'], Forms::all());
    }
    if (!user_can($userId, self::CAPABILITY)) {
      return [];
    }
    return array_values(array_filter(array_map('intval', (array) get_user_meta($userId, self::META, TRUE))));
  }

  public static function can(int $formId, ?int $userId = NULL): bool {
    $userId = $userId ?? get_current_user_id();
    if (!$userId || $formId <= 0) {
      return FALSE;
    }
    return self::isAdmin($userId) || in_array($formId, self::formIds($userId), TRUE);
  }

  /**
   * Store managers given this form, for its settings.
   *
   * @return \WP_User[]
   */
  public static function managers(int $formId): array {
    return array_values(array_filter(get_users(['role' => self::ROLE, 'number' => 200]), static fn(\WP_User $u) => in_array($formId, self::formIds($u->ID), TRUE)));
  }

  public function loginRedirect(string $to, string $requested, mixed $user): string {
    if (!$user instanceof \WP_User || self::isAdmin($user->ID) || !user_can($user, self::CAPABILITY)) {
      return $to;
    }
    // A link they were sent (to an order page) wins; otherwise their orders.
    if ($requested !== '' && str_contains($requested, Module::QUERY_VAR)) {
      return $to;
    }
    $ids = self::formIds($user->ID);
    return Module::url(count($ids) === 1 ? $ids[0] : 0);
  }

  public function menu(): void {
    if (self::isAdmin() || !current_user_can(self::CAPABILITY)) {
      return;
    }
    add_menu_page(__('Orders', 'embed-forms'), __('Orders', 'embed-forms'), self::CAPABILITY, 'embed-forms-orders', static function (): void {
      echo '<div class="wrap"><h1>' . esc_html__('Orders', 'embed-forms') . '</h1><p><a class="button button-primary" href="' . esc_url(Module::url(0)) . '">' . esc_html__('Open the store manager page', 'embed-forms') . '</a></p></div>';
    }, 'dashicons-cart', 3);
  }

  public function profileFields(\WP_User $user): void {
    if (!self::isAdmin() || self::isAdmin($user->ID)) {
      return;
    }
    $forms = Forms::all();
    $chosen = self::formIds($user->ID);
    ?>
    <h2 id="embed-forms-orders"><?php esc_html_e('Store manager page', 'embed-forms'); ?></h2>
    <table class="form-table" role="presentation">
      <tr>
        <th scope="row"><?php esc_html_e('Forms they manage', 'embed-forms'); ?></th>
        <td>
          <?php if (!user_can($user, self::CAPABILITY)) : ?>
            <p class="description"><?php esc_html_e('Give this person the Store manager role (above) and save, then choose their forms here.', 'embed-forms'); ?></p>
          <?php else : ?>
            <input type="hidden" name="ef_manager_forms_sent" value="1">
            <?php wp_nonce_field('embed_forms_manager_forms', 'ef_manager_nonce'); ?>
            <fieldset>
              <?php foreach ($forms as $form) : ?>
                <label style="display:block;margin-bottom:4px"><input type="checkbox" name="ef_manager_forms[]" value="<?php echo (int) $form['id']; ?>" <?php checked(in_array((int) $form['id'], $chosen, TRUE)); ?>> <?php echo esc_html($form['title']); ?></label>
              <?php endforeach; ?>
            </fieldset>
            <p class="description"><?php esc_html_e('They see these forms\' entries and can change orders, give refunds and add orders. Their link:', 'embed-forms'); ?> <code><?php echo esc_html(Module::url(0)); ?></code></p>
          <?php endif; ?>
        </td>
      </tr>
    </table>
    <?php
  }

  public function saveProfile(int $userId): void {
    if (!self::isAdmin() || empty($_POST['ef_manager_forms_sent']) || !check_admin_referer('embed_forms_manager_forms', 'ef_manager_nonce')) {
      return;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['ef_manager_forms'] ?? [])))));
    update_user_meta($userId, self::META, $ids);
  }

}
