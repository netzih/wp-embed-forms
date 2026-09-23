<?php

namespace EmbedForms\Render;

use EmbedForms\Db\Forms;
use EmbedForms\Embed;
use EmbedForms\Plugin;
use EmbedForms\Rest\SubmitController;
use EmbedForms\Security\Frame;
use EmbedForms\Security\Token;
use EmbedForms\Security\Turnstile;
use EmbedForms\Settings;

/**
 * The standalone page at /f/{slug} (or /f/{uuid}) that shows one form,
 * both when opened directly and inside the embed iframe. It prints its own
 * minimal HTML instead of the theme, so external sites get a small, fast
 * page that no theme or plugin CSS reaches.
 */
final class PublicPage {

  public const QUERY_VAR = 'embed_form';

  public function register(): void {
    add_action('init', [$this, 'rewrite']);
    add_filter('query_vars', static fn(array $vars) => array_merge($vars, [self::QUERY_VAR]));
    add_action('template_redirect', [$this, 'maybeRender'], 0);
  }

  public function rewrite(): void {
    add_rewrite_rule('^' . preg_quote(Embed::base(), '/') . '/([A-Za-z0-9_-]+)/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top');
  }

  public function maybeRender(): void {
    $ref = (string) get_query_var(self::QUERY_VAR);
    if ($ref === '') {
      return;
    }
    $form = Forms::findPublic($ref);
    $embedded = !empty($_GET['ef_embed']);
    $canPreview = current_user_can('manage_options');

    nocache_headers();
    header_remove('X-Frame-Options');
    header('Content-Type: text/html; charset=' . get_option('blog_charset'));
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    if (!$form || ($form['status'] === 'draft' && !$canPreview)) {
      status_header(404);
      header('Content-Security-Policy: frame-ancestors *');
      $this->page(__('Form not found', 'embed-forms'), '<div class="ef-notice">' . esc_html__('This form is not available.', 'embed-forms') . '</div>', $embedded);
      exit;
    }

    status_header(200);
    header('Content-Security-Policy: ' . Frame::policy($form['settings']['embed_domains']));
    if ($embedded || $form['status'] !== 'live') {
      header('X-Robots-Tag: noindex, nofollow');
    }

    $blocked = self::blockedReason($form, $embedded, $canPreview);
    if ($blocked !== NULL) {
      status_header(403);
      $this->page($form['title'], '<div class="ef-notice">' . esc_html($blocked) . '</div>', $embedded);
      exit;
    }

    if ($form['status'] === 'closed' || \EmbedForms\Submit\SubmissionHandler::isFull($form)) {
      $this->page($form['title'], '<div class="ef-notice">' . wp_kses_post($form['settings']['closed_message']) . '</div>', $embedded);
      exit;
    }

    $this->page($form['title'], $this->formMarkup($form, $embedded), $embedded, $this->config($form, $embedded), Turnstile::enabledFor($form['settings']));
    exit;
  }

  /**
   * Why this request may not see the form, or NULL. With allowed websites
   * set, browsers already refuse to frame the page anywhere else (the
   * frame-ancestors header); this adds the checks the server can make:
   *
   * - an embed that names a parent page outside the list is refused;
   * - when the form's direct link is switched off, opening the page as a
   *   top-level page (Sec-Fetch-Dest: document) is refused.
   *
   * Administrators can always open it, so previews keep working.
   */
  public static function blockedReason(array $form, bool $embedded, bool $canPreview): ?string {
    $domains = $form['settings']['embed_domains'];
    if ($canPreview || !$domains) {
      return NULL;
    }
    $parent = isset($_GET['ef_parent']) ? (string) wp_unslash($_GET['ef_parent']) : '';
    if ($embedded && $parent !== '' && !Frame::allows($domains, $parent)) {
      return __('This form cannot be shown on this website.', 'embed-forms');
    }
    if (empty($form['settings']['allow_direct'])) {
      $dest = isset($_SERVER['HTTP_SEC_FETCH_DEST']) ? strtolower((string) $_SERVER['HTTP_SEC_FETCH_DEST']) : '';
      if ($dest === 'document' || ($dest === '' && !$embedded)) {
        return __('This form is only available on the website it was published on.', 'embed-forms');
      }
    }
    return NULL;
  }

  private function formMarkup(array $form, bool $embedded): string {
    $html = '';
    if ($form['status'] === 'draft') {
      $html .= '<div class="ef-preview-banner">' . esc_html__('Draft preview: only administrators can see this form until it is set live.', 'embed-forms') . '</div>';
    }
    if (!empty($form['settings']['show_title'])) {
      $html .= '<h1 class="ef-title">' . esc_html($form['title']) . '</h1>';
    }
    if (trim($form['settings']['description']) !== '') {
      $html .= '<div class="ef-description">' . wp_kses_post(wpautop($form['settings']['description'])) . '</div>';
    }
    $html .= '<div id="ef-root" class="ef-root"><noscript><div class="ef-notice">' . esc_html__('This form needs JavaScript. Please enable it and reload the page.', 'embed-forms') . '</div></noscript></div>';
    return $html;
  }

  private function config(array $form, bool $embedded): array {
    $parent = isset($_GET['ef_parent']) ? esc_url_raw(wp_unslash((string) $_GET['ef_parent'])) : '';
    $parentOrigin = '';
    if ($parent !== '' && Frame::allows($form['settings']['embed_domains'], $parent)) {
      $scheme = parse_url($parent, PHP_URL_SCHEME);
      $host = parse_url($parent, PHP_URL_HOST);
      $port = parse_url($parent, PHP_URL_PORT);
      $parentOrigin = $scheme . '://' . $host . ($port ? ':' . $port : '');
    }
    $config = [
      'form' => [
        'ref' => $form['uuid'],
        'title' => $form['title'],
        'schema' => $form['schema'],
        'submitLabel' => $form['settings']['submit_label'],
      ],
      'token' => Token::issue((int) $form['id'], Token::secret()),
      'submitUrl' => rest_url(SubmitController::NAMESPACE . '/forms/' . $form['uuid'] . '/submit'),
      'restNonce' => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
      'embedded' => $embedded,
      'frameId' => isset($_GET['ef_frame']) ? preg_replace('/[^A-Za-z0-9_-]/', '', (string) $_GET['ef_frame']) : '',
      'parentOrigin' => $parentOrigin,
      'sourceUrl' => $parent !== '' ? $parent : '',
      'turnstileSiteKey' => Turnstile::enabledFor($form['settings']) ? (string) Settings::get('turnstile_site_key') : '',
      'i18n' => [
        'required' => __('This field is required.', 'embed-forms'),
        'requiredParts' => __('Please fill in every part of this field.', 'embed-forms'),
        'email' => __('Please enter a valid email address.', 'embed-forms'),
        'phone' => __('Please enter a valid phone number.', 'embed-forms'),
        'number' => __('Please enter a number.', 'embed-forms'),
        'min' => __('Please enter %s or more.', 'embed-forms'),
        'max' => __('Please enter %s or less.', 'embed-forms'),
        'date' => __('Please enter a valid date.', 'embed-forms'),
        'next' => __('Next', 'embed-forms'),
        'previous' => __('Back', 'embed-forms'),
        'sending' => __('Sending…', 'embed-forms'),
        'fixErrors' => __('Please correct the highlighted fields.', 'embed-forms'),
        'network' => __('Your submission could not be sent. Please check your connection and try again.', 'embed-forms'),
        'choose' => __('Choose…', 'embed-forms'),
        'stepOf' => __('Step %1$d of %2$d', 'embed-forms'),
        'parts' => [
          'prefix' => __('Prefix', 'embed-forms'),
          'first' => __('First', 'embed-forms'),
          'middle' => __('Middle', 'embed-forms'),
          'last' => __('Last', 'embed-forms'),
          'suffix' => __('Suffix', 'embed-forms'),
          'line1' => __('Street address', 'embed-forms'),
          'line2' => __('Apartment, suite, etc.', 'embed-forms'),
          'city' => __('City', 'embed-forms'),
          'state' => __('State / Province', 'embed-forms'),
          'postcode' => __('ZIP / Postal code', 'embed-forms'),
          'country' => __('Country', 'embed-forms'),
        ],
      ],
    ];
    /**
     * The browser configuration of a public form page; payment support adds
     * its keys here.
     */
    return apply_filters('embed_forms_page_config', $config, $form);
  }

  private function page(string $title, string $body, bool $embedded, ?array $config = NULL, bool $turnstile = FALSE): void {
    $plugin = Plugin::instance();
    $scripts = [];
    if ($config !== NULL) {
      if ($turnstile) {
        $scripts[] = Turnstile::SCRIPT;
      }
      foreach ((array) apply_filters('embed_forms_page_scripts', [], $config) as $src) {
        $scripts[] = (string) $src;
      }
      $scripts[] = add_query_arg('ver', $plugin->assetVersion('assets/form.js'), $plugin->url('assets/form.js'));
    }
    $css = add_query_arg('ver', $plugin->assetVersion('assets/form.css'), $plugin->url('assets/form.css'));
    ?><!doctype html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
<meta charset="<?php echo esc_attr(get_option('blog_charset')); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($title); ?></title>
<link rel="stylesheet" href="<?php echo esc_url($css); ?>">
<?php do_action('embed_forms_page_head'); ?>
</head>
<body class="ef-body<?php echo $embedded ? ' ef-embedded' : ''; ?>">
<main class="ef-page"><?php echo $body; // Built from escaped parts above. ?></main>
<?php if ($config !== NULL) : ?>
<script>window.EmbedFormsConfig = <?php echo wp_json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES); ?>;</script>
<?php foreach ($scripts as $src) : ?>
<script src="<?php echo esc_url($src); ?>"></script>
<?php endforeach; ?>
<?php elseif ($embedded) : ?>
<script>
(function () {
  var p = new URLSearchParams(location.search);
  if (window.parent !== window) {
    window.parent.postMessage({ source: 'embed-forms', frame: p.get('ef_frame') || '', type: 'height', height: Math.ceil(document.querySelector('.ef-page').getBoundingClientRect().height) }, '*');
  }
}());
</script>
<?php endif; ?>
</body>
</html>
<?php
  }

}
