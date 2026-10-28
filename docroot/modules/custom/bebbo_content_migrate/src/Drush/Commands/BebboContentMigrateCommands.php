<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Drush\Commands;

use Drupal\bebbo_content_migrate\SchemaChecker;
use Drupal\bebbo_content_migrate\Selection;
use Drupal\bebbo_content_migrate\SelectionBuilder;
use Drupal\bebbo_content_migrate\SourceDatabase;
use Drupal\bebbo_content_migrate\Verifier;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drush\Attributes as CLI;
use Drush\Commands\AutowireTrait;
use Drush\Commands\DrushCommands;

/**
 * Drush commands to prepare, check and verify the Bebbo content migration.
 *
 * The migrations themselves run with migrate_tools (drush migrate:import),
 * see README.md.
 */
final class BebboContentMigrateCommands extends DrushCommands {

  use AutowireTrait;

  public function __construct(
    private readonly SourceDatabase $source,
    private readonly SchemaChecker $schemaChecker,
    private readonly SelectionBuilder $selectionBuilder,
    private readonly Selection $selection,
    private readonly Verifier $verifier,
    private readonly LanguageManagerInterface $languageManager,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly Connection $database,
  ) {
    parent::__construct();
  }

  /**
   * Sets the connection to the copy of the Bebbo database and checks it.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:connect', aliases: ['bcm-connect'])]
  #[CLI\Option(name: 'host', description: 'Database host. For the Bebbo DDEV project: ddev-<project>-db.')]
  #[CLI\Option(name: 'port', description: 'Database port.')]
  #[CLI\Option(name: 'database', description: 'Database name.')]
  #[CLI\Option(name: 'username', description: 'Database user.')]
  #[CLI\Option(name: 'password', description: 'Database password.')]
  #[CLI\Option(name: 'prefix', description: 'Table prefix.')]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:connect --host=ddev-bebbo.app-db', description: 'Read the database of the Bebbo DDEV project.')]
  public function connect(
    array $options = [
      'host' => '',
      'port' => '3306',
      'database' => 'db',
      'username' => 'db',
      'password' => 'db',
      'prefix' => '',
    ],
  ): int {
    if (empty($options['host'])) {
      $this->logger()->error(dt('--host is required.'));
      return self::EXIT_FAILURE;
    }
    $this->source->setInfo([
      'driver' => 'mysql',
      'namespace' => 'Drupal\\mysql\\Driver\\Database\\mysql',
      'autoload' => 'core/modules/mysql/src/Driver/Database/mysql/',
      'host' => $options['host'],
      'port' => $options['port'],
      'database' => $options['database'],
      'username' => $options['username'],
      'password' => $options['password'],
      'prefix' => $options['prefix'],
    ]);
    try {
      $nodes = $this->source->connection()->query('SELECT COUNT(*) FROM {node}')->fetchField();
    }
    catch (\Exception $e) {
      $this->logger()->error(dt('Cannot read the Bebbo database: @message', ['@message' => $e->getMessage()]));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('Connected to @database on @host (@nodes nodes).', [
      '@database' => $options['database'],
      '@host' => $options['host'],
      '@nodes' => $nodes,
    ]));

    $problems = array_merge($this->schemaChecker->check(), $this->schemaChecker->unmappedFields());
    if ($problems) {
      foreach ($problems as $problem) {
        $this->logger()->error($problem);
      }
      $this->logger()->error(dt('The Bebbo schema or the migrations differ from this site. Import the Bebbo config first (bebbo-country-config), and map every field in the migrations.'));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('The Bebbo tables match this site and the migrations map every field (@count tables checked).', [
      '@count' => count($this->schemaChecker->tableNames()),
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Chooses the Bebbo entities to migrate.
   *
   * Starts from the nodes of the given bundles that have a translation in one
   * of the seed languages, and every term of this site's vocabularies, then
   * follows their references (terms, media, files, users, other nodes).
   */
  #[CLI\Command(name: 'bebbo-content-migrate:select', aliases: ['bcm-select'])]
  #[CLI\Option(name: 'bundles', description: 'Node bundles to start from, comma separated.')]
  #[CLI\Option(name: 'seed-langcodes', description: 'Start from the nodes translated into one of these languages. Leave empty for the languages of this site except the default one.')]
  #[CLI\Option(name: 'langcodes', description: 'Languages whose translations are migrated. Leave empty for the languages of this site.')]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:select', description: 'Articles translated into this site\'s languages, and what they reference.')]
  public function select(
    array $options = [
      'bundles' => 'article',
      'seed-langcodes' => '',
      'langcodes' => '',
    ],
  ): int {
    $default = $this->languageManager->getDefaultLanguage()->getId();
    $site = array_keys($this->languageManager->getLanguages());
    $bundles = $this->split($options['bundles']);
    $seed = $options['seed-langcodes'] ? $this->split($options['seed-langcodes']) : array_values(array_diff($site, [$default]));
    $langcodes = $options['langcodes'] ? $this->split($options['langcodes']) : $site;

    $this->logger()->notice(dt('Starting from @bundles nodes translated into @seed; migrating the translations in @langcodes.', [
      '@bundles' => implode(', ', $bundles),
      '@seed' => implode(', ', $seed),
      '@langcodes' => implode(', ', $langcodes),
    ]));
    $result = $this->selectionBuilder->build($bundles, $seed, $langcodes);
    $this->selection->save($result['ids'], [
      'bundles' => $bundles,
      'seed_langcodes' => $seed,
      'langcodes' => $langcodes,
      'created' => time(),
    ]);

    $rows = [];
    foreach ($result['ids'] as $entity_type_id => $ids) {
      $bundles_text = [];
      foreach ($result['bundles'][$entity_type_id] ?? [] as $bundle => $count) {
        $bundles_text[] = "$bundle: $count";
      }
      $rows[] = [$entity_type_id, count($ids), implode(', ', $bundles_text)];
    }
    $this->io()->table(['Entity type', 'Selected', 'Per bundle'], $rows);

    foreach ($result['dangling'] as $entity_type_id => $count) {
      $this->logger()->notice(dt('@count referenced @type ID(s) do not exist on Bebbo (deleted); the references are kept as they are.', [
        '@count' => $count,
        '@type' => $entity_type_id,
      ]));
    }

    if ($result['other_feeds']) {
      $this->logger()->notice(dt('@count country feed(s) of feed types this site does not have are not migrated.', [
        '@count' => $result['other_feeds'],
      ]));
    }
    foreach ($result['untranslated'] as $entity_type_id => $untranslated) {
      $this->logger()->notice(dt('Not migrated, without a translation in @seed: @type @ids', [
        '@seed' => implode(', ', $seed),
        '@type' => $entity_type_id,
        '@ids' => implode(', ', $untranslated),
      ]));
    }
    foreach ($result['broken'] as $entity_type_id => $broken) {
      $this->logger()->warning(dt('Not migrated, no translation in a language of this site (or broken on Bebbo): @type @ids', [
        '@type' => $entity_type_id,
        '@ids' => implode(', ', $broken),
      ]));
    }

    $ok = TRUE;
    foreach ($result['bundles'] as $entity_type_id => $counts) {
      $bundle_info = $this->entityTypeManager->getStorage($entity_type_id)->getEntityType()->getBundleEntityType();
      $existing = array_keys($this->entityTypeManager->getStorage($bundle_info)->loadMultiple());
      if ($missing = array_diff(array_keys($counts), $existing)) {
        $this->logger()->error(dt('Bundles missing on this site (@type): @bundles', [
          '@type' => $entity_type_id,
          '@bundles' => implode(', ', $missing),
        ]));
        $ok = FALSE;
      }
    }
    foreach ($this->selectionBuilder->collisions($result['ids']) as $entity_type_id => $count) {
      $this->logger()->error(dt('@count selected @type ID(s) already exist on this site and were not migrated from Bebbo.', [
        '@count' => $count,
        '@type' => $entity_type_id,
      ]));
      $ok = FALSE;
    }
    $this->logger()->success(dt('Selection saved.'));
    return $ok ? self::EXIT_SUCCESS : self::EXIT_FAILURE;
  }

  /**
   * Sets the country group's images and master language.
   *
   * The media reference fields (logos, flag) get Bebbo's values: the media
   * keep their Bebbo IDs, and bcm-select selects them. The master language is
   * set to the given language: Bebbo's can be one this site does not have.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:country-group', aliases: ['bcm-group'])]
  #[CLI\Argument(name: 'gid', description: 'Country group ID, the same on Bebbo and this site.')]
  #[CLI\Option(name: 'master-language', description: 'Language for field_master_language, one of this site\'s languages.')]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:country-group 51 --master-language=uz-uz', description: 'Copy the group\'s images from Bebbo and set its master language.')]
  public function countryGroup(string $gid, array $options = ['master-language' => '']): int {
    /** @var \Drupal\group\Entity\GroupInterface|null $group */
    $group = $this->entityTypeManager->getStorage('group')->load($gid);
    if (!$group) {
      $this->logger()->error(dt('Group @gid does not exist on this site.', ['@gid' => $gid]));
      return self::EXIT_FAILURE;
    }
    $master = $options['master-language'];
    if ($master !== '' && !$this->languageManager->getLanguage($master)) {
      $this->logger()->error(dt('Language @langcode is not on this site.', ['@langcode' => $master]));
      return self::EXIT_FAILURE;
    }

    /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
    $mapping = $this->entityTypeManager->getStorage('group')->getTableMapping();
    $source = $this->source->connection();
    foreach ($group->getFieldDefinitions() as $name => $definition) {
      if ($definition->getType() !== 'entity_reference' || $definition->getSetting('target_type') !== 'media') {
        continue;
      }
      $storage = $definition->getFieldStorageDefinition();
      $ids = $source->select($mapping->getDedicatedDataTableName($storage), 'f')
        ->fields('f', [$mapping->getFieldColumnName($storage, 'target_id')])
        ->condition('f.entity_id', $group->id())
        ->condition('f.langcode', $group->language()->getId())
        ->orderBy('f.delta')
        ->execute()
        ->fetchCol();
      $group->set($name, array_map('intval', $ids));
      $this->logger()->notice(dt('@field: @ids', [
        '@field' => $name,
        '@ids' => $ids ? 'media ' . implode(', ', $ids) : dt('empty on Bebbo'),
      ]));
    }
    if ($master !== '' && $group->hasField('field_master_language')) {
      $group->set('field_master_language', $master);
      $this->logger()->notice(dt('field_master_language: @langcode', ['@langcode' => $master]));
    }
    $group->save();
    $this->logger()->success(dt('Group @gid (@label) saved.', [
      '@gid' => $group->id(),
      '@label' => $group->label(),
    ]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Lists the URIs of the selected files, one per line.
   *
   * The migration creates the file entities; the script copies the files
   * themselves from the Bebbo files directories with this list.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:files', aliases: ['bcm-files'])]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:files', description: 'public://… and private://… URIs of the selected files.')]
  public function files(): int {
    $listed = [];
    foreach (array_chunk($this->selection->ids('file'), 1000) as $chunk) {
      $uris = $this->source->connection()->select('file_managed', 'f')
        ->fields('f', ['uri'])
        ->condition('f.fid', $chunk, 'IN')
        ->execute()
        ->fetchCol();
      if ($uris) {
        $this->output()->writeln(implode(PHP_EOL, $uris));
        $listed += array_flip($uris);
      }
    }
    // Files that migrated text links by path only (<img src="/sites/default/
    // files/…">, image style URLs) and that have no file entity on Bebbo.
    $found = $this->linkedFiles();
    // Names without extension, URL-decoded, of the files that are listed or
    // linked directly.
    $base = fn(string $uri) => rawurldecode(preg_replace('/\.[^.\/]+$/', '', $uri));
    $originals = [];
    foreach (array_merge(array_keys($listed), array_keys(array_filter($found, fn($style) => !$style))) as $uri) {
      $originals[$base($uri)] = TRUE;
    }
    $linked = [];
    foreach ($found as $uri => $style) {
      // An image style can convert (photo.jpg → photo.webp): its original is
      // then a file with the same name and another extension.
      if (!isset($listed[$uri]) && !($style && isset($originals[$base($uri)]))) {
        $linked[] = $uri;
      }
    }
    if ($linked) {
      sort($linked);
      $this->output()->writeln(implode(PHP_EOL, $linked));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Finds the public files linked by path in the migrated text fields.
   *
   * Reads every revision of the text and string fields of the migrated
   * entity types on this site. An image style URL gives its original file.
   *
   * @return array<string, bool>
   *   TRUE when the URI comes from an image style URL, keyed by public:// URI.
   */
  private function linkedFiles(): array {
    $uris = [];
    foreach (['node', 'media', 'taxonomy_term', 'block_content'] as $entity_type_id) {
      $mapping = $this->entityTypeManager->getStorage($entity_type_id)->getTableMapping();
      foreach ($this->entityFieldManager->getFieldStorageDefinitions($entity_type_id) as $storage) {
        if (!in_array($storage->getType(), ['text', 'text_long', 'text_with_summary', 'string', 'string_long'], TRUE) || !$mapping->requiresDedicatedTableStorage($storage)) {
          continue;
        }
        $table = $storage->isRevisionable() ? $mapping->getDedicatedRevisionTableName($storage) : $mapping->getDedicatedDataTableName($storage);
        $column = $mapping->getFieldColumnName($storage, 'value');
        $values = $this->database->select($table, 't')
          ->fields('t', [$column])
          ->condition($column, '%/sites/default/files/%', 'LIKE')
          ->distinct()
          ->execute()
          ->fetchCol();
        foreach ($values as $value) {
          // Relative links and links to Bebbo; other sites use the same path.
          preg_match_all('#(?:(?:https?:)?//([^/"\'\s]+))?/sites/default/files/([^"\'\s?\#<>)]+)#', $value, $matches, PREG_SET_ORDER);
          foreach ($matches as [, $host, $path]) {
            if ($host !== '' && !preg_match('/(^|\.)bebbo\.app$/', $host)) {
              continue;
            }
            $path = rawurldecode(html_entity_decode($path, ENT_QUOTES | ENT_HTML5));
            $original = preg_replace('#^styles/[^/]+/public/#', '', $path);
            if (!str_starts_with($original, 'styles/') && !str_starts_with($original, 'private/')) {
              $uris['public://' . $original] = ($uris['public://' . $original] ?? TRUE) && $original !== $path;
            }
          }
        }
      }
    }
    return $uris;
  }

  /**
   * Removes the feeds that are not selected, and the links to them.
   *
   * Feeds an earlier selection migrated (another country's, or one for all
   * countries such as "Media - Image") are deleted with their migrate map
   * rows. The content they imported stays; its Feeds item values that point
   * at a feed that is not selected are removed, in every revision.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:prune-feeds', aliases: ['bcm-prune-feeds'])]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:prune-feeds', description: 'Keep only the selected feeds.')]
  public function pruneFeeds(): int {
    $selected = $this->selection->ids('feeds_feed');
    $storage = $this->entityTypeManager->getStorage('feeds_feed');
    $query = $storage->getQuery()->accessCheck(FALSE);
    if ($selected) {
      $query->condition('fid', $selected, 'NOT IN');
    }
    $fids = $query->execute();
    foreach (array_chunk($fids, 50) as $chunk) {
      $storage->delete($storage->loadMultiple($chunk));
    }
    // Also the map rows of feeds no longer on this site.
    $map = 'migrate_map_' . SelectionBuilder::MIGRATIONS['feeds_feed'];
    if ($this->database->schema()->tableExists($map)) {
      $delete = $this->database->delete($map);
      if ($selected) {
        $delete->condition('sourceid1', $selected, 'NOT IN');
      }
      $delete->execute();
    }
    $this->logger()->notice(dt('@count feed(s) deleted: @ids', [
      '@count' => count($fids),
      '@ids' => $fids ? implode(', ', $fids) : dt('none'),
    ]));

    foreach ($this->entityFieldManager->getFieldMap() as $entity_type_id => $fields) {
      if (!isset($fields['feeds_item'])) {
        continue;
      }
      /** @var \Drupal\Core\Entity\Sql\DefaultTableMapping $mapping */
      $mapping = $this->entityTypeManager->getStorage($entity_type_id)->getTableMapping();
      $definition = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id)['feeds_item'];
      $column = $mapping->getFieldColumnName($definition, 'target_id');
      $tables = [$mapping->getDedicatedDataTableName($definition)];
      if ($this->entityTypeManager->getDefinition($entity_type_id)->isRevisionable() && $definition->isRevisionable()) {
        $tables[] = $mapping->getDedicatedRevisionTableName($definition);
      }
      $ids = [];
      $removed = 0;
      foreach ($tables as $table) {
        $rows = $this->database->select($table, 't')->fields('t', ['entity_id']);
        if ($selected) {
          $rows->condition("t.$column", $selected, 'NOT IN');
        }
        $ids += array_flip($rows->execute()->fetchCol());
        $delete = $this->database->delete($table);
        if ($selected) {
          $delete->condition($column, $selected, 'NOT IN');
        }
        $removed += $delete->execute();
      }
      if ($ids) {
        $this->entityTypeManager->getStorage($entity_type_id)->resetCache(array_keys($ids));
      }
      $this->logger()->notice(dt('@type: @rows Feeds item value(s) of @count entities removed', [
        '@type' => $entity_type_id,
        '@rows' => $removed,
        '@count' => count($ids),
      ]));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Deletes the migrated entities that are not selected any more.
   *
   * An earlier selection, made with other rules or languages, can have
   * migrated entities the current one leaves out. They are deleted, with
   * their migrate map rows, so this site has the selected entities only (as
   * bcm-verify-ids checks). Only migrated entities (with a migrate map row)
   * are deleted, never content created on this site, nor users 0 and 1.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:prune', aliases: ['bcm-prune'])]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:prune', description: 'Delete the entities that are not selected.')]
  public function prune(): int {
    // Entities that reference others first.
    foreach (array_reverse(SelectionBuilder::MIGRATIONS, TRUE) as $entity_type_id => $migration) {
      if (!$this->entityTypeManager->hasDefinition($entity_type_id)) {
        continue;
      }
      $map = "migrate_map_$migration";
      if (!$this->database->schema()->tableExists($map)) {
        continue;
      }
      // IDs stay as on Bebbo: the map's source ID is the entity ID here.
      $exclude = $this->selection->ids($entity_type_id);
      if ($entity_type_id === 'user') {
        $exclude = array_merge($exclude, [0, 1]);
      }
      $query = $this->database->select($map, 'm')->fields('m', ['sourceid1'])->distinct();
      if ($exclude) {
        $query->condition('m.sourceid1', $exclude, 'NOT IN');
      }
      $ids = array_map('intval', $query->execute()->fetchCol());
      if (!$ids) {
        continue;
      }
      $storage = $this->entityTypeManager->getStorage($entity_type_id);
      foreach (array_chunk($ids, 50) as $chunk) {
        $storage->delete($storage->loadMultiple($chunk));
      }
      foreach (array_chunk($ids, 1000) as $chunk) {
        $this->database->delete($map)->condition('sourceid1', $chunk, 'IN')->execute();
      }
      $this->logger()->notice(dt('@type: @count deleted (@ids)', [
        '@type' => $entity_type_id,
        '@count' => count($ids),
        '@ids' => implode(', ', array_slice($ids, 0, 20)) . (count($ids) > 20 ? ', …' : ''),
      ]));
    }
    return self::EXIT_SUCCESS;
  }

  /**
   * Compares the migrated content with Bebbo, table by table.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:verify', aliases: ['bcm-verify'])]
  #[CLI\Option(name: 'all', description: 'List the tables that match too.')]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:verify', description: 'List the tables that differ.')]
  public function verify(array $options = ['all' => FALSE]): int {
    return $this->report($this->verifier->verify(), 'tables', $options['all']);
  }

  /**
   * Checks that the entity IDs, UUIDs and revision IDs are the same as on Bebbo.
   */
  #[CLI\Command(name: 'bebbo-content-migrate:verify-ids', aliases: ['bcm-verify-ids'])]
  #[CLI\Option(name: 'all', description: 'List the entity types that match too.')]
  #[CLI\Usage(name: 'drush bebbo-content-migrate:verify-ids', description: 'List the entity types whose IDs or UUIDs differ.')]
  public function verifyIds(array $options = ['all' => FALSE]): int {
    return $this->report($this->verifier->verifyIds(), 'ID checks', $options['all']);
  }

  /**
   * Prints verification results; returns the command's exit code.
   */
  private function report(array $results, string $what, bool $all): int {
    $rows = [];
    $failed = 0;
    foreach ($results as $result) {
      $ok = !$result['missing'] && !$result['extra'] && !$result['different'];
      $failed += $ok ? 0 : 1;
      if ($ok && !$all) {
        continue;
      }
      $rows[] = [
        $ok ? 'OK' : 'DIFF',
        $result['table'],
        $result['source'],
        $result['target'],
        $result['missing'],
        $result['extra'],
        $result['different'],
        implode(', ', $result['samples']),
      ];
    }
    if ($rows) {
      $this->io()->table(['', 'Table', 'Bebbo rows', 'Rows here', 'Missing', 'Extra', 'Different', 'Samples'], $rows);
    }
    if ($failed) {
      $this->logger()->error(dt('@failed of @total @what differ from Bebbo.', [
        '@failed' => $failed,
        '@total' => count($results),
        '@what' => $what,
      ]));
      return self::EXIT_FAILURE;
    }
    $this->logger()->success(dt('All @total @what match Bebbo.', ['@total' => count($results), '@what' => $what]));
    return self::EXIT_SUCCESS;
  }

  /**
   * Splits a comma-separated option value.
   *
   * @return string[]
   *   The trimmed, non-empty values.
   */
  private function split(string $value): array {
    return array_values(array_filter(array_map('trim', explode(',', $value))));
  }

}
