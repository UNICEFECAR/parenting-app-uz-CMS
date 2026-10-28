<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\Sql\DefaultTableMapping;
use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Language\LanguageManagerInterface;

/**
 * Describes where the migrated entity types keep their values.
 *
 * Bebbo and this site share the same entity and field schema (the config was
 * imported from Bebbo), so this site's table mapping also describes the
 * source tables. SchemaChecker confirms it before anything is migrated.
 */
final class SourceTables {

  /**
   * Entity types migrated with every selected revision and translation.
   */
  public const REVISIONED = ['node', 'media', 'taxonomy_term', 'block_content', 'menu_link_content'];

  /**
   * Entity types migrated with their current values only.
   */
  public const CURRENT = [
    'user',
    'file',
    'path_alias',
    'feeds_feed',
    'group_relationship',
    'shortcut',
    'tmgmt_job',
    'tmgmt_job_item',
    'tmgmt_message',
    'tmgmt_remote',
  ];

  /**
   * Types whose items in another language only are moved to the default one.
   *
   * A media item is a file and a name: its language carries no meaning, and
   * the article that shows it needs it. Nodes and terms in another language
   * only are not migrated, see revisionLanguages().
   */
  public const RELABELLED = ['media'];

  /**
   * Languages every site has: not specified, not applicable.
   */
  private const LOCKED_LANGCODES = ['und', 'zxx'];

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly LanguageManagerInterface $languageManager,
  ) {}

  /**
   * Returns the languages kept, in the order they become the original one.
   *
   * @param string[] $langcodes
   *   The selected languages.
   *
   * @return string[]
   *   The selected languages in their order, then this site's default
   *   language, then und and zxx. Not the default language first: it can
   *   change after the content is migrated, and the languages migrated
   *   content got must stay the same.
   */
  public function keptLangcodes(array $langcodes): array {
    $default = $this->languageManager->getDefaultLanguage()->getId();
    return array_values(array_unique(array_merge($langcodes, [$default], self::LOCKED_LANGCODES)));
  }

  /**
   * Returns the language an entity migrated with its current values gets.
   *
   * Its own language when kept, else the first kept language: Drupal
   * stores field values per language, and cannot load them for a language
   * the site does not have.
   */
  public function currentLangcode(?string $langcode, array $langcodes): ?string {
    if ($langcode === NULL || in_array($langcode, $this->keptLangcodes($langcodes), TRUE)) {
      return $langcode;
    }
    return $this->keptLangcodes($langcodes)[0];
  }

  /**
   * Decides the languages of each revision on this site.
   *
   * Translations in a kept language keep their language. The original
   * language of a revision is kept when this site has it. Otherwise:
   * - the first kept translation (in the order of keptLangcodes()) becomes
   *   the original, and the other-language translation is not migrated. A
   *   later revision keeps the language chosen for an earlier one, as Drupal
   *   keeps the original language of an entity, so pass all the migrated
   *   revisions of an entity together;
   * - a revision with no kept translation is migrated in this site's default
   *   language for the RELABELLED types, and not at all for the others.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The source database.
   * @param string $entity_type_id
   *   A revisioned entity type ID.
   * @param int[] $revision_ids
   *   Revision IDs.
   * @param string[] $langcodes
   *   The selected languages.
   *
   * @return array
   *   Keyed by revision ID: 'map' (source langcode => langcode here, for the
   *   migrated translations) and 'default' (the original language here, NULL
   *   when the revision is not migrated).
   */
  public function revisionLanguages(Connection $database, string $entity_type_id, array $revision_ids, array $langcodes): array {
    $entity_type = $this->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $default_langcode = $entity_type->getKey('default_langcode');
    $kept = $this->keptLangcodes($langcodes);

    $found = [];
    $entity_ids = [];
    foreach (array_chunk($revision_ids, 1000) as $chunk) {
      $query = $database->select($entity_type->getRevisionDataTable(), 'fr')
        ->fields('fr', [$id, $revision, $langcode, $default_langcode])
        ->condition("fr.$revision", $chunk, 'IN');
      foreach ($query->execute() as $record) {
        $found[$record->$revision][$record->$langcode] = (bool) $record->$default_langcode;
        $entity_ids[$record->$revision] = $record->$id;
      }
    }
    ksort($found);

    $result = [];
    // The original language chosen for an entity's earlier revision: Drupal
    // keeps the original language of an entity once it is saved.
    $chosen = [];
    foreach ($found as $revision_id => $translations) {
      $entity_id = $entity_ids[$revision_id];
      $original = array_search(TRUE, $translations, TRUE);
      $present = array_values(array_intersect($kept, array_keys($translations)));
      $map = array_combine($present, $present);
      if ($original !== FALSE && in_array($original, $kept, TRUE)) {
        $default = $original;
      }
      elseif (isset($chosen[$entity_id]) && in_array($chosen[$entity_id], $present, TRUE)) {
        $default = $chosen[$entity_id];
      }
      elseif ($present) {
        $default = $present[0];
      }
      elseif ($original !== FALSE && in_array($entity_type_id, self::RELABELLED, TRUE)) {
        $default = $kept[0];
        $map = [$original => $default];
      }
      else {
        $default = NULL;
      }
      if ($default !== NULL) {
        $chosen[$entity_id] = $default;
      }
      $result[$revision_id] = ['map' => $map, 'default' => $default];
    }
    return $result;
  }

  /**
   * Whether every selected revision of the entity type is migrated.
   */
  public function isRevisioned(string $entity_type_id): bool {
    return in_array($entity_type_id, self::REVISIONED, TRUE);
  }

  /**
   * Whether this site has the entity type.
   */
  public function hasEntityType(string $entity_type_id): bool {
    return $this->entityTypeManager->hasDefinition($entity_type_id);
  }

  /**
   * Returns the entity type definition.
   */
  public function entityType(string $entity_type_id): EntityTypeInterface {
    return $this->entityTypeManager->getDefinition($entity_type_id);
  }

  /**
   * Returns the table mapping of the entity type.
   */
  public function mapping(string $entity_type_id): DefaultTableMapping {
    $mapping = $this->entityTypeManager->getStorage($entity_type_id)->getTableMapping();
    assert($mapping instanceof DefaultTableMapping);
    return $mapping;
  }

  /**
   * Whether a field of a bundle is translatable.
   *
   * Drupal stores an untranslatable field once, in the original language,
   * and every translation shares it. The bundle's field decides: the field
   * storage is translatable by default.
   */
  public function isTranslatable(string $entity_type_id, string $bundle, string $field_name): bool {
    $definitions = $this->entityFieldManager->getFieldDefinitions($entity_type_id, $bundle);
    return isset($definitions[$field_name]) && $definitions[$field_name]->isTranslatable();
  }

  /**
   * Returns the field storage definitions that have stored values.
   *
   * @return \Drupal\Core\Field\FieldStorageDefinitionInterface[]
   *   Definitions keyed by field name.
   */
  public function storedFields(string $entity_type_id): array {
    $definitions = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
    // Computed fields (path, moderation_state) have custom storage too.
    return array_filter($definitions, static fn($definition) => !$definition->hasCustomStorage());
  }

  /**
   * Returns the table and columns each stored field is read from.
   *
   * Revisioned types are read from the revision tables, the others from the
   * base and data tables. A field that is not revisionable (term weight) is
   * kept only in the data table: revisioned types read its current value
   * there, see joinDataTable().
   *
   * @return array
   *   Keyed by field name: 'table', 'columns' (property => column) and
   *   'dedicated' (TRUE for a table of its own, keyed by entity_id, or
   *   revision_id for a revision table, langcode and delta), 'serialized'
   *   (the properties stored serialized, such as a map field). Dedicated
   *   tables also have 'revision' (TRUE for a revision table).
   */
  public function fieldTables(string $entity_type_id): array {
    $entity_type = $this->entityType($entity_type_id);
    $mapping = $this->mapping($entity_type_id);
    $revisioned = $this->isRevisioned($entity_type_id);
    $shared = $revisioned
      ? [
        $entity_type->getRevisionDataTable(),
        $entity_type->getRevisionTable(),
        $entity_type->getBaseTable(),
        $entity_type->getDataTable(),
      ]
      : [$entity_type->getDataTable(), $entity_type->getBaseTable()];

    $tables = [];
    foreach ($this->storedFields($entity_type_id) as $name => $definition) {
      if ($mapping->requiresDedicatedTableStorage($definition)) {
        $table = $revisioned && $definition->isRevisionable()
          ? $mapping->getDedicatedRevisionTableName($definition)
          : $mapping->getDedicatedDataTableName($definition);
        $tables[$name] = [
          'table' => $table,
          'columns' => $mapping->getColumnNames($name),
          'serialized' => $this->serializedProperties($definition),
          'dedicated' => TRUE,
          'revision' => $revisioned && $definition->isRevisionable(),
        ];
        continue;
      }
      $table = current(array_intersect(array_filter($shared), $mapping->getAllFieldTableNames($name)));
      if ($table) {
        $tables[$name] = [
          'table' => $table,
          'columns' => $mapping->getColumnNames($name),
          'serialized' => $this->serializedProperties($definition),
          'dedicated' => FALSE,
        ];
      }
    }
    return $tables;
  }

  /**
   * Joins the data table (alias 'd') to a query built by rowsQuery().
   *
   * The data table holds the current translation, matched by entity and
   * language. It is a left join: a revision can have a translation that was
   * removed since.
   */
  public function joinDataTable(SelectInterface $query, string $entity_type_id): void {
    $entity_type = $this->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $langcode = $entity_type->getKey('langcode');
    $query->leftJoin($entity_type->getDataTable(), 'd', "[d].[$id] = [fr].[$id] AND [d].[$langcode] = [fr].[$langcode]");
  }

  /**
   * Returns the properties of a field that are stored serialized.
   *
   * @return string[]
   *   Property names.
   */
  private function serializedProperties(FieldStorageDefinitionInterface $definition): array {
    return array_keys(array_filter($definition->getColumns(), static fn($column) => !empty($column['serialize'])));
  }

  /**
   * Builds the query of the source rows to migrate for a revisioned type.
   *
   * One row per revision and language, ordered so that each revision is
   * created before its translations and revisions are created in order:
   * - translations in a kept language (see revisionLanguages());
   * - revisions in which one of those translations changed, plus the default
   *   and the latest revision. Revisions that only changed other languages
   *   are left out.
   *
   * The entity type must have a 'changed' field (EntityChangedInterface).
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The source database.
   * @param string $entity_type_id
   *   A revisioned entity type ID.
   * @param int[] $ids
   *   The entity IDs.
   * @param string[] $langcodes
   *   The languages to migrate.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query, with the table alias 'fr' for the revision data table and
   *   'b' for the base table. It selects the entity ID, revision ID and
   *   langcode columns.
   */
  public function rowsQuery(Connection $database, string $entity_type_id, array $ids, array $langcodes): SelectInterface {
    $entity_type = $this->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $default_langcode = $entity_type->getKey('default_langcode');
    $revision_data_table = $entity_type->getRevisionDataTable();
    $revision_table = $entity_type->getRevisionTable();

    $query = $database->select($revision_data_table, 'fr');
    $query->innerJoin($entity_type->getBaseTable(), 'b', "[b].[$id] = [fr].[$id]");
    $query->fields('fr', [$id, $revision, $langcode]);
    if (!$ids) {
      $query->where('1 = 0');
      return $query;
    }
    $query->condition("fr.$id", $ids, 'IN');
    $this->migratedTranslations($query, 'fr', $entity_type_id, $langcodes);

    // The revisions in which a migrated translation changed. Drupal records
    // that in revision_translation_affected; content saved before Drupal
    // tracked it has NULL there, and counts as changed when the
    // translation's changed time differs from its previous revision.
    $translations = $database->select($revision_data_table, 'x');
    $translations->fields('x', [$id, $revision, $langcode, 'revision_translation_affected', 'changed']);
    $translations->addExpression("LAG([x].[changed]) OVER (PARTITION BY [x].[$id], [x].[$langcode] ORDER BY [x].[$revision])", 'previous_changed');
    $translations->condition("x.$id", $ids, 'IN');
    $this->migratedTranslations($translations, 'x', $entity_type_id, $langcodes);
    $affected = $database->select($translations, 'y');
    $affected->fields('y', [$revision]);
    $affected->condition($affected->orConditionGroup()
      ->condition('y.revision_translation_affected', 1)
      ->condition($affected->andConditionGroup()
        ->isNull('y.revision_translation_affected')
        ->condition($affected->orConditionGroup()
          ->isNull('y.previous_changed')
          ->where('[y].[previous_changed] <> [y].[changed]'))));

    $query->condition($query->orConditionGroup()
      ->where("[fr].[$revision] = [b].[$revision]")
      ->where("[fr].[$revision] = (SELECT MAX([l].[$revision]) FROM {" . $revision_table . "} [l] WHERE [l].[$id] = [fr].[$id])")
      ->condition("fr.$revision", $affected, 'IN'));

    // The original language here comes first in each revision.
    $kept = $this->keptLangcodes($langcodes);
    $order = "CASE WHEN [fr].[$default_langcode] = 1 AND [fr].[$langcode] IN (:kept[]) THEN 0";
    $arguments = [':kept[]' => $kept];
    foreach ($kept as $index => $kept_langcode) {
      $order .= " WHEN [fr].[$langcode] = :kept_$index THEN " . ($index + 1);
      $arguments[":kept_$index"] = $kept_langcode;
    }
    $order .= ' ELSE 0 END';
    $query->addExpression($order, 'language_order', $arguments);
    $query->orderBy("fr.$id");
    $query->orderBy("fr.$revision");
    $query->orderBy('language_order');
    return $query;
  }

  /**
   * Limits a revision data table query to the migrated translations.
   *
   * The translations in a kept language, plus, for the RELABELLED types, the
   * original translation of a revision that has no kept translation.
   *
   * @see self::revisionLanguages()
   */
  private function migratedTranslations(SelectInterface $query, string $alias, string $entity_type_id, array $langcodes): void {
    $entity_type = $this->entityType($entity_type_id);
    $revision = $entity_type->getKey('revision');
    $langcode = $entity_type->getKey('langcode');
    $kept = $this->keptLangcodes($langcodes);
    $condition = $query->orConditionGroup()->condition("$alias.$langcode", $kept, 'IN');
    if (in_array($entity_type_id, self::RELABELLED, TRUE)) {
      $others = $query->getConnection()->select($entity_type->getRevisionDataTable(), 'k');
      $others->addExpression('1');
      $others->where("[k].[$revision] = [$alias].[$revision]");
      $others->condition("k.$langcode", $kept, 'IN');
      $condition->condition($query->andConditionGroup()
        ->condition("$alias." . $entity_type->getKey('default_langcode'), 1)
        ->notExists($others));
    }
    $query->condition($condition);
  }

  /**
   * Builds the query of the source rows of a type migrated without revisions.
   *
   * @param \Drupal\Core\Database\Connection $database
   *   The source database.
   * @param string $entity_type_id
   *   An entity type ID from self::CURRENT.
   * @param int[] $ids
   *   The entity IDs.
   *
   * @return \Drupal\Core\Database\Query\SelectInterface
   *   The query, with the alias 'b' for the base table and 'd' for the data
   *   table (default translation), if the type has one.
   */
  public function currentQuery(Connection $database, string $entity_type_id, array $ids): SelectInterface {
    $entity_type = $this->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $query = $database->select($entity_type->getBaseTable(), 'b');
    $query->fields('b', [$id]);
    if ($data_table = $entity_type->getDataTable()) {
      $query->innerJoin($data_table, 'd', "[d].[$id] = [b].[$id]");
      $query->condition('d.' . $entity_type->getKey('default_langcode'), 1);
    }
    if (!$ids) {
      $query->where('1 = 0');
      return $query;
    }
    $query->condition("b.$id", $ids, 'IN');
    $query->orderBy("b.$id");
    return $query;
  }

}
