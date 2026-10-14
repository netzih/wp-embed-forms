<?php

namespace EmbedForms\Render;

use EmbedForms\Plugin;

/**
 * A page template any theme gets, "Embed Forms: Full page": the page's
 * content alone, without the theme's header, footer or stylesheets, for
 * campaign pages that bring their own design (a Custom HTML block with an
 * embedded form and the campaign script). WordPress's own head and footer
 * hooks still run, so the admin bar, SEO and analytics plugins work.
 *
 * - A page made only of Custom HTML blocks (no shortcodes, no other
 *   blocks) needs nothing else from the site, so its head is cleaned too:
 *   other plugins' scripts and styles, the Customizer's Additional CSS and
 *   the emoji script are left out.
 * - "Own address": such a page can also be served at the root of another
 *   domain pointed at this site (a Plesk domain alias, say), e.g.
 *   give.example.org shows the page and every other path on that domain
 *   goes to the same path on this site.
 */
final class PageTemplate {

  public const TEMPLATE = 'embed-forms-full-page';

  private const DOMAIN_META = '_embed_forms_domain';

  /**
   * host => page id, kept in step with the pages' DOMAIN_META.
   */
  private const DOMAINS_OPTION = 'embed_forms_page_domains';

  /**
   * Handles kept on a clean page: the admin bar, and this plugin's own.
   */
  private const KEEP = ['admin-bar'];

  public function register(): void {
    add_filter('theme_page_templates', [$this, 'templates']);
    add_filter('template_include', [$this, 'include'], 99);
    add_action('wp_enqueue_scripts', [$this, 'dropThemeStyles'], 999);
    add_action('template_redirect', [$this, 'cleanHead'], 1);
    foreach (['wp_print_styles', 'wp_print_scripts', 'wp_print_footer_scripts'] as $hook) {
      add_action($hook, [$this, 'dropOtherAssets'], 0);
    }
    add_filter('request', [$this, 'ownAddress'], 1);
    add_action('add_meta_boxes_page', [$this, 'metaBox']);
    add_action('save_post_page', [$this, 'saveDomain'], 10, 2);
    add_action('deleted_post', [self::class, 'rebuildDomains']);
    add_action('trashed_post', [self::class, 'rebuildDomains']);
  }

  public function templates(array $templates): array {
    $templates[self::TEMPLATE] = __('Embed Forms: Full page (no header or footer)', 'embed-forms');
    return $templates;
  }

  public static function active(): bool {
    return is_singular() && get_page_template_slug() === self::TEMPLATE;
  }

  public function include(string $template): string {
    return self::active() ? Plugin::instance()->path('templates/full-page.php') : $template;
  }

  /**
   * The theme's stylesheets would restyle the page's own design; WordPress
   * core and plugin styles stay (unless the page is clean, below).
   */
  public function dropThemeStyles(): void {
    if (!self::active()) {
      return;
    }
    $roots = array_unique([get_template_directory_uri(), get_stylesheet_directory_uri()]);
    foreach (wp_styles()->queue as $handle) {
      $src = (string) (wp_styles()->registered[$handle]->src ?? '');
      foreach ($roots as $root) {
        if ($src !== '' && str_starts_with($src, $root)) {
          wp_dequeue_style($handle);
        }
      }
    }
    // Theme settings printed inline (colours, fonts) go with them.
    foreach (['global-styles', 'kadence-global'] as $handle) {
      wp_dequeue_style($handle);
    }
  }

  // ---------------------------------------------------------- clean head

  /**
   * Whether the shown page is on this template and made only of Custom
   * HTML blocks, so nothing on it uses other plugins' scripts or styles.
   */
  public static function isClean(): bool {
    if (!self::active()) {
      return FALSE;
    }
    $content = (string) get_post_field('post_content', get_queried_object_id());
    if ($content === '' || preg_match('/' . get_shortcode_regex() . '/', $content)) {
      return FALSE;
    }
    foreach (parse_blocks($content) as $block) {
      if ($block['blockName'] === NULL ? trim($block['innerHTML']) !== '' : $block['blockName'] !== 'core/html') {
        return FALSE;
      }
    }
    /**
     * Whether a full-page template page drops other plugins' assets.
     */
    return (bool) apply_filters('embed_forms_full_page_clean', TRUE, get_queried_object_id());
  }

  public function cleanHead(): void {
    if (!self::isClean()) {
      return;
    }
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('wp_enqueue_scripts', 'wp_enqueue_emoji_styles');
    // The Customizer's Additional CSS styles the theme's pages.
    remove_action('wp_head', 'wp_custom_css_cb', 101);
  }

  /**
   * Leaves out every queued script and style but the admin bar's and this
   * plugin's, just before they print (plugins enqueue at many points).
   */
  public function dropOtherAssets(): void {
    static $clean = NULL;
    $clean ??= self::isClean();
    if (!$clean) {
      return;
    }
    foreach ([wp_scripts(), wp_styles()] as $assets) {
      foreach ($assets->queue as $handle) {
        if (!in_array($handle, self::KEEP, TRUE) && !str_starts_with($handle, 'embed-forms')) {
          $assets->dequeue($handle);
        }
      }
    }
  }

  // --------------------------------------------------------- own address

  /**
   * On a domain set as a page's own address, the root shows that page and
   * any other address goes to the same path on this site.
   */
  public function ownAddress(array $query): array {
    $host = self::requestHost();
    $map = get_option(self::DOMAINS_OPTION, []);
    if ($host === '' || !is_array($map) || empty($map[$host])) {
      return $query;
    }
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    if (trim($path, '/') !== '') {
      // Forms, the REST API and logins keep working on the alias.
      if (preg_match('#^/(wp-json|wp-admin|wp-login\.php|' . preg_quote(\EmbedForms\Embed::base(), '#') . ')(/|$)#', $path)) {
        return $query;
      }
      $queryString = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_QUERY);
      wp_redirect(home_url($path) . ($queryString !== '' ? '?' . $queryString : ''), 301);
      exit;
    }
    $pageId = (int) $map[$host];
    // This host is not the site's own, which WordPress would redirect away.
    remove_action('template_redirect', 'redirect_canonical');
    add_filter('get_canonical_url', static fn($url, $post) => $post && (int) $post->ID === $pageId ? 'https://' . $host . '/' : $url, 10, 2);
    return ['page_id' => $pageId];
  }

  private static function requestHost(): string {
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    return (string) preg_replace('/:\d+$/', '', $host);
  }

  public function metaBox(\WP_Post $post): void {
    if (!current_user_can('manage_options')) {
      return;
    }
    add_meta_box('embed-forms-domain', __('Own address', 'embed-forms'), [$this, 'renderMetaBox'], 'page', 'side');
  }

  public function renderMetaBox(\WP_Post $post): void {
    wp_nonce_field('embed_forms_domain_' . $post->ID, 'embed_forms_domain_nonce');
    $domain = (string) get_post_meta($post->ID, self::DOMAIN_META, TRUE);
    ?>
    <p><label for="ef-page-domain"><?php esc_html_e('Also show this page at', 'embed-forms'); ?></label>
      <input type="text" id="ef-page-domain" name="embed_forms_domain" value="<?php echo esc_attr($domain); ?>" placeholder="give.example.org" class="widefat"></p>
    <p class="description"><?php esc_html_e('For pages on the "Embed Forms: Full page" template. Point the domain at this site first (a domain alias in Plesk, with SSL). Its home page then shows this page; other addresses on it go to this site.', 'embed-forms'); ?></p>
    <?php
  }

  public function saveDomain(int $postId, \WP_Post $post): void {
    if (!isset($_POST['embed_forms_domain_nonce']) || !wp_verify_nonce((string) $_POST['embed_forms_domain_nonce'], 'embed_forms_domain_' . $postId)) {
      return;
    }
    if (!current_user_can('manage_options') || wp_is_post_revision($postId) || (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)) {
      return;
    }
    $domain = self::cleanDomain((string) wp_unslash($_POST['embed_forms_domain'] ?? ''));
    $siteHost = strtolower((string) parse_url(home_url(), PHP_URL_HOST));
    if ($domain === '' || $domain === $siteHost) {
      delete_post_meta($postId, self::DOMAIN_META);
    }
    else {
      update_post_meta($postId, self::DOMAIN_META, $domain);
    }
    self::rebuildDomains();
  }

  public static function cleanDomain(string $value): string {
    $value = strtolower(trim($value));
    $value = (string) preg_replace('#^https?://#', '', $value);
    $value = rtrim((string) preg_replace('#/.*$#', '', $value), '.');
    return preg_match('/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/', $value) ? $value : '';
  }

  /**
   * host => page id for published pages on this template with an own
   * address; a domain claimed twice goes to the newest page.
   */
  public static function rebuildDomains(): void {
    $map = [];
    $pages = get_posts([
      'post_type' => 'page',
      'post_status' => 'publish',
      'meta_key' => self::DOMAIN_META,
      'orderby' => 'ID',
      'order' => 'ASC',
      'numberposts' => 100,
      'fields' => 'ids',
    ]);
    foreach ($pages as $id) {
      $domain = (string) get_post_meta($id, self::DOMAIN_META, TRUE);
      if ($domain !== '' && get_page_template_slug($id) === self::TEMPLATE) {
        $map[$domain] = (int) $id;
      }
    }
    update_option(self::DOMAINS_OPTION, $map, TRUE);
  }

}
