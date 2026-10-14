<?php
/**
 * Embed Forms: Full page. The page content only, no theme header or footer.
 */

defined('ABSPATH') || exit;
?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>html,body{margin:0;padding:0}body.ef-full-page{background:#fff}.ef-full-page-content>*{margin-top:0;margin-bottom:0}</style>
</head>
<body <?php body_class('ef-full-page'); ?>>
<?php wp_body_open(); ?>
<main class="ef-full-page-content">
<?php
while (have_posts()) {
  the_post();
  the_content();
}
?>
</main>
<?php wp_footer(); ?>
</body>
</html>
