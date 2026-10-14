<?php

namespace EmbedForms\Render;

/**
 * A form's look on its public page: the fonts (Geist ships with the plugin,
 * the others come from Google Fonts), a brand colour with the shades the
 * stylesheet needs, the card or plain layout, and the corners. Pure PHP
 * apart from the URL helpers, so the colour maths can be unit tested.
 */
final class Design {

  /**
   * key => [label, CSS family, Google Fonts family spec or NULL].
   */
  public const FONTS = [
    'geist' => ['Geist', '"Geist", system-ui, -apple-system, "Segoe UI", Roboto, sans-serif', NULL],
    'system' => ['System', 'system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif', NULL],
    'inter' => ['Inter', '"Inter", system-ui, sans-serif', 'Inter:wght@400;500;600;700'],
    'dm-sans' => ['DM Sans', '"DM Sans", system-ui, sans-serif', 'DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600;9..40,700'],
    'nunito' => ['Nunito', '"Nunito", system-ui, sans-serif', 'Nunito:wght@400;600;700;800'],
    'poppins' => ['Poppins', '"Poppins", system-ui, sans-serif', 'Poppins:wght@400;500;600;700'],
    'lora' => ['Lora', '"Lora", Georgia, serif', 'Lora:wght@400;500;600;700'],
    'merriweather' => ['Merriweather', '"Merriweather", Georgia, serif', 'Merriweather:wght@400;700'],
    'baloo-2' => ['Baloo 2', '"Baloo 2", system-ui, sans-serif', 'Baloo+2:wght@500;600;700;800'],
    'playfair-display' => ['Playfair Display', '"Playfair Display", Georgia, serif', 'Playfair+Display:wght@500;600;700'],
  ];

  /**
   * @return array<string, string>
   *   key => label, for the editor.
   */
  public static function fontChoices(): array {
    return array_map(static fn(array $font) => $font[0], self::FONTS);
  }

  /**
   * The Google Fonts stylesheet for the form's fonts, or ''.
   */
  public static function fontsUrl(array $settings): string {
    $families = [];
    foreach (array_unique(array_filter([$settings['font'] ?? '', $settings['heading_font'] ?? ''])) as $key) {
      if (!empty(self::FONTS[$key][2])) {
        $families[] = 'family=' . self::FONTS[$key][2];
      }
    }
    return $families ? 'https://fonts.googleapis.com/css2?' . implode('&', $families) . '&display=swap' : '';
  }

  public static function usesGeist(array $settings): bool {
    return in_array('geist', [$settings['font'] ?? 'geist', ($settings['heading_font'] ?? '') ?: ($settings['font'] ?? 'geist')], TRUE);
  }

  /**
   * Body classes: accent preset, layout and corners.
   */
  public static function bodyClasses(array $settings): array {
    $classes = ['ef-accent-' . (($settings['accent'] ?? 'blue') === 'custom' ? 'custom' : ($settings['accent'] ?? 'blue'))];
    $classes[] = 'ef-layout-' . ($settings['layout'] ?? 'card');
    $classes[] = 'ef-corners-' . ($settings['corners'] ?? 'soft');
    if (self::isDesigned($settings)) {
      $classes[] = 'ef-designed';
    }
    return $classes;
  }

  /**
   * Whether the form chose any of the design options; forms that did not
   * look exactly as before they existed.
   */
  public static function isDesigned(array $settings): bool {
    return ($settings['accent'] ?? 'blue') === 'custom'
      || ($settings['font'] ?? 'geist') !== 'geist'
      || ($settings['heading_font'] ?? '') !== ''
      || ($settings['layout'] ?? 'card') !== 'card'
      || ($settings['corners'] ?? 'soft') !== 'soft';
  }

  /**
   * CSS custom properties for the fonts and a custom accent colour, as the
   * body of a :root rule, or ''.
   */
  public static function cssVariables(array $settings): string {
    $vars = [];
    $font = self::FONTS[$settings['font'] ?? 'geist'] ?? self::FONTS['geist'];
    if (($settings['font'] ?? 'geist') !== 'geist') {
      $vars['--ef-font'] = $font[1];
    }
    $heading = $settings['heading_font'] ?? '';
    if ($heading !== '' && isset(self::FONTS[$heading])) {
      $vars['--ef-heading-font'] = self::FONTS[$heading][1];
    }
    if (($settings['accent'] ?? '') === 'custom' && ($settings['accent_color'] ?? '') !== '') {
      $vars += self::accentVariables($settings['accent_color']);
    }
    $out = '';
    foreach ($vars as $name => $value) {
      $out .= $name . ':' . $value . ';';
    }
    return $out;
  }

  /**
   * The accent shades the stylesheet uses, from one brand colour. Text on
   * the accent is white or near-black, whichever reads better.
   *
   * @return array<string, string>
   */
  public static function accentVariables(string $hex): array {
    [$r, $g, $b] = self::rgb($hex);
    $light = self::luminance($r, $g, $b) > 0.42;
    return [
      '--ef-accent' => self::hex($r, $g, $b),
      '--ef-accent-hover' => self::hex(...self::mix([$r, $g, $b], [0, 0, 0], 0.12)),
      '--ef-accent-active' => self::hex(...self::mix([$r, $g, $b], [0, 0, 0], 0.22)),
      // Links and selected text on white: darkened until it reads.
      '--ef-accent-ink' => self::hex(...self::readableOnWhite([$r, $g, $b])),
      '--ef-accent-text' => $light ? '#1d1b18' : '#fff',
      '--ef-accent-tint' => self::hex(...self::mix([$r, $g, $b], [255, 255, 255], 0.92)),
      '--ef-ring' => sprintf('rgba(%d, %d, %d, .16)', $r, $g, $b),
    ];
  }

  /**
   * @return int[]
   */
  private static function rgb(string $hex): array {
    $hex = ltrim($hex, '#');
    return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
  }

  private static function hex(int $r, int $g, int $b): string {
    return sprintf('#%02x%02x%02x', $r, $g, $b);
  }

  /**
   * @return int[]
   */
  private static function mix(array $from, array $to, float $amount): array {
    return array_map(static fn($a, $b) => (int) round($a + ($b - $a) * $amount), $from, $to);
  }

  private static function luminance(int $r, int $g, int $b): float {
    $channel = static function (int $c): float {
      $c /= 255;
      return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
    };
    return 0.2126 * $channel($r) + 0.7152 * $channel($g) + 0.0722 * $channel($b);
  }

  /**
   * @return int[]
   */
  private static function readableOnWhite(array $rgb): array {
    for ($step = 0; $step <= 10; $step++) {
      $color = self::mix($rgb, [0, 0, 0], $step * 0.08);
      // Contrast with white of at least 4.5:1.
      if (1.05 / (self::luminance(...$color) + 0.05) >= 4.5) {
        return $color;
      }
    }
    return [0, 0, 0];
  }

}
