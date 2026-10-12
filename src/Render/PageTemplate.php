<?php

namespace EmbedForms\Render;

use EmbedForms\Plugin;

/**
 * A page template any theme gets, "Embed Forms: Full page": the page's
 * content alone, without the theme's header, footer or stylesheets, for
 * campaign pages that bring their own design (a Custom HTML block with an
 * embedded form and the campaign script). WordPress's own head and footer
 * hooks still run, so the admin bar, SEO and analytics plugins work.
 */
final class PageTemplate {

  public const TEMPLATE = 'embed-forms-full-page';

  public function register(): void {
    add_filter('theme_page_templates', [$this, 'templates']);
    add_filter('template_include', [$this, 'include'], 99);
    add_action('wp_enqueue_scripts', [$this, 'dropThemeStyles'], 999);
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
   * core and plugin styles stay.
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

}
