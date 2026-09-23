<?php

namespace EmbedForms;

/**
 * Public addresses of a form and the codes that embed it elsewhere.
 */
final class Embed {

  public static function base(): string {
    $base = trim((string) apply_filters('embed_forms_base', 'f'), '/');
    return $base !== '' ? $base : 'f';
  }

  public static function url(array $form, array $query = []): string {
    $url = home_url('/' . self::base() . '/' . rawurlencode((string) $form['slug']) . '/');
    return $query ? add_query_arg($query, $url) : $url;
  }

  public static function loaderUrl(): string {
    return Plugin::instance()->url('assets/embed.js');
  }

  /**
   * The snippet for other sites: a placeholder and the loader, which turns
   * it into an auto-resizing iframe. The uuid keeps working if the form's
   * link name changes.
   */
  public static function scriptSnippet(array $form): string {
    return sprintf(
      "<div data-embed-form=\"%1\$s\"></div>\n<script src=\"%2\$s\" async></script>",
      esc_attr((string) $form['uuid']),
      esc_url(self::loaderUrl())
    );
  }

  /**
   * A plain iframe for site builders that strip scripts. It cannot resize
   * itself, so it gets a generous fixed height.
   */
  public static function iframeSnippet(array $form): string {
    return sprintf(
      '<iframe src="%1$s" title="%2$s" style="width:100%%;min-height:900px;border:0" allow="payment" loading="lazy"></iframe>',
      esc_url(home_url('/' . self::base() . '/' . $form['uuid'] . '/?ef_embed=1')),
      esc_attr((string) $form['title'])
    );
  }

  /**
   * [embed_form id="..."] on this site's own pages: the same loader, so the
   * form behaves exactly as it does elsewhere.
   */
  public static function shortcode(mixed $atts): string {
    $atts = shortcode_atts(['id' => ''], is_array($atts) ? $atts : [], 'embed_form');
    $form = Db\Forms::findPublic(sanitize_text_field((string) $atts['id']));
    if (!$form) {
      return '';
    }
    wp_enqueue_script('embed-forms-loader', self::loaderUrl(), [], Plugin::instance()->assetVersion('assets/embed.js'), TRUE);
    return '<div data-embed-form="' . esc_attr((string) $form['uuid']) . '"></div>';
  }

}
