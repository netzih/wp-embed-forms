<?php

namespace EmbedForms\Admin;

/**
 * A settings screen split into sections: a list on the left with each
 * section's current state, one section shown at a time on the right
 * (assets/admin.js builds the list from the sections). Without JavaScript
 * every section shows, one under the other.
 *
 * Inside a section, an element with data-ef-show-if="NAME" shows only while
 * the checkbox NAME is ticked, "NAME=VALUE" only while the radio NAME has
 * that value.
 */
final class Sections {

  /**
   * Start the sections. The section to open comes from ?section=.
   */
  public static function start(string $label): void {
    $current = isset($_GET['section']) ? sanitize_key((string) $_GET['section']) : '';
    ?>
    <div class="ef-sections" data-current="<?php echo esc_attr($current); ?>">
      <script>document.currentScript.parentNode.classList.add('ef-sections-js');</script>
      <nav class="ef-section-nav" aria-label="<?php echo esc_attr($label); ?>"></nav>
      <div class="ef-section-panels">
        <input type="hidden" name="section" value="<?php echo esc_attr($current); ?>" class="ef-section-input">
    <?php
  }

  public static function end(): void {
    echo '</div></div>';
  }

  /**
   * Open one section.
   *
   * @param string $state
   *   A few words on how it is set up now, shown under its name in the list.
   * @param string $toggle
   *   The name of the checkbox that turns the section on, if any: its state
   *   then reads On or Off as it is ticked.
   * @param string $tone
   *   'good' (green) or 'bad' (red) for the state; neutral otherwise.
   */
  public static function open(string $key, string $label, string $state = '', string $toggle = '', string $tone = ''): void {
    ?>
    <section class="ef-section" id="ef-section-<?php echo esc_attr($key); ?>" data-section="<?php echo esc_attr($key); ?>" data-label="<?php echo esc_attr($label); ?>" data-state="<?php echo esc_attr($state); ?>"<?php echo $toggle !== '' ? ' data-toggle="' . esc_attr($toggle) . '"' : ''; ?><?php echo $tone !== '' ? ' data-tone="' . esc_attr($tone) . '"' : ''; ?>>
      <h2 class="ef-section-title"><?php echo esc_html($label); ?></h2>
    <?php
  }

  public static function close(): void {
    echo '</section>';
  }

  /**
   * Words for a section that is on or off.
   */
  public static function onOff(bool $on): string {
    return $on ? __('On', 'embed-forms') : __('Off', 'embed-forms');
  }

}
