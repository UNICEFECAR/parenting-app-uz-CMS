<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Restores the stored values that Drupal changes when it saves an entity.
 *
 * The entity API cannot store some source values as they are:
 * - revision_translation_affected: Drupal computes it when it is NULL (content
 *   saved before Drupal tracked it), so NULL becomes 1;
 * - the published status: content_moderation sets it from the moderation
 *   state, also while syncing, and some Bebbo translations are unpublished
 *   with a published state;
 * - the revision author: Node::preSaveRevision() replaces an author that no
 *   longer exists (a deleted user) with the owner;
 * - a user's empty timezone: User sets the site's default timezone (the
 *   anonymous user has none on Bebbo);
 * - content moderation states: content_moderation creates a state for every
 *   translation it saves (Bebbo has none for some translations, and Drupal
 *   then shows the state of the default translation) and removes the states
 *   of a translation that a later revision deleted (Bebbo kept them).
 *
 * Content moderation state entities are created by content_moderation with
 * this site's IDs; they get the source's IDs, UUIDs and revision IDs.
 *
 * After each import of a revisioned type, the migrated rows get the source
 * values with direct queries, so the tables are the same as on the source.
 *
 * @see \Drupal\bebbo_content_migrate\EventSubscriber\MigrateSubscriber
 */
final class SourceValueRestorer {

  /**
   * Number of entities per query.
   */
  private const CHUNK = 200;

  /**
   * The content moderation state table with one row per revision.
   */
  private const MODERATION_REVISION = 'content_moderation_state_field_revision';

  /**
   * The content moderation state table with the current revision.
   */
  private const MODERATION_DATA = 'content_moderation_state_field_data';

  public function __construct(
    private readonly Connection $database,
    private readonly SourceDatabase $source,
    private readonly SourceTables $tables,
    private readonly Selection $selection,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly CacheTagsInvalidatorInterface $cacheTagsInvalidator,
  ) {}

  /**
   * Restores the source values of the migrated entities of one type.
   *
   * @return array
   *   Counts of the rows changed: 'translation_values', 'revision_user',
   *   'moderation_updated', 'moderation_added', 'moderation_removed',
   *   'moderation_ids' (state entities given the source IDs) and
   *   'moderation_ids_unmapped' (state revisions the source has none for).
   */
  public function restore(string $entity_type_id): array {
    $counts = array_fill_keys(['translation_values', 'revision_user', 'moderation_updated', 'moderation_added', 'moderation_removed', 'moderation_ids', 'moderation_ids_unmapped'], 0);
    $moderated = $this->database->schema()->tableExists(self::MODERATION_REVISION)
      && $this->source->connection()->schema()->tableExists(self::MODERATION_REVISION);
    foreach (array_chunk($this->selection->ids($entity_type_id), self::CHUNK) as $chunk) {
      $revisions = $this->targetRevisions($entity_type_id, $chunk);
      if (!$revisions) {
        continue;
      }
      $languages = $this->tables->revisionLanguages($this->source->connection(), $entity_type_id, array_keys($revisions), $this->selection->langcodes());
      $counts['translation_values'] += $this->restoreTranslationValues($entity_type_id, $revisions, $languages);
      $counts['revision_user'] += $this->restoreRevisionUser($entity_type_id, array_keys($revisions));
      if ($moderated) {
        foreach ($this->restoreModeration($entity_type_id, $chunk, $revisions, $languages) as $key => $count) {
          $counts[$key] += $count;
        }
      }
    }
    if ($moderated) {
      [$counts['moderation_ids'], $counts['moderation_ids_unmapped']] = $this->restoreModerationIds($entity_type_id);
    }
    if (array_filter($counts)) {
      $this->entityTypeManager->getStorage($entity_type_id)->resetCache();
      if ($this->entityTypeManager->hasDefinition('content_moderation_state')) {
        $this->entityTypeManager->getStorage('content_moderation_state')->resetCache();
      }
      $this->cacheTagsInvalidator->invalidateTags(Cache::mergeTags([$entity_type_id . '_list'], $this->entityTypeManager->getDefinition($entity_type_id)->getListCacheTags()));
    }
    return $counts;
  }

  /**
   * Restores the empty timezones of the migrated users.
   *
   * @return int
   *   The number of users changed.
   */
  public function restoreUserTimezones(): int {
    $changed = 0;
    foreach (array_chunk($this->selection->ids('user'), 1000) as $chunk) {
      $empty = $this->source->connection()->select('users_field_data', 'u')
        ->fields('u', ['uid'])
        ->condition('u.uid', $chunk, 'IN')
        ->condition('u.timezone', '')
        ->execute()
        ->fetchCol();
      if ($empty) {
        $changed += $this->database->update('users_field_data')
          ->fields(['timezone' => ''])
          ->condition('uid', $empty, 'IN')
          ->condition('timezone', '', '<>')
          ->execute();
      }
    }
    if ($changed) {
      $this->entityTypeManager->getStorage('user')->resetCache();
    }
    return $changed;
  }

  /**
   * Returns the migrated revision translations on this site.
   *
   * @return array
   *   Keyed by revision ID and langcode: entity ID and original-language
   *   flag.
   */
  private function targetRevisions(string $entity_type_id, array $ids): array {
    $entity_type = $this->tables->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $query = $this->database->select($entity_type->getRevisionDataTable(), 'fr');
    $query->addField('fr', $id, 'id');
    $query->addField('fr', $revision, 'revision');
    $query->addField('fr', $langcode, 'langcode');
    $query->addField('fr', $entity_type->getKey('default_langcode'), 'is_default');
    $query->condition("fr.$id", $ids, 'IN');
    $revisions = [];
    foreach ($query->execute() as $record) {
      $revisions[$record->revision][$record->langcode] = $record;
    }
    return $revisions;
  }

  /**
   * Restores the translation values Drupal computes on save.
   *
   * revision_translation_affected and the published status, in the revision
   * data table and, for the current revision, the data table.
   *
   * @return int
   *   The number of revision translations changed.
   */
  private function restoreTranslationValues(string $entity_type_id, array $revisions, array $languages): int {
    $entity_type = $this->tables->entityType($entity_type_id);
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $columns = array_filter([
      $entity_type->getKey('revision_translation_affected'),
      $entity_type->getKey('published'),
    ]);
    $values = [];
    foreach (['source' => $this->source->connection(), 'target' => $this->database] as $side => $connection) {
      $query = $connection->select($entity_type->getRevisionDataTable(), 'fr')
        ->fields('fr', array_merge([$revision, $langcode], $columns))
        ->condition("fr.$revision", array_keys($revisions), 'IN');
      foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
        $values[$side][$row[$revision]][$row[$langcode]] = array_intersect_key($row, array_flip($columns));
      }
    }

    $changed = 0;
    foreach ($revisions as $revision_id => $translations) {
      $origin = array_flip($languages[$revision_id]['map'] ?? []);
      foreach (array_keys($translations) as $target_langcode) {
        $source = $values['source'][$revision_id][$origin[$target_langcode] ?? ''] ?? NULL;
        $target = $values['target'][$revision_id][$target_langcode] ?? NULL;
        if ($source === NULL || $target === NULL || $source === $target) {
          continue;
        }
        foreach ([$entity_type->getRevisionDataTable(), $entity_type->getDataTable()] as $table) {
          $this->database->update($table)
            ->fields($source)
            ->condition($revision, $revision_id)
            ->condition($langcode, $target_langcode)
            ->execute();
        }
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * Restores the revision author in the revision table.
   *
   * @return int
   *   The number of revisions changed.
   */
  private function restoreRevisionUser(string $entity_type_id, array $revision_ids): int {
    $entity_type = $this->tables->entityType($entity_type_id);
    $column = $entity_type->getRevisionMetadataKey('revision_user');
    if (!$column) {
      return 0;
    }
    $revision = $entity_type->getKey('revision');
    $values = [];
    foreach (['source' => $this->source->connection(), 'target' => $this->database] as $side => $connection) {
      $values[$side] = $connection->select($entity_type->getRevisionTable(), 'r')
        ->fields('r', [$revision, $column])
        ->condition("r.$revision", $revision_ids, 'IN')
        ->execute()
        ->fetchAllKeyed();
    }
    $changed = 0;
    foreach (array_intersect_key($values['source'], $values['target']) as $revision_id => $user_id) {
      if ($user_id !== $values['target'][$revision_id]) {
        $this->database->update($entity_type->getRevisionTable())
          ->fields([$column => $user_id])
          ->condition($revision, $revision_id)
          ->execute();
        $changed++;
      }
    }
    return $changed;
  }

  /**
   * Makes the content moderation state of each revision translation as on
   * the source.
   *
   * A translation without a state on the source loses its state here, except
   * in the original language, which the state entity cannot lose: it gets the
   * state Drupal shows on the source (that of the source's original
   * language).
   *
   * @return int[]
   *   'moderation_updated', 'moderation_added', 'moderation_removed'.
   */
  private function restoreModeration(string $entity_type_id, array $ids, array $revisions, array $languages): array {
    $counts = ['moderation_updated' => 0, 'moderation_added' => 0, 'moderation_removed' => 0];
    $source = $this->sourceStates($entity_type_id, array_keys($revisions));

    // The state rows here, the latest per revision translation.
    $target = [];
    $query = $this->database->select(self::MODERATION_REVISION, 'm')
      ->fields('m')
      ->condition('m.content_entity_type_id', $entity_type_id)
      ->condition('m.content_entity_id', $ids, 'IN')
      ->orderBy('m.revision_id');
    foreach ($query->execute()->fetchAll(\PDO::FETCH_ASSOC) as $row) {
      $target[$row['content_entity_revision_id']][$row['langcode']] = $row;
    }
    $current = [];
    if ($target) {
      $current = $this->database->select('content_moderation_state', 'c')
        ->fields('c', ['id', 'revision_id'])
        ->condition('c.id', array_unique(array_column(array_merge(...array_values($target)), 'id')), 'IN')
        ->execute()
        ->fetchAllKeyed();
    }

    foreach ($revisions as $revision_id => $translations) {
      $origin = array_flip($languages[$revision_id]['map'] ?? []);
      foreach ($translations as $langcode => $translation) {
        if (!isset($origin[$langcode])) {
          continue;
        }
        $state = $source[$revision_id]['states'][$origin[$langcode]] ?? NULL;
        if ($state === NULL && $translation->is_default) {
          $state = $source[$revision_id]['default'] ?? NULL;
          if ($state === NULL) {
            continue;
          }
        }
        $row = $target[$revision_id][$langcode] ?? NULL;
        if ($state === NULL) {
          if ($row) {
            $this->deleteState($row);
            $counts['moderation_removed']++;
          }
        }
        elseif (!$row) {
          if ($this->addState($target[$revision_id] ?? [], $langcode, $state, $current)) {
            $counts['moderation_added']++;
          }
        }
        elseif ($row['moderation_state'] !== $state) {
          $this->updateState($row, $state);
          $counts['moderation_updated']++;
        }
      }
    }
    return $counts;
  }

  /**
   * Gives the content moderation state entities the source's IDs.
   *
   * Each state revision here belongs to one revision of a moderated entity.
   * The latest one of each moderated revision gets the ID and revision ID of
   * the source's latest state revision of that revision. A moderated entity
   * normally has one state entity, but the source can have more (node 61881
   * on Bebbo): the revisions are then split between them as on the source.
   * Other state revisions (older ones of the same moderated revision, or of a
   * revision the source has no state for) keep their revision ID, in the
   * state entity of their latest mapped revision. The base and current-value
   * rows are rebuilt from the revisions. All IDs are first moved out of the
   * way (+ an offset), so that no new ID meets an old one.
   *
   * @return int[]
   *   The number of state entities given the source IDs, and the number of
   *   state revisions that kept their own revision ID.
   */
  private function restoreModerationIds(string $entity_type_id): array {
    $offset = 1000000000;
    $ids = $this->selection->ids($entity_type_id);
    $source = $this->source->connection();

    // The latest state revision of each moderated revision on the source:
    // [state ID, state revision ID], and the UUIDs of the state entities.
    $source_revisions = [];
    $uuids = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
      $query = $source->select(self::MODERATION_REVISION, 'm');
      $query->innerJoin('content_moderation_state', 'c', '[c].[id] = [m].[id]');
      $query->fields('m', ['id', 'revision_id', 'content_entity_revision_id'])
        ->fields('c', ['uuid'])
        ->condition('m.content_entity_type_id', $entity_type_id)
        ->condition('m.content_entity_id', $chunk, 'IN')
        ->orderBy('m.revision_id');
      foreach ($query->execute() as $row) {
        $source_revisions[$row->content_entity_revision_id] = [(int) $row->id, (int) $row->revision_id];
        $uuids[(int) $row->id] = $row->uuid;
      }
    }

    // The state revisions here, per state entity, oldest first.
    $target = [];
    foreach (array_chunk($ids, 1000) as $chunk) {
      $query = $this->database->select(self::MODERATION_REVISION, 'm')
        ->fields('m', ['id', 'revision_id', 'content_entity_revision_id'])
        ->condition('m.content_entity_type_id', $entity_type_id)
        ->condition('m.content_entity_id', $chunk, 'IN')
        ->orderBy('m.revision_id');
      foreach ($query->execute() as $row) {
        $target[(int) $row->id][(int) $row->revision_id] = $row->content_entity_revision_id;
      }
    }
    $uuids_here = $target ? $this->database->select('content_moderation_state', 'c')
      ->fields('c', ['id', 'uuid'])
      ->condition('c.id', array_keys($target), 'IN')
      ->execute()
      ->fetchAllKeyed() : [];

    // New [state ID, revision ID] of every state revision of the entities
    // that change.
    $map = [];
    $unmapped = 0;
    foreach ($target as $id => $revisions) {
      $latest = [];
      foreach ($revisions as $revision_id => $content_revision_id) {
        $latest[$content_revision_id] = $revision_id;
      }
      $entity_map = [];
      $fallback = NULL;
      foreach ($revisions as $revision_id => $content_revision_id) {
        if ($latest[$content_revision_id] === $revision_id && isset($source_revisions[$content_revision_id])) {
          $entity_map[$revision_id] = $source_revisions[$content_revision_id];
          $fallback = $source_revisions[$content_revision_id][0];
        }
      }
      if ($fallback === NULL) {
        continue;
      }
      $changed = FALSE;
      foreach ($revisions as $revision_id => $content_revision_id) {
        if (!isset($entity_map[$revision_id])) {
          $unmapped++;
          $entity_map[$revision_id] = [$fallback, $revision_id];
        }
        $changed = $changed || $entity_map[$revision_id] !== [$id, $revision_id];
      }
      $changed = $changed || ($uuids_here[$id] ?? NULL) !== ($uuids[$fallback] ?? NULL);
      if ($changed) {
        $map[$id] = $entity_map;
      }
    }
    if (!$map) {
      return [0, $unmapped];
    }

    $revision_tables = ['content_moderation_state_revision', self::MODERATION_REVISION];
    $current_tables = ['content_moderation_state', self::MODERATION_DATA];
    $transaction = $this->database->startTransaction();
    // The transaction commits when it goes out of scope, also on an error:
    // roll back, so that no state entity is left half rebuilt.
    try {
      foreach (array_chunk(array_keys($map), 1000) as $chunk) {
        foreach (array_merge($revision_tables, $current_tables) as $table) {
          $this->database->update($table)
            ->expression('id', '[id] + :offset', [':offset' => $offset])
            ->expression('revision_id', '[revision_id] + :offset', [':offset' => $offset])
            ->condition('id', $chunk, 'IN')
            ->execute();
        }
      }
      $new_ids = [];
      foreach ($map as $entity_map) {
        foreach ($entity_map as $old => [$new_id, $new_revision_id]) {
          foreach ($revision_tables as $table) {
            $this->database->update($table)
              ->fields(['id' => $new_id, 'revision_id' => $new_revision_id])
              ->condition('revision_id', $old + $offset)
              ->execute();
          }
          $new_ids[$new_id] = TRUE;
        }
      }
      // Rebuild the base and current-value rows: the current revision of each
      // state entity is its latest revision here. A state entity that already
      // had the source's ID (not moved) can get the revisions of another one
      // (node 7491): its rows are rebuilt too.
      $rebuild = array_merge(array_map(static fn($id) => $id + $offset, array_keys($map)), array_keys($new_ids));
      foreach (array_chunk($rebuild, 1000) as $chunk) {
        foreach ($current_tables as $table) {
          $this->database->delete($table)->condition('id', $chunk, 'IN')->execute();
        }
      }
      foreach (array_keys($new_ids) as $new_id) {
        $query = $this->database->select('content_moderation_state_revision', 'r')
          ->condition('r.id', $new_id);
        $query->addExpression('MAX([r].[revision_id])');
        $current = (int) $query->execute()->fetchField();
        $rows = $this->database->select(self::MODERATION_REVISION, 'm')
          ->fields('m')
          ->condition('m.revision_id', $current)
          ->execute()
          ->fetchAll(\PDO::FETCH_ASSOC);
        $langcode = NULL;
        foreach ($rows as $row) {
          $this->database->insert(self::MODERATION_DATA)->fields($row)->execute();
          if ($row['default_langcode']) {
            $langcode = $row['langcode'];
          }
        }
        $this->database->insert('content_moderation_state')->fields([
          'id' => $new_id,
          'revision_id' => $current,
          'uuid' => $uuids[$new_id],
          'langcode' => $langcode,
        ])->execute();
      }
    }
    catch (\Throwable $e) {
      $transaction->rollBack();
      throw $e;
    }
    unset($transaction);
    return [count($new_ids), $unmapped];
  }

  /**
   * Returns the source states of the given revisions, the latest per language.
   *
   * @return array
   *   Keyed by revision ID: 'states' (state keyed by langcode) and 'default'
   *   (the state of the state entity's original language).
   */
  private function sourceStates(string $entity_type_id, array $revision_ids): array {
    $states = [];
    $query = $this->source->connection()->select(self::MODERATION_REVISION, 'm')
      ->fields('m', ['content_entity_revision_id', 'langcode', 'moderation_state', 'default_langcode'])
      ->condition('m.content_entity_type_id', $entity_type_id)
      ->condition('m.content_entity_revision_id', $revision_ids, 'IN')
      ->orderBy('m.revision_id');
    foreach ($query->execute() as $record) {
      $states[$record->content_entity_revision_id]['states'][$record->langcode] = $record->moderation_state;
      if ($record->default_langcode) {
        $states[$record->content_entity_revision_id]['default'] = $record->moderation_state;
      }
    }
    return $states;
  }

  /**
   * Removes the states of one revision translation.
   */
  private function deleteState(array $row): void {
    foreach ([self::MODERATION_REVISION, self::MODERATION_DATA] as $table) {
      $this->database->delete($table)
        ->condition('content_entity_type_id', $row['content_entity_type_id'])
        ->condition('content_entity_revision_id', $row['content_entity_revision_id'])
        ->condition('langcode', $row['langcode'])
        ->execute();
    }
  }

  /**
   * Adds the state of a revision translation that has none here.
   *
   * The row is a copy of another translation of the same state revision, in
   * the current-revision table too when that state revision is the current
   * one (both tables have the same columns).
   *
   * @return bool
   *   FALSE when the revision has no state here to copy.
   */
  private function addState(array $translations, string $langcode, string $state, array $current): bool {
    $copy = NULL;
    foreach ($translations as $row) {
      if ($copy === NULL || $row['default_langcode']) {
        $copy = $row;
      }
    }
    if (!$copy) {
      return FALSE;
    }
    $copy['langcode'] = $langcode;
    $copy['default_langcode'] = 0;
    $copy['moderation_state'] = $state;
    $copy['revision_translation_affected'] = 1;
    $this->database->insert(self::MODERATION_REVISION)->fields($copy)->execute();
    if (($current[$copy['id']] ?? NULL) == $copy['revision_id']) {
      $this->database->merge(self::MODERATION_DATA)
        ->keys(['id' => $copy['id'], 'langcode' => $langcode])
        ->fields(array_diff_key($copy, ['id' => TRUE, 'langcode' => TRUE]))
        ->execute();
    }
    return TRUE;
  }

  /**
   * Changes the state of one revision translation.
   */
  private function updateState(array $row, string $state): void {
    foreach ([self::MODERATION_REVISION, self::MODERATION_DATA] as $table) {
      $this->database->update($table)
        ->fields(['moderation_state' => $state])
        ->condition('content_entity_type_id', $row['content_entity_type_id'])
        ->condition('content_entity_revision_id', $row['content_entity_revision_id'])
        ->condition('langcode', $row['langcode'])
        ->execute();
    }
  }

}
