<?php

namespace EmbedForms\Db;

/**
 * The database the plugin's tables live in. By default the WordPress
 * database; when EF_DB_NAME is defined (with EF_DB_USER, EF_DB_PASSWORD and
 * optionally EF_DB_HOST) in wp-config.php, a separate one reached through a
 * second wpdb connection. Table names always carry the WordPress prefix plus
 * "ef_", so one separate database can serve several sites.
 */
final class Db {

  private static ?\wpdb $connection = NULL;

  public static function wpdb(): \wpdb {
    global $wpdb;
    if (!self::isSeparate()) {
      return $wpdb;
    }
    if (self::$connection === NULL) {
      self::$connection = new \wpdb(
        (string) EF_DB_USER,
        (string) EF_DB_PASSWORD,
        (string) EF_DB_NAME,
        defined('EF_DB_HOST') ? (string) EF_DB_HOST : (string) DB_HOST
      );
      self::$connection->set_prefix($wpdb->prefix);
    }
    return self::$connection;
  }

  public static function isSeparate(): bool {
    return defined('EF_DB_NAME') && defined('EF_DB_USER') && defined('EF_DB_PASSWORD');
  }

  public static function table(string $name): string {
    global $wpdb;
    return $wpdb->prefix . 'ef_' . $name;
  }

  public static function now(): string {
    return gmdate('Y-m-d H:i:s');
  }

}
