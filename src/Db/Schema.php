<?php

namespace EmbedForms\Db;

/**
 * Table definitions, applied with dbDelta whenever the stored schema
 * version is older than this file's. The payment and subscription tables
 * exist from the start so later releases only add columns.
 *
 * 3: gateway and account on payments and subscriptions (empty on older rows
 * means USAePay's default account), customer_reference for Stripe.
 */
final class Schema {

  public const VERSION = '3';

  private const OPTION = 'embed_forms_db_version';

  public static function maybeUpgrade(): void {
    if (get_option(self::OPTION) !== self::VERSION) {
      self::install();
    }
  }

  public static function install(): void {
    global $wpdb;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    $db = Db::wpdb();
    $charset = $db->get_charset_collate();
    $forms = Db::table('forms');
    $versions = Db::table('form_versions');
    $entries = Db::table('entries');
    $payments = Db::table('payments');
    $subscriptions = Db::table('subscriptions');
    $notes = Db::table('notes');

    $sql = [];
    $sql[] = "CREATE TABLE {$forms} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(32) NOT NULL,
  slug varchar(190) NOT NULL,
  title varchar(255) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'draft',
  schema_json longtext NOT NULL,
  settings_json longtext NOT NULL,
  version int(10) unsigned NOT NULL DEFAULT 1,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  UNIQUE KEY slug (slug),
  KEY status (status)
) {$charset};";
    $sql[] = "CREATE TABLE {$versions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  version int(10) unsigned NOT NULL,
  schema_json longtext NOT NULL,
  created_at datetime NOT NULL,
  created_by bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY form_version (form_id,version)
) {$charset};";
    $sql[] = "CREATE TABLE {$entries} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  form_id bigint(20) unsigned NOT NULL,
  form_version int(10) unsigned NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'submitted',
  data_json longtext NOT NULL,
  payer_email varchar(190) NOT NULL DEFAULT '',
  amount decimal(12,2) DEFAULT NULL,
  ip varchar(45) NOT NULL DEFAULT '',
  user_agent varchar(255) NOT NULL DEFAULT '',
  source_url text NULL,
  submission_key varchar(64) NOT NULL DEFAULT '',
  is_read tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY form_status (form_id,status),
  KEY created_at (created_at),
  KEY payer_email (payer_email),
  KEY submission (form_id,submission_key)
) {$charset};";
    $sql[] = "CREATE TABLE {$payments} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  kind varchar(20) NOT NULL DEFAULT 'charge',
  status varchar(20) NOT NULL DEFAULT 'pending',
  amount decimal(12,2) NOT NULL DEFAULT 0,
  refunded_amount decimal(12,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'USD',
  mode varchar(10) NOT NULL DEFAULT '',
  gateway varchar(20) NOT NULL DEFAULT '',
  account varchar(64) NOT NULL DEFAULT '',
  method varchar(20) NOT NULL DEFAULT '',
  orderid varchar(64) NOT NULL DEFAULT '',
  transaction_key varchar(64) NOT NULL DEFAULT '',
  refnum varchar(64) NOT NULL DEFAULT '',
  auth_code varchar(32) NOT NULL DEFAULT '',
  card_brand varchar(32) NOT NULL DEFAULT '',
  card_last4 varchar(4) NOT NULL DEFAULT '',
  gateway_message text NULL,
  marker_json text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY subscription_id (subscription_id),
  KEY orderid (orderid)
) {$charset};";
    $sql[] = "CREATE TABLE {$subscriptions} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  form_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  mode varchar(10) NOT NULL DEFAULT '',
  gateway varchar(20) NOT NULL DEFAULT '',
  account varchar(64) NOT NULL DEFAULT '',
  customer_reference varchar(64) NOT NULL DEFAULT '',
  card_reference varchar(64) NOT NULL DEFAULT '',
  card_brand varchar(32) NOT NULL DEFAULT '',
  card_last4 varchar(4) NOT NULL DEFAULT '',
  amount decimal(12,2) NOT NULL DEFAULT 0,
  currency char(3) NOT NULL DEFAULT 'USD',
  interval_length smallint(5) unsigned NOT NULL DEFAULT 1,
  interval_unit varchar(10) NOT NULL DEFAULT 'month',
  recurring_times int(10) unsigned NOT NULL DEFAULT 0,
  payments_made int(10) unsigned NOT NULL DEFAULT 0,
  failed_attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  schedule_start datetime NOT NULL,
  installment_index int(10) unsigned NOT NULL DEFAULT 1,
  next_charge datetime DEFAULT NULL,
  last_error text NULL,
  cancelled_at datetime DEFAULT NULL,
  marker_json text NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id),
  KEY due (status,next_charge)
) {$charset};";

    $sql[] = "CREATE TABLE {$notes} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  entry_id bigint(20) unsigned NOT NULL,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  content text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY entry_id (entry_id)
) {$charset};";

    // dbDelta works on the global connection; point it at ours for a
    // separate database.
    $original = $wpdb;
    $wpdb = $db;
    try {
      dbDelta($sql);
    }
    finally {
      $wpdb = $original;
    }
    update_option(self::OPTION, self::VERSION, TRUE);
  }

}
