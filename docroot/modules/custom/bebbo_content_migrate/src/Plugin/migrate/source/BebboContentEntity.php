<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Plugin\migrate\source;

use Drupal\bebbo_content_migrate\Selection;
use Drupal\bebbo_content_migrate\SourceTables;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate\Plugin\migrate\source\SqlBase;
use Drupal\migrate\Row;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the selected entities of one type from the Bebbo database.
 *
 * For node, media and taxonomy_term there is one row per revision and
 * language (see SourceTables::rowsQuery()); for user, file and path_alias one
 * row per entity, with its current values.
 *
 * Every stored field is a source property named after the field: a scalar
 * for single-column base fields (nid, title, status), a list of items keyed
 * by property for the others (body, field_cover_image, thumbnail). Extra
 * properties of revisioned types:
 * - target_langcode, target_default_langcode: the language of the row on
 *   this site, and whether it is the original language there. They differ
 *   from langcode and default_langcode when the original language is not
 *   kept (see SourceTables::revisionLanguages()). Types migrated with their
 *   current values get target_langcode too (SourceTables::currentLangcode());
 * - moderation_state: the content moderation state of the revision in this
 *   language, if it has one;
 * - revision_langcodes: the languages the revision has on this site.
 *
 * Available configuration keys:
 * - entity_type: The entity type ID.
 *
 * Example:
 *
 * @code
 * source:
 *   plugin: bebbo_content_entity
 *   entity_type: node
 *   database_state_key: bebbo_content_migrate.database
 * @endcode
 *
 * @see \Drupal\bebbo_content_migrate\SourceTables
 */
#[MigrateSource(id: 'bebbo_content_entity')]
class BebboContentEntity extends SqlBase {

  /**
   * Values loaded for the current row's revision (or entity).
   *
   * The rows come ordered by entity and revision, so the values of one
   * revision are loaded for all its languages at once and kept until the
   * next revision.
   */
  protected array $rowValues = [];

  /**
   * The entity and revision the cache holds values of.
   */
  protected ?string $rowValuesKey = NULL;

  /**
   * The languages of the revisions of the current row's entity.
   *
   * @see self::revisionLanguages()
   */
  protected array $entityLanguages = [];

  /**
   * Whether the source has content moderation states.
   */
  protected ?bool $hasModeration = NULL;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    StateInterface $state,
    protected readonly SourceTables $tables,
    protected readonly Selection $selection,
  ) {
    if (empty($configuration['entity_type'])) {
      throw new \InvalidArgumentException('The bebbo_content_entity source needs an entity_type.');
    }
    parent::__construct($configuration, $plugin_id, $plugin_definition, $migration, $state);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition, ?MigrationInterface $migration = NULL) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $migration,
      $container->get('state'),
      $container->get(SourceTables::class),
      $container->get(Selection::class),
    );
  }

  /**
   * Returns the entity type ID this source reads.
   */
  protected function entityTypeId(): string {
    return $this->configuration['entity_type'];
  }

  /**
   * {@inheritdoc}
   */
  public function query() {
    $entity_type_id = $this->entityTypeId();
    $entity_type = $this->tables->entityType($entity_type_id);
    $ids = $this->selection->ids($entity_type_id);

    if ($this->tables->isRevisioned($entity_type_id)) {
      $query = $this->tables->rowsQuery($this->getDatabase(), $entity_type_id, $ids, $this->selection->langcodes());
      $revision = $entity_type->getKey('revision');
      $query->innerJoin($entity_type->getRevisionTable(), 'r', "[r].[$revision] = [fr].[$revision]");
      $this->tables->joinDataTable($query, $entity_type_id);
      $aliases = [
        $entity_type->getRevisionDataTable() => 'fr',
        $entity_type->getRevisionTable() => 'r',
        $entity_type->getBaseTable() => 'b',
        $entity_type->getDataTable() => 'd',
      ];
    }
    else {
      $query = $this->tables->currentQuery($this->getDatabase(), $entity_type_id, $ids);
      $aliases = [$entity_type->getBaseTable() => 'b'];
      if ($data_table = $entity_type->getDataTable()) {
        $aliases[$data_table] = 'd';
      }
    }

    $selected = [];
    foreach ($query->getFields() as $field) {
      $selected[$field['field']] = TRUE;
    }
    foreach ($this->tables->fieldTables($entity_type_id) as $info) {
      if ($info['dedicated']) {
        continue;
      }
      foreach ($info['columns'] as $column) {
        if (!isset($selected[$column])) {
          $query->addField($aliases[$info['table']], $column);
          $selected[$column] = TRUE;
        }
      }
    }
    return $query;
  }

  /**
   * {@inheritdoc}
   */
  public function fields() {
    $fields = [];
    foreach ($this->tables->storedFields($this->entityTypeId()) as $name => $definition) {
      $fields[$name] = (string) $definition->getLabel();
    }
    if ($this->tables->isRevisioned($this->entityTypeId())) {
      $fields['moderation_state'] = $this->t('The content moderation state of the revision translation');
      $fields['revision_langcodes'] = $this->t('The languages of the revision on this site');
      $fields['target_langcode'] = $this->t('The language of the row on this site');
      $fields['target_default_langcode'] = $this->t('Whether the row is the original language on this site');
    }
    return $fields;
  }

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $entity_type = $this->tables->entityType($this->entityTypeId());
    if ($this->tables->isRevisioned($this->entityTypeId())) {
      return [
        $entity_type->getKey('id') => ['type' => 'integer', 'alias' => 'fr'],
        $entity_type->getKey('revision') => ['type' => 'integer', 'alias' => 'fr'],
        $entity_type->getKey('langcode') => ['type' => 'string', 'alias' => 'fr'],
      ];
    }
    return [
      $entity_type->getKey('id') => ['type' => 'integer', 'alias' => 'b'],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function prepareRow(Row $row) {
    $entity_type_id = $this->entityTypeId();
    $entity_type = $this->tables->entityType($entity_type_id);
    $revisioned = $this->tables->isRevisioned($entity_type_id);
    $id = (int) $row->getSourceProperty($entity_type->getKey('id'));
    $revision_id = $revisioned ? (int) $row->getSourceProperty($entity_type->getKey('revision')) : NULL;
    $langcode = $row->getSourceProperty($entity_type->getKey('langcode'));
    if ($this->rowValuesKey !== "$id:$revision_id") {
      $this->rowValues = [];
      $this->rowValuesKey = "$id:$revision_id";
    }
    $bundle_key = $entity_type->getKey('bundle');
    $bundle = $bundle_key ? $row->getSourceProperty($bundle_key) : $entity_type_id;
    $definitions = $this->tables->storedFields($entity_type_id);

    foreach ($this->tables->fieldTables($entity_type_id) as $name => $info) {
      if ($info['dedicated']) {
        $values = $this->dedicatedValues(($info['revision'] ?? FALSE) ? $revision_id : NULL, $id, $name, $info);
        // Drupal stores an untranslatable field once, in the original
        // language, and every translation shares it.
        $translated = !$revisioned || $this->tables->isTranslatable($entity_type_id, $bundle, $name);
        $items = $translated ? ($values[$langcode] ?? []) : (reset($values) ?: []);
        if ($definitions[$name]->getType() === 'feeds_item') {
          $items = $this->selection->feedItems($items);
        }
        $row->setSourceProperty($name, $items);
        continue;
      }
      // Single-column base fields stay scalars (nid, title, status).
      if (count($info['columns']) === 1 && current($info['columns']) === $name) {
        if ($info['serialized']) {
          $row->setSourceProperty($name, $this->unserialize($row->getSourceProperty($name)));
        }
        continue;
      }
      $item = [];
      foreach ($info['columns'] as $property => $column) {
        $value = $row->getSourceProperty($column);
        $item[$property] = in_array($property, $info['serialized'], TRUE) ? $this->unserialize($value) : $value;
      }
      $row->setSourceProperty($name, array_filter($item, static fn($value) => $value !== NULL) ? [$item] : []);
    }

    if (!$revisioned && $entity_type->getKey('langcode')) {
      $row->setSourceProperty('target_langcode', $this->tables->currentLangcode($langcode, $this->selection->langcodes()));
    }
    if ($revisioned) {
      $languages = $this->revisionLanguages($revision_id);
      $target_langcode = $languages['map'][$langcode];
      $row->setSourceProperty('target_langcode', $target_langcode);
      $row->setSourceProperty('target_default_langcode', (int) ($target_langcode === $languages['default']));
      $row->setSourceProperty('revision_langcodes', array_values($languages['map']));
      $row->setSourceProperty('moderation_state', $this->moderationState($id, $revision_id, $langcode));
    }
    return parent::prepareRow($row);
  }

  /**
   * Loads the values of a field stored in a table of its own.
   *
   * @param int|null $revision_id
   *   The revision ID for a revision table, NULL for a data table.
   * @param int $id
   *   The entity ID.
   * @param string $name
   *   The field name.
   * @param array $info
   *   The field table info from SourceTables::fieldTables().
   *
   * @return array
   *   Field items keyed by langcode.
   */
  protected function dedicatedValues(?int $revision_id, int $id, string $name, array $info): array {
    if (!isset($this->rowValues[$name])) {
      $query = $this->getDatabase()->select($info['table'], 't')
        ->fields('t', array_merge(['langcode'], array_values($info['columns'])))
        ->condition('t.deleted', 0)
        ->orderBy('t.langcode')
        ->orderBy('t.delta');
      $query->condition($revision_id !== NULL ? 't.revision_id' : 't.entity_id', $revision_id ?? $id);
      $values = [];
      foreach ($query->execute() as $record) {
        $item = [];
        foreach ($info['columns'] as $property => $column) {
          $item[$property] = in_array($property, $info['serialized'], TRUE) ? $this->unserialize($record->$column) : $record->$column;
        }
        $values[$record->langcode][] = $item;
      }
      $this->rowValues[$name] = $values;
    }
    return $this->rowValues[$name];
  }

  /**
   * Returns the languages of a revision on this site.
   *
   * @return array
   *   'map' (source langcode => langcode here) and 'default' (the original
   *   language here).
   *
   * @see \Drupal\bebbo_content_migrate\SourceTables::revisionLanguages()
   */
  protected function revisionLanguages(int $revision_id): array {
    $entity_type = $this->tables->entityType($this->entityTypeId());
    $id = (int) strtok($this->rowValuesKey, ':');
    // Decided for all migrated revisions of the entity at once, so that each
    // revision gets the same original language.
    if (($this->entityLanguages['id'] ?? NULL) !== $id) {
      $rows = $this->getDatabase()->select($this->tables->rowsQuery($this->getDatabase(), $this->entityTypeId(), [$id], $this->selection->langcodes()), 's');
      $rows->addField('s', $entity_type->getKey('revision'));
      $revision_ids = $rows->distinct()->execute()->fetchCol();
      $this->entityLanguages = [
        'id' => $id,
        'revisions' => $this->tables->revisionLanguages($this->getDatabase(), $this->entityTypeId(), $revision_ids, $this->selection->langcodes()),
      ];
    }
    return $this->entityLanguages['revisions'][$revision_id];
  }

  /**
   * Unserializes a stored value, such as the data of a map field.
   *
   * Layout Builder stores its sections as objects; no other class is allowed.
   */
  protected function unserialize(?string $value): mixed {
    return $value === NULL ? NULL : unserialize($value, [
      'allowed_classes' => [
        'Drupal\layout_builder\Section',
        'Drupal\layout_builder\SectionComponent',
      ],
    ]);
  }

  /**
   * Returns the content moderation state of a revision translation.
   */
  protected function moderationState(int $id, int $revision_id, string $langcode): ?string {
    $database = $this->getDatabase();
    $this->hasModeration ??= $database->schema()->tableExists('content_moderation_state_field_revision');
    if (!$this->hasModeration) {
      return NULL;
    }
    $state = $database->select('content_moderation_state_field_revision', 'm')
      ->fields('m', ['moderation_state'])
      ->condition('m.content_entity_type_id', $this->entityTypeId())
      ->condition('m.content_entity_id', $id)
      ->condition('m.content_entity_revision_id', $revision_id)
      ->condition('m.langcode', $langcode)
      ->orderBy('m.revision_id', 'DESC')
      ->range(0, 1)
      ->execute()
      ->fetchField();
    return $state === FALSE ? NULL : $state;
  }

}
