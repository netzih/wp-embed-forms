<?php

namespace EmbedForms\Db;

/**
 * A timeline of what happened to an entry: payments, refunds, renewals,
 * cancellations and admin actions.
 */
final class Notes {

  public static function add(int $entryId, string $content, ?int $subscriptionId = NULL): void {
    Db::wpdb()->insert(Db::table('notes'), [
      'entry_id' => $entryId,
      'subscription_id' => $subscriptionId,
      'user_id' => function_exists('get_current_user_id') ? get_current_user_id() : 0,
      'content' => $content,
      'created_at' => Db::now(),
    ]);
  }

  /**
   * @return array[]
   */
  public static function forEntry(int $entryId): array {
    $db = Db::wpdb();
    return (array) $db->get_results($db->prepare('SELECT * FROM ' . Db::table('notes') . ' WHERE entry_id = %d ORDER BY id DESC', $entryId), ARRAY_A);
  }

}
