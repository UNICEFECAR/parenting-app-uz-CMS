<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\migrate\Plugin\MigrationPluginManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Checks that the Bebbo tables match this site's entity schema.
 *
 * The migration reads the source with this site's table mapping, so every
 * table and column this site uses for the migrated entity types must exist
 * on the source. A missing one means the two sites' config differs (a field
 * added on one side only), which would lose values silently otherwise.
 */
final class SchemaChecker {

  public function __construct(
    private readonly Connection $database,
    private readonly SourceTables $tables,
    private readonly SourceDatabase $source,
    #[Autowire(service: 'plugin.manager.migration')]
    private readonly MigrationPluginManagerInterface $migrationManager,
  ) {}

  /**
   * Returns the stored fields that a migration does not map.
   *
   * Each migration lists every stored field of its entity type (all
   * bundles), or every column of its table, so a field added to this site
   * later must be added to the migration too, or its values would not be
   * migrated.
   *
   * @return string[]
   *   Messages naming the migration and the fields.
   */
  public function unmappedFields(): array {
    $problems = [];
    foreach (SelectionBuilder::MIGRATIONS as $entity_type_id => $migration_id) {
      $process = $this->migrationManager->createInstance($migration_id)->getProcess();
      $missing = array_diff(array_keys($this->tables->fieldTables($entity_type_id)), array_keys($process));
      if ($missing) {
        $problems[] = "Migration $migration_id does not map " . implode(', ', $missing) . '.';
      }
    }
    foreach (SelectionBuilder::TABLES as $table => $info) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      $process = $this->migrationManager->createInstance($info['migration'])->getProcess();
      $missing = array_diff($this->columns($this->database, $table), array_keys($process));
      if ($missing) {
        $problems[] = "Migration {$info['migration']} does not map " . implode(', ', $missing) . '.';
      }
    }
    return $problems;
  }

  /**
   * Returns the differences, one message each; empty when the schemas match.
   *
   * @return string[]
   *   Messages naming the missing tables and columns.
   */
  public function check(): array {
    $source = $this->source->connection();
    $problems = [];
    foreach ($this->tableNames() as $table) {
      if (!$this->database->schema()->tableExists($table)) {
        continue;
      }
      if (!$source->schema()->tableExists($table)) {
        $problems[] = "Table $table is missing on the source.";
        continue;
      }
      $missing = array_filter($this->columns($this->database, $table), static fn($column) => !$source->schema()->fieldExists($table, $column));
      if ($missing) {
        $problems[] = "Table $table on the source has no column " . implode(', ', $missing) . '.';
      }
    }
    return $problems;
  }

  /**
   * Returns the tables of the migrated entity types and TABLES on this site.
   *
   * @return string[]
   *   Table names.
   */
  public function tableNames(): array {
    $names = [];
    foreach (array_merge(SourceTables::REVISIONED, SourceTables::CURRENT) as $entity_type_id) {
      $entity_type = $this->tables->entityType($entity_type_id);
      $names[] = $entity_type->getBaseTable();
      $names[] = $entity_type->getDataTable();
      $names[] = $entity_type->getRevisionTable();
      $names[] = $entity_type->getRevisionDataTable();
      foreach ($this->tables->fieldTables($entity_type_id) as $info) {
        $names[] = $info['table'];
      }
    }
    $names[] = 'content_moderation_state_field_revision';
    array_push($names, ...array_keys(SelectionBuilder::TABLES));
    return array_values(array_unique(array_filter($names)));
  }

  /**
   * Returns the column names of a table.
   *
   * @return string[]
   *   Column names.
   */
  private function columns(Connection $database, string $table): array {
    $statement = $database->query('SELECT * FROM {' . $table . '} LIMIT 0');
    $columns = [];
    for ($i = 0; $i < $statement->getClientStatement()->columnCount(); $i++) {
      $columns[] = $statement->getClientStatement()->getColumnMeta($i)['name'];
    }
    return $columns;
  }

}
