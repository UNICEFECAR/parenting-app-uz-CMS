<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;

/**
 * Compares the migrated entities with the source, table by table.
 *
 * For node, media and taxonomy_term every stored value of every migrated
 * revision and language is compared (base, data, revision and field tables,
 * current and revision), plus the content moderation state of each revision
 * translation. For user, file and path_alias the current values are compared.
 * The selected rows of the tables that are not entity types
 * (SelectionBuilder::TABLES) are compared by ID.
 * Rows are compared by key (entity, revision, language, delta) and content.
 */
final class Verifier {

  /**
   * Number of entities per query.
   */
  private const CHUNK = 100;

  /**
   * Number of sample keys reported per difference.
   */
  private const SAMPLES = 5;

  public function __construct(
    private readonly Connection $database,
    private readonly SourceDatabase $source,
    private readonly SourceTables $tables,
    private readonly Selection $selection,
  ) {}

  /**
   * Compares all migrated tables.
   *
   * @return array
   *   One result per table: 'table', 'source' and 'target' (row counts),
   *   'missing', 'extra' and 'different' (row counts), 'samples' (keys).
   */
  public function verify(): array {
    $results = [];
    foreach (SourceTables::REVISIONED as $entity_type_id) {
      array_push($results, ...$this->verifyRevisioned($entity_type_id));
    }
    foreach (SourceTables::CURRENT as $entity_type_id) {
      array_push($results, ...$this->verifyCurrent($entity_type_id));
    }
    foreach (SelectionBuilder::TABLES as $table => $info) {
      if ($this->database->schema()->tableExists($table)) {
        $results[] = $this->compare($table, [$info['id']], array_chunk($this->selection->ids($table), self::CHUNK * 10),
          fn($connection, $chunk) => $connection->select($table, 't')->fields('t')->condition('t.' . $info['id'], $chunk, 'IN'));
      }
    }
    return $results;
  }

  /**
   * Compares the entity IDs, UUIDs and revision IDs with the source.
   *
   * For each migrated entity type, and the groups (created by
   * bebbo-country-config with the source's IDs): every selected source
   * entity must exist here with the same ID and UUID, and every entity here
   * must be one of them, so nothing was created here instead. The same for
   * the migrated revision IDs of the revisioned types, and for the content
   * moderation state entities and revisions (see verifyModerationIds()).
   *
   * @return array
   *   One result per entity type (and per revisioned type, one for its
   *   revisions), as verify() returns them.
   */
  public function verifyIds(): array {
    $source = $this->source->connection();
    $types = array_keys(SelectionBuilder::MIGRATIONS);
    if ($this->tables->hasEntityType('group')) {
      $types[] = 'group';
    }
    $results = [];
    foreach ($types as $entity_type_id) {
      $entity_type = $this->tables->entityType($entity_type_id);
      $id = $entity_type->getKey('id');
      $uuid = $entity_type->getKey('uuid');
      $base_table = $entity_type->getBaseTable();
      $all = $this->database->select($base_table, 'b')->fields('b', [$id, $uuid])->execute()->fetchAllKeyed();
      $ids = $entity_type_id === 'group' ? array_keys($all) : $this->selection->ids($entity_type_id);
      $expected = [];
      foreach (array_chunk($ids, self::CHUNK * 10) as $chunk) {
        $expected += $source->select($base_table, 'b')->fields('b', [$id, $uuid])->condition("b.$id", $chunk, 'IN')->execute()->fetchAllKeyed();
      }
      $results[] = $this->result("IDs and UUIDs ($entity_type_id)", $expected, $all);

      if ($this->tables->isRevisioned($entity_type_id)) {
        $revision = $entity_type->getKey('revision');
        $expected = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
          foreach ($this->tables->rowsQuery($source, $entity_type_id, $chunk, $this->selection->langcodes())->execute() as $record) {
            $expected[$record->$revision] = $record->$id;
          }
        }
        $here = $this->database->select($entity_type->getRevisionTable(), 'r')->fields('r', [$revision, $id])->execute()->fetchAllKeyed();
        $results[] = $this->result("revision IDs ($entity_type_id)", $expected, $here);
        $migrated_revisions[$entity_type_id] = $expected;
      }
    }
    if ($this->tables->hasEntityType('content_moderation_state') && $source->schema()->tableExists('content_moderation_state')) {
      array_push($results, ...$this->verifyModerationIds($migrated_revisions ?? []));
    }
    return $results;
  }

  /**
   * Compares the content moderation state IDs, UUIDs and revision IDs.
   *
   * The state entities of the migrated entities must have the source's IDs
   * and UUIDs, and each migrated revision's state revision the ID of the
   * source's latest state revision of that revision. Every state entity and
   * revision here is compared, so others show as extra.
   *
   * @param array $migrated_revisions
   *   Per revisioned entity type, the migrated revision IDs (as keys).
   */
  private function verifyModerationIds(array $migrated_revisions): array {
    $source = $this->source->connection();
    $expected = [];
    $expected_revisions = [];
    foreach ($migrated_revisions as $entity_type_id => $revisions) {
      foreach (array_chunk(array_keys($revisions), self::CHUNK * 10) as $chunk) {
        $query = $source->select('content_moderation_state_field_revision', 'm');
        $query->innerJoin('content_moderation_state', 'c', '[c].[id] = [m].[id]');
        $query->fields('m', ['id', 'revision_id', 'content_entity_revision_id'])
          ->fields('c', ['uuid'])
          ->condition('m.content_entity_type_id', $entity_type_id)
          ->condition('m.content_entity_revision_id', $chunk, 'IN')
          ->orderBy('m.revision_id');
        $latest = [];
        foreach ($query->execute() as $row) {
          $expected[$row->id] = $row->uuid;
          $latest[$row->content_entity_revision_id] = $row;
        }
        foreach ($latest as $row) {
          $expected_revisions[$row->revision_id] = $row->id;
        }
      }
    }
    $here = $this->database->select('content_moderation_state', 'c')->fields('c', ['id', 'uuid'])->execute()->fetchAllKeyed();
    $here_revisions = $this->database->select('content_moderation_state_revision', 'r')->fields('r', ['revision_id', 'id'])->execute()->fetchAllKeyed();
    return [
      $this->result('IDs and UUIDs (content_moderation_state)', $expected, $here),
      $this->result('revision IDs (content_moderation_state)', $expected_revisions, $here_revisions),
    ];
  }

  /**
   * Compares the tables of an entity type migrated with its revisions.
   *
   * The source rows are first given their language on this site (see
   * SourceTables::revisionLanguages()): a translation whose original language
   * is not kept is compared with the kept translation that became the
   * original.
   */
  private function verifyRevisioned(string $entity_type_id): array {
    $source = $this->source->connection();
    $entity_type = $this->tables->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $default_langcode = $entity_type->getKey('default_langcode');
    $ids = $this->selection->ids($entity_type_id);
    $langcodes = $this->selection->langcodes();
    $chunks = array_chunk($ids, self::CHUNK);

    // The migrated revisions, the current revision of each entity, and the
    // languages of each revision here.
    $revision_ids = [];
    $current = [];
    foreach ($chunks as $chunk) {
      foreach ($this->tables->rowsQuery($source, $entity_type_id, $chunk, $langcodes)->execute() as $record) {
        $revision_ids[$record->$revision] = TRUE;
      }
      $current += $source->select($entity_type->getBaseTable(), 'b')
        ->fields('b', [$id, $revision])
        ->condition("b.$id", $chunk, 'IN')
        ->execute()
        ->fetchAllKeyed();
    }
    $revision_ids = array_keys($revision_ids);
    sort($revision_ids);
    $languages = $this->tables->revisionLanguages($source, $entity_type_id, array_unique(array_merge($revision_ids, array_values($current))), $langcodes);
    $migrated = array_flip($revision_ids);

    // Gives a source row its language here; NULL when it is not migrated.
    $relabel = function (array $row, int $revision_id, string $langcode_column, ?string $field_name = NULL) use ($languages, $entity_type_id, $default_langcode): ?array {
      $info = $languages[$revision_id] ?? NULL;
      if (!$info || $info['default'] === NULL) {
        return NULL;
      }
      if ($field_name && !$this->tables->isTranslatable($entity_type_id, $row['bundle'], $field_name)) {
        // Stored once, in the original language.
        $row[$langcode_column] = $info['default'];
        return $row;
      }
      if (!isset($info['map'][$row[$langcode_column]])) {
        return NULL;
      }
      $row[$langcode_column] = $info['map'][$row[$langcode_column]];
      if (array_key_exists($default_langcode, $row)) {
        $row[$default_langcode] = (string) (int) ($row[$langcode_column] === $info['default']);
      }
      return $row;
    };

    $results = [];
    $results[] = $this->compare($entity_type->getRevisionDataTable(), [$revision, $langcode], $chunks,
      fn($connection, $chunk) => $connection->select($entity_type->getRevisionDataTable(), 't')->fields('t')->condition("t.$id", $chunk, 'IN'),
      fn($row) => isset($migrated[$row[$revision]]) ? $relabel($row, (int) $row[$revision], $langcode) : NULL);
    $results[] = $this->compare($entity_type->getRevisionTable(), [$revision], array_chunk($revision_ids, self::CHUNK * 10),
      fn($connection, $chunk) => $connection->select($entity_type->getRevisionTable(), 't')->fields('t')->condition("t.$revision", $chunk, 'IN'),
      function (array $row) use ($languages, $revision, $langcode): array {
        $row[$langcode] = $languages[$row[$revision]]['default'];
        return $row;
      });
    $results[] = $this->compare($entity_type->getBaseTable(), [$id], $chunks,
      fn($connection, $chunk) => $connection->select($entity_type->getBaseTable(), 't')->fields('t')->condition("t.$id", $chunk, 'IN'),
      function (array $row) use ($languages, $revision, $langcode): ?array {
        $row[$langcode] = $languages[$row[$revision]]['default'] ?? NULL;
        return $row[$langcode] === NULL ? NULL : $row;
      });
    $results[] = $this->compare($entity_type->getDataTable(), [$id, $langcode], $chunks,
      fn($connection, $chunk) => $connection->select($entity_type->getDataTable(), 't')->fields('t')->condition("t.$id", $chunk, 'IN'),
      fn($row) => $relabel($row, (int) $current[$row[$id]], $langcode));

    $mapping = $this->tables->mapping($entity_type_id);
    foreach ($this->tables->storedFields($entity_type_id) as $name => $definition) {
      if (!$mapping->requiresDedicatedTableStorage($definition)) {
        continue;
      }
      // Feeds items that point at a feed not migrated are left out.
      $is_feeds_item = $definition->getType() === 'feeds_item';
      $data_table = $mapping->getDedicatedDataTableName($definition);
      $results[] = $this->compare($data_table, ['entity_id', 'langcode', 'delta'], $chunks,
        fn($connection, $chunk) => $connection->select($data_table, 't')->fields('t')->condition('t.entity_id', $chunk, 'IN')->condition('t.deleted', 0),
        fn($row) => $is_feeds_item && !$this->selection->keepsFeedItemRow($row, $name) ? NULL : $relabel($row, (int) $current[$row['entity_id']], 'langcode', $name));
      if (!$definition->isRevisionable()) {
        continue;
      }
      $revision_table = $mapping->getDedicatedRevisionTableName($definition);
      // Join the source table to the migrated rows instead of reading every
      // revision: Bebbo keeps far more revisions than are migrated.
      $results[] = $this->compare($revision_table, ['revision_id', 'langcode', 'delta'], $chunks,
        function (Connection $connection, array $chunk) use ($source, $revision_table, $entity_type_id, $langcodes, $revision): SelectInterface {
          $query = $connection->select($revision_table, 't')->fields('t')->condition('t.deleted', 0);
          if ($connection === $source) {
            $rows = $source->select($this->tables->rowsQuery($source, $entity_type_id, $chunk, $langcodes), 's');
            $rows->addField('s', $revision);
            $rows->distinct();
            $query->innerJoin($rows, 'v', "[v].[$revision] = [t].[revision_id]");
          }
          else {
            $query->condition('t.entity_id', $chunk, 'IN');
          }
          return $query;
        },
        fn($row) => $is_feeds_item && !$this->selection->keepsFeedItemRow($row, $name) ? NULL : $relabel($row, (int) $row['revision_id'], 'langcode', $name));
    }

    $results[] = $this->compareModeration($entity_type_id, $chunks, $migrated, $languages);
    return $results;
  }

  /**
   * Compares the tables of an entity type migrated with its current values.
   */
  private function verifyCurrent(string $entity_type_id): array {
    $entity_type = $this->tables->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $chunks = array_chunk($this->selection->ids($entity_type_id), self::CHUNK * 10);
    // Feeds counts the items of a feed on this site when it is saved, and
    // fills in its default periodic import setting when it is empty. A user's
    // last access and login change whenever the account is used, on either
    // site. A file saved while it is already on disk gets its real size, and
    // Bebbo keeps the size of many images from before they were made smaller.
    $ignore = match ($entity_type_id) {
      'feeds_feed' => ['item_count', 'periodic_import'],
      'user' => ['access', 'login'],
      'file' => ['filesize'],
      default => [],
    };
    // A translatable entity (user) in a language this site does not have gets
    // the default language here, see SourceTables::currentLangcode().
    $langcodes = $this->selection->langcodes();
    $relabel = $entity_type->isTranslatable()
      ? function (array $row) use ($langcodes): array {
        $row['langcode'] = $this->tables->currentLangcode($row['langcode'], $langcodes);
        return $row;
      }
    : NULL;
    $results = [];
    $results[] = $this->compare($entity_type->getBaseTable(), [$id], $chunks,
      fn($connection, $chunk) => $connection->select($entity_type->getBaseTable(), 't')->fields('t')->condition("t.$id", $chunk, 'IN'),
      $relabel, $ignore);
    if ($data_table = $entity_type->getDataTable()) {
      $results[] = $this->compare($data_table, [$id, 'langcode'], $chunks,
        fn($connection, $chunk) => $connection->select($data_table, 't')->fields('t')->condition("t.$id", $chunk, 'IN')->condition('t.default_langcode', 1),
        $relabel, $ignore);
    }
    $definitions = $this->tables->storedFields($entity_type_id);
    foreach ($this->tables->fieldTables($entity_type_id) as $name => $info) {
      if ($info['dedicated']) {
        $table = $info['table'];
        // Feeds items that point at a feed not migrated are left out.
        $transform = $definitions[$name]->getType() === 'feeds_item'
          ? fn(array $row): ?array => $this->selection->keepsFeedItemRow($row, $name) ? ($relabel ? $relabel($row) : $row) : NULL
          : $relabel;
        $results[] = $this->compare($table, ['entity_id', 'langcode', 'delta'], $chunks,
          fn($connection, $chunk) => $connection->select($table, 't')->fields('t')->condition('t.entity_id', $chunk, 'IN')->condition('t.deleted', 0),
          $transform);
      }
    }
    return $results;
  }

  /**
   * Compares the content moderation state of each migrated revision.
   */
  private function compareModeration(string $entity_type_id, array $chunks, array $migrated, array $languages): array {
    $states = [];
    $source_default = [];
    foreach (['source' => $this->source->connection(), 'target' => $this->database] as $side => $connection) {
      $states[$side] = [];
      if (!$connection->schema()->tableExists('content_moderation_state_field_revision')) {
        continue;
      }
      foreach ($chunks as $chunk) {
        $records = $connection->select('content_moderation_state_field_revision', 'm')
          ->fields('m', ['content_entity_revision_id', 'langcode', 'moderation_state', 'default_langcode'])
          ->condition('m.content_entity_type_id', $entity_type_id)
          ->condition('m.content_entity_id', $chunk, 'IN')
          ->orderBy('m.revision_id')
          ->execute();
        foreach ($records as $record) {
          $revision_id = $record->content_entity_revision_id;
          $langcode = $record->langcode;
          if ($side === 'source') {
            if (isset($migrated[$revision_id]) && $record->default_langcode) {
              $source_default[$revision_id] = md5($record->moderation_state);
            }
            if (!isset($migrated[$revision_id], $languages[$revision_id]['map'][$langcode])) {
              continue;
            }
            $langcode = $languages[$revision_id]['map'][$langcode];
          }
          // The latest state wins, as when Drupal loads it.
          $states[$side][$revision_id . '|' . $langcode] = md5($record->moderation_state);
        }
      }
    }
    // A revision whose original language here has no state on the source
    // (its original language there is not migrated) shows the state of the
    // source's original language, as Drupal does on the source.
    // @see \Drupal\content_moderation\Plugin\Field\ModerationStateFieldItemList::loadContentModerationStateRevision()
    foreach ($source_default as $revision_id => $state) {
      if (isset($languages[$revision_id]['default'])) {
        $states['source'][$revision_id . '|' . $languages[$revision_id]['default']] ??= $state;
      }
    }
    return $this->result("content moderation states ($entity_type_id)", $states['source'], $states['target']);
  }

  /**
   * Compares the rows of one table on the source and on this site.
   *
   * @param string $table
   *   The table name, for the report.
   * @param string[] $key
   *   The columns that identify a row.
   * @param array $chunks
   *   The ID chunks to query.
   * @param callable $query
   *   Builds the query for a connection and a chunk.
   * @param callable|null $transform
   *   Returns a source row (array) as it should be here, or NULL when it is
   *   not migrated (other languages, revisions). Every row on this site is
   *   compared, so rows that should not be here show as extra.
   * @param string[] $ignore
   *   Columns left out of the comparison, on both sides.
   */
  private function compare(string $table, array $key, array $chunks, callable $query, ?callable $transform = NULL, array $ignore = []): array {
    $rows = [];
    foreach (['source' => $this->source->connection(), 'target' => $this->database] as $side => $connection) {
      $rows[$side] = [];
      foreach ($chunks as $chunk) {
        foreach ($query($connection, $chunk)->execute() as $record) {
          $row = (array) $record;
          if ($transform && $side === 'source' && !($row = $transform($row))) {
            continue;
          }
          $id = implode('|', array_map(static fn($column) => $row[$column], $key));
          $row = array_diff_key($row, array_flip($ignore));
          ksort($row);
          $rows[$side][$id] = md5(serialize(array_map(static fn($value) => $value === NULL ? NULL : (string) $value, $row)));
        }
      }
    }
    return $this->result($table, $rows['source'], $rows['target']);
  }

  /**
   * Builds the result of a comparison.
   */
  private function result(string $table, array $source, array $target): array {
    $missing = array_diff_key($source, $target);
    $extra = array_diff_key($target, $source);
    $different = array_diff_assoc(array_intersect_key($source, $target), $target);
    return [
      'table' => $table,
      'source' => count($source),
      'target' => count($target),
      'missing' => count($missing),
      'extra' => count($extra),
      'different' => count($different),
      'samples' => array_slice(array_merge(
        array_map(static fn($key) => "missing $key", array_keys($missing)),
        array_map(static fn($key) => "extra $key", array_keys($extra)),
        array_map(static fn($key) => "different $key", array_keys($different)),
      ), 0, self::SAMPLES),
    ];
  }

}
