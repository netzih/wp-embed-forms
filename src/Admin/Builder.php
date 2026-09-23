<?php

namespace EmbedForms\Admin;

use EmbedForms\Plugin;
use EmbedForms\Schema\Fields;

/**
 * The drag-and-drop builder on the editor's Fields tab. It is plain script
 * on WordPress's bundled React (wp-element, wp-components): no build step.
 */
final class Builder {

  public function register(): void {
    add_action('embed_forms_editor_load', [$this, 'load']);
    add_action('embed_forms_render_builder', [$this, 'render']);
  }

  public function load(int $formId): void {
    add_action('admin_enqueue_scripts', function () use ($formId): void {
      $plugin = Plugin::instance();
      $form = \EmbedForms\Db\Forms::find($formId);
      wp_enqueue_style('wp-components');
      wp_enqueue_style('embed-forms-builder', $plugin->url('assets/builder.css'), ['wp-components'], $plugin->assetVersion('assets/builder.css'));
      wp_enqueue_script('embed-forms-builder', $plugin->url('assets/builder.js'), ['wp-element', 'wp-components', 'wp-i18n'], $plugin->assetVersion('assets/builder.js'), TRUE);
      $palette = Fields::palette();
      if (!Plugin::paymentsAvailable()) {
        // Offered anyway so forms can be prepared, with a warning on the field.
        foreach (Fields::PAYMENT_TYPES as $type) {
          $palette[$type]['label'] .= ' *';
        }
      }
      wp_localize_script('embed-forms-builder', 'EmbedFormsBuilder', [
        'palette' => $palette,
        'groups' => [
          'basic' => __('Basic', 'embed-forms'),
          'choice' => __('Choices', 'embed-forms'),
          'layout' => __('Layout', 'embed-forms'),
          'payment' => __('Payment', 'embed-forms'),
        ],
        'frequencies' => array_combine(Fields::FREQUENCIES, array_map([Fields::class, 'frequencyLabel'], Fields::FREQUENCIES)),
        'paymentsAvailable' => Plugin::paymentsAvailable(),
        'submitLabel' => $form ? $form['settings']['submit_label'] : '',
      ]);
      wp_set_script_translations('embed-forms-builder', 'embed-forms');
    });
  }

  public function render(array $form): void {
    echo '<div id="ef-builder" class="ef-builder"><p>' . esc_html__('Loading the builder…', 'embed-forms') . '</p></div>';
  }

}
