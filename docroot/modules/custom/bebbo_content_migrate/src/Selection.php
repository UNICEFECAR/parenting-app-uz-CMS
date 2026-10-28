<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\State\StateInterface;

/**
 * The Bebbo entities and languages chosen for the migration.
 *
 * Written by drush bebbo-content-migrate:select, read by the source plugin
 * and the verifier. The IDs are kept in the bebbo_content_migrate_selection
 * table of this site; the settings in state.
 */
final class Selection {

  /**
   * The table holding the selected entity IDs.
   */
  public const TABLE = 'bebbo_content_migrate_selection';

  /**
   * The state key holding the selection settings.
   */
  public const STATE_KEY = 'bebbo_content_migrate.settings';

  /**
   * The selected feed IDs, as keys; NULL until read.
   */
  private ?array $feeds = NULL;

  public function __construct(
    private readonly Connection $database,
    private readonly StateInterface $state,
  ) {}

  /**
   * Returns the selected IDs of one entity type, sorted.
   *
   * @return int[]
   *   The entity IDs.
   */
  public function ids(string $entity_type_id): array {
    return array_map('intval', $this->database->select(self::TABLE, 's')
      ->fields('s', ['entity_id'])
      ->condition('entity_type', $entity_type_id)
      ->orderBy('entity_id')
      ->execute()
      ->fetchCol());
  }

  /**
   * Replaces the selection.
   *
   * @param array $ids
   *   Entity IDs keyed by entity type ID.
   * @param array $settings
   *   The settings the selection was made with. 'langcodes' holds the
   *   languages whose translations are migrated.
   */
  public function save(array $ids, array $settings): void {
    $transaction = $this->database->startTransaction();
    $this->database->truncate(self::TABLE)->execute();
    foreach ($ids as $entity_type_id => $entity_ids) {
      foreach (array_chunk($entity_ids, 1000) as $chunk) {
        $insert = $this->database->insert(self::TABLE)->fields(['entity_type', 'entity_id']);
        foreach ($chunk as $id) {
          $insert->values([$entity_type_id, $id]);
        }
        $insert->execute();
      }
    }
    $this->state->set(self::STATE_KEY, $settings);
    $this->feeds = NULL;
    unset($transaction);
  }

  /**
   * Keeps the Feeds items that point at a selected feed.
   *
   * Content imported by a feed that is not migrated (another country's, or
   * one for all countries such as "Media - Image") is migrated without that
   * link.
   *
   * @param array $items
   *   Items of a feeds_item field.
   *
   * @return array
   *   The items whose feed is selected.
   */
  public function feedItems(array $items): array {
    $this->feeds ??= array_flip($this->ids('feeds_feed'));
    return array_values(array_filter($items, fn(array $item): bool => isset($this->feeds[(int) ($item['target_id'] ?? 0)])));
  }

  /**
   * Whether a source row of a feeds_item field table is migrated.
   */
  public function keepsFeedItemRow(array $row, string $field_name): bool {
    return (bool) $this->feedItems([['target_id' => $row[$field_name . '_target_id'] ?? NULL]]);
  }

  /**
   * Returns the settings of the selection, or an empty array.
   */
  public function settings(): array {
    return $this->state->get(self::STATE_KEY, []);
  }

  /**
   * Returns the languages whose translations are migrated.
   *
   * The original language of each entity is migrated too, see
   * SourceTables::rowsQuery().
   *
   * @return string[]
   *   Language codes.
   */
  public function langcodes(): array {
    return $this->settings()['langcodes'] ?? [];
  }

}
