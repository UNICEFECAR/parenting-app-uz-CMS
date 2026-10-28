<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Plugin\migrate\source;

use Drupal\bebbo_content_migrate\Selection;
use Drupal\Core\Database\Query\SelectInterface;
use Drupal\Core\State\StateInterface;
use Drupal\migrate\Attribute\MigrateSource;
use Drupal\migrate\Plugin\MigrationInterface;
use Drupal\migrate_plus\Plugin\migrate\source\Table;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Reads the selected rows of a table that is not an entity type from Bebbo.
 *
 * The rows are chosen by drush bebbo-content-migrate:select and kept in the
 * selection under the table name, by the single ID column.
 *
 * Example:
 *
 * @code
 * source:
 *   plugin: bebbo_table
 *   table_name: forcefull_check_update_api
 *   id_fields:
 *     id:
 *       type: integer
 *   database_state_key: bebbo_content_migrate.database
 * @endcode
 *
 * @see \Drupal\bebbo_content_migrate\SelectionBuilder::TABLES
 */
#[MigrateSource(id: 'bebbo_table')]
class BebboTable extends Table {

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    MigrationInterface $migration,
    StateInterface $state,
    protected readonly Selection $selection,
  ) {
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
      $container->get(Selection::class),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function query(): SelectInterface {
    $query = parent::query();
    $id = static::TABLE_ALIAS . '.' . array_key_first($this->idFields);
    $ids = $this->selection->ids($this->tableName);
    if (!$ids) {
      $query->where('1 = 0');
      return $query;
    }
    $query->condition($id, $ids, 'IN');
    $query->orderBy($id);
    return $query;
  }

}
