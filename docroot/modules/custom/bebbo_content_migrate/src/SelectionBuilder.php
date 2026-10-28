<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeBundleInfoInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\layout_builder\Section;

/**
 * Decides which Bebbo entities are migrated.
 *
 * Starts from the nodes of the given bundles and the media that have a
 * translation in one of the given languages, and all terms of the
 * vocabularies this site has.
 * Then follows every reference of the migrated revisions and languages until
 * nothing new is found:
 * - entity reference, file and image fields (base fields such
 *   as uid, revision_uid, thumbnail and parent included);
 * - entities embedded in formatted text (<drupal-media data-entity-uuid>,
 *   links with data-entity-type and data-entity-uuid);
 * - files linked by path in formatted text (<img src="/sites/default/files/
 *   …">) that have a file entity on Bebbo, and the media that use them;
 * - custom blocks placed in Layout Builder sections (block_content:UUID),
 *   and the menu links of the menus placed there (system_menu_block:MENU),
 *   such as those of the front page.
 * The group relationships of the groups this site has (the country group
 * created by bebbo-country-config) come too: memberships with their users,
 * and relationships to entities that are selected. Path aliases of the
 * selected nodes, media, terms and group relationships are added last.
 *
 * Also selected: the feeds named after one of the seed languages (also
 * empty ones), the custom blocks, menu links and shortcuts, the
 * translation jobs from or into one of the seed languages with all their
 * items, messages and remote mappings, and the rows of the TABLES that belong
 * to the groups this site has.
 *
 * Entities of the TRANSLATED types are started from only when they have a
 * translation in one of the seed languages: those in English only are left
 * out unless a migrated entity references them. Bebbo's front page node is
 * always migrated, in whatever language it has, and so are the media the
 * groups this site has show (logos, set by bcm-group), and the custom
 * blocks this site's block placements show (block.block.gin_logo).
 *
 * Users 0 (anonymous) and 1 (administrator) are always migrated: they exist
 * on every site and are updated to be the same as on Bebbo (UUID included).
 */
final class SelectionBuilder {

  /**
   * Entity types whose references are followed.
   */
  public const TYPES = [
    'node',
    'media',
    'taxonomy_term',
    'file',
    'user',
    'feeds_feed',
    'block_content',
    'menu_link_content',
    'shortcut',
    'tmgmt_job',
    'tmgmt_job_item',
    'tmgmt_message',
    'tmgmt_remote',
  ];

  /**
   * Entity types started from only with a translation in a seed language.
   *
   * Not menu links and shortcuts: they are the site's navigation (the
   * editorial menu, quick links), in English only on Bebbo. They are
   * migrated in this site's languages.
   */
  private const TRANSLATED = [
    'node',
    'media',
    'taxonomy_term',
    'block_content',
  ];

  /**
   * The migration that migrates each entity type.
   */
  public const MIGRATIONS = [
    'user' => 'bebbo_user',
    'file' => 'bebbo_file',
    'taxonomy_term' => 'bebbo_taxonomy_term',
    'media' => 'bebbo_media',
    'node' => 'bebbo_node',
    'path_alias' => 'bebbo_path_alias',
    'feeds_feed' => 'bebbo_feeds_feed',
    'group_relationship' => 'bebbo_group_relationship',
    'block_content' => 'bebbo_block_content',
    'menu_link_content' => 'bebbo_menu_link_content',
    'shortcut' => 'bebbo_shortcut',
    'tmgmt_job' => 'bebbo_tmgmt_job',
    'tmgmt_job_item' => 'bebbo_tmgmt_job_item',
    'tmgmt_message' => 'bebbo_tmgmt_message',
    'tmgmt_remote' => 'bebbo_tmgmt_remote',
  ];

  /**
   * Tables that are not entity types, migrated with the groups' rows.
   *
   * Keyed by table: the migration, the ID column, and the column that holds
   * the ID of the country group a row belongs to.
   */
  public const TABLES = [
    'forcefull_check_update_api' => [
      'migration' => 'bebbo_forcefull_check_update_api',
      'id' => 'id',
      'group' => 'countries_id',
    ],
  ];

  /**
   * The entity type each group relation plugin relates, by plugin base ID.
   */
  private const RELATION_TYPES = [
    'group_membership' => 'user',
    'group_node' => 'node',
    'group_media' => 'media',
    'group_term' => 'taxonomy_term',
  ];

  /**
   * Field types whose target_id references an entity.
   */
  private const REFERENCE_TYPES = ['entity_reference', 'entity_reference_revisions', 'file', 'image'];

  /**
   * Field types that can embed entities in their markup.
   */
  private const TEXT_TYPES = ['text', 'text_long', 'text_with_summary'];

  /**
   * The paths of the canonical routes the path aliases point at.
   */
  private const PATHS = [
    'node' => '/node/',
    'media' => '/media/',
    'taxonomy_term' => '/taxonomy/term/',
  ];

  /**
   * Number of entity IDs per query.
   */
  private const CHUNK = 500;

  /**
   * Number of entities whose references are read per query.
   *
   * Lower than CHUNK: the text of every migrated revision is read to find
   * embedded entities, and MySQL results are buffered in memory.
   */
  private const REFERENCE_CHUNK = 100;

  public function __construct(
    private readonly SourceTables $tables,
    private readonly SourceDatabase $source,
    private readonly Connection $database,
    private readonly EntityTypeBundleInfoInterface $bundleInfo,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Finds the entities to migrate.
   *
   * @param string[] $bundles
   *   The node bundles to start from (article).
   * @param string[] $seed_langcodes
   *   The nodes and media to start from have a translation in one of these
   *   languages.
   * @param string[] $langcodes
   *   The languages whose translations are migrated, besides the original
   *   language of each entity.
   *
   * @return array
   *   - ids: entity IDs keyed by entity type ID, and row IDs keyed by
   *     table (TABLES).
   *   - dangling: per entity type, the number of referenced IDs that do not
   *     exist on the source (deleted entities, kept as they are).
   *   - broken: per entity type, the IDs left out because they have no
   *     translation to migrate: in another country's language only (nodes,
   *     terms), or without an original-language translation on Bebbo
   *     (Drupal cannot load such an entity).
   *   - untranslated: per entity type (TRANSLATED), the IDs left out because
   *     they have no translation in one of the seed languages and no migrated
   *     entity references them.
   *   - other_feeds: the number of country feeds left out because this
   *     site does not have their feed type.
   *   - bundles: per entity type, the number of entities per bundle.
   */
  public function build(array $bundles, array $seed_langcodes, array $langcodes): array {
    $source = $this->source->connection();
    $ids = array_fill_keys(self::TYPES, []);
    $dangling = array_fill_keys(self::TYPES, 0);

    $queue = [
      'node' => $source->select('node_field_data', 'n')
        ->fields('n', ['nid'])
        ->condition('n.type', $bundles, 'IN')
        ->condition('n.langcode', $seed_langcodes, 'IN')
        ->distinct()
        ->execute()
        ->fetchCol(),
      'taxonomy_term' => $source->select('taxonomy_term_data', 't')
        ->fields('t', ['tid'])
        ->condition('t.vid', array_keys($this->bundleInfo->getBundleInfo('taxonomy_term')) ?: [''], 'IN')
        ->execute()
        ->fetchCol(),
      // Media in the country's languages, also those no node references.
      'media' => $source->select($this->tables->entityType('media')->getDataTable(), 'm')
        ->fields('m', ['mid'])
        ->condition('m.langcode', $seed_langcodes, 'IN')
        ->distinct()
        ->execute()
        ->fetchCol(),
      'user' => [0, 1],
      // Blocks and menu links in another language only are left out below,
      // as nodes are (withoutRows()).
      'block_content' => $source->select('block_content', 'b')
        ->fields('b', ['id'])
        ->execute()
        ->fetchCol(),
      'menu_link_content' => $source->select('menu_link_content', 'l')
        ->fields('l', ['id'])
        ->execute()
        ->fetchCol(),
      'shortcut' => $source->select('shortcut_field_data', 's')
        ->fields('s', ['id'])
        ->condition('s.default_langcode', 1)
        ->condition('s.langcode', $this->tables->keptLangcodes($langcodes), 'IN')
        ->execute()
        ->fetchCol(),
    ];
    $queue += $this->translationJobs($source, $seed_langcodes);
    $queue['feeds_feed'] = $this->countryFeeds($source, $seed_langcodes);
    // Started from only in a seed language; referenced ones come below.
    $untranslated = [];
    foreach (self::TRANSLATED as $entity_type_id) {
      $translated = $this->translated($source, $entity_type_id, $queue[$entity_type_id], $seed_langcodes);
      $untranslated[$entity_type_id] = array_diff(array_map('intval', $queue[$entity_type_id]), $translated);
      $queue[$entity_type_id] = $translated;
    }
    $queue['node'] = array_merge($queue['node'], $this->frontPage($source));
    $queue['media'] = array_merge($queue['media'], $this->groupMedia());
    $queue['block_content'] = array_merge($queue['block_content'], $this->placedBlocks($source));
    // Members of this site's groups, and the users who added them.
    $relationships = $this->groupRelationships($source);
    foreach ($relationships as $relationship) {
      if ($relationship->target_type === 'user') {
        $queue['user'][] = $relationship->entity_id;
      }
      $queue['user'][] = $relationship->uid;
    }

    while ($queue) {
      $next = [];
      foreach ($queue as $entity_type_id => $candidates) {
        $candidates = array_diff(array_unique(array_map('intval', $candidates)), array_keys($ids[$entity_type_id]));
        if (!$candidates) {
          continue;
        }
        $existing = $this->existing($source, $entity_type_id, $candidates);
        $dangling[$entity_type_id] += count($candidates) - count($existing);
        $ids[$entity_type_id] += array_fill_keys($existing, TRUE);
        foreach (array_chunk($existing, self::REFERENCE_CHUNK) as $chunk) {
          foreach ($this->references($source, $entity_type_id, $chunk, $langcodes) as $target_type => $target_ids) {
            $next[$target_type] = array_merge($next[$target_type] ?? [], $target_ids);
          }
        }
      }
      $queue = array_filter($next);
    }

    $ids = array_map(static function (array $list): array {
      $list = array_keys($list);
      sort($list);
      return $list;
    }, $ids);
    // A feed needs its feed type: feeds of a type this site does not have are
    // left out. Only the country's feeds are migrated (countryFeeds()); the
    // Feeds item values that point at other feeds are not migrated, see
    // Selection::feedItems().
    $feed_types = array_keys($this->bundleInfo->getBundleInfo('feeds_feed'));
    $feeds = [];
    foreach (array_chunk($ids['feeds_feed'], self::CHUNK) as $chunk) {
      $feeds = array_merge($feeds, $source->select('feeds_feed', 'f')
        ->fields('f', ['fid'])
        ->condition('f.fid', $chunk, 'IN')
        ->condition('f.type', $feed_types ?: [''], 'IN')
        ->execute()
        ->fetchCol());
    }
    $other_feeds = count($ids['feeds_feed']) - count($feeds);
    $ids['feeds_feed'] = array_map('intval', $feeds);

    $broken = [];
    foreach (SourceTables::REVISIONED as $entity_type_id) {
      $broken[$entity_type_id] = $this->withoutRows($source, $entity_type_id, $ids[$entity_type_id], $langcodes);
      $ids[$entity_type_id] = array_values(array_diff($ids[$entity_type_id], $broken[$entity_type_id]));
    }
    // Relationships to entities that are migrated.
    $ids['group_relationship'] = [];
    foreach ($relationships as $relationship) {
      if (in_array((int) $relationship->entity_id, $ids[$relationship->target_type] ?? [], TRUE)) {
        $ids['group_relationship'][] = (int) $relationship->id;
      }
    }
    $ids['path_alias'] = $this->pathAliases($source, $ids, $langcodes);
    $ids += $this->tableRows($source);

    return [
      'ids' => $ids,
      'dangling' => array_filter($dangling),
      'broken' => array_filter($broken),
      'untranslated' => array_filter(array_map(static fn (string $entity_type_id): array => array_values(array_diff($untranslated[$entity_type_id], $ids[$entity_type_id])), array_combine(self::TRANSLATED, self::TRANSLATED))),
      'other_feeds' => $other_feeds,
      'bundles' => $this->bundles($source, $ids),
    ];
  }

  /**
   * Returns the entities that have no row to migrate.
   *
   * @return int[]
   *   Entity IDs.
   */
  private function withoutRows(Connection $source, string $entity_type_id, array $ids, array $langcodes): array {
    $id = $this->tables->entityType($entity_type_id)->getKey('id');
    $found = [];
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $query = $source->select($this->tables->rowsQuery($source, $entity_type_id, $chunk, $langcodes), 's');
      $query->addField('s', $id);
      $found = array_merge($found, array_map('intval', $query->distinct()->execute()->fetchCol()));
    }
    return array_values(array_diff($ids, $found));
  }

  /**
   * Returns the entities that have a translation in one of the seed languages.
   *
   * @return int[]
   *   Entity IDs.
   */
  private function translated(Connection $source, string $entity_type_id, array $ids, array $seed_langcodes): array {
    $entity_type = $this->tables->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $langcode = $entity_type->getKey('langcode');
    $translated = [];
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $translated = array_merge($translated, $source->select($entity_type->getDataTable(), 'd')
        ->fields('d', [$id])
        ->condition("d.$id", $chunk, 'IN')
        ->condition("d.$langcode", $seed_langcodes, 'IN')
        ->distinct()
        ->execute()
        ->fetchCol());
    }
    return array_map('intval', $translated);
  }

  /**
   * Returns the node that is Bebbo's front page.
   *
   * @return int[]
   *   The node ID, or nothing when the front page is not a node.
   */
  private function frontPage(Connection $source): array {
    $data = $source->select('config', 'c')
      ->fields('c', ['data'])
      ->condition('c.collection', '')
      ->condition('c.name', 'system.site')
      ->execute()
      ->fetchField();
    $config = $data ? unserialize($data, ['allowed_classes' => FALSE]) : [];
    return preg_match('@^/node/(\d+)$@', $config['page']['front'] ?? '', $matches) ? [(int) $matches[1]] : [];
  }

  /**
   * Returns the IDs that exist on the source.
   *
   * @return int[]
   *   Entity IDs.
   */
  private function existing(Connection $source, string $entity_type_id, array $ids): array {
    $entity_type = $this->tables->entityType($entity_type_id);
    $id = $entity_type->getKey('id');
    $existing = [];
    foreach (array_chunk($ids, self::CHUNK) as $chunk) {
      $existing = array_merge($existing, $source->select($entity_type->getBaseTable(), 'b')
        ->fields('b', [$id])
        ->condition("b.$id", $chunk, 'IN')
        ->execute()
        ->fetchCol());
    }
    return array_map('intval', $existing);
  }

  /**
   * Returns the entities that the given entities reference.
   *
   * @return array
   *   Entity IDs keyed by entity type ID.
   */
  private function references(Connection $source, string $entity_type_id, array $ids, array $langcodes): array {
    $entity_type = $this->tables->entityType($entity_type_id);
    $revisioned = $this->tables->isRevisioned($entity_type_id);
    $definitions = $this->tables->storedFields($entity_type_id);
    $found = [];
    $uuids = [];
    $menus = [];
    $paths = [];

    foreach ($this->tables->fieldTables($entity_type_id) as $name => $info) {
      $field_type = $definitions[$name]->getType();
      $target_type = $definitions[$name]->getSetting('target_type');
      $is_reference = in_array($field_type, self::REFERENCE_TYPES, TRUE) && in_array($target_type, self::TYPES, TRUE);
      $is_text = in_array($field_type, self::TEXT_TYPES, TRUE);
      $is_layout = $field_type === 'layout_section';
      if (!$is_reference && !$is_text && !$is_layout) {
        continue;
      }
      $column = match (TRUE) {
        $is_reference => $info['columns']['target_id'],
        $is_layout => $info['columns']['section'],
        default => $info['columns']['value'],
      };

      if ($info['dedicated']) {
        $query = $source->select($info['table'], 'f');
        $query->addField('f', $column, 'value');
        $query->condition('f.deleted', 0);
        if (!empty($info['revision'])) {
          $rows = $this->tables->rowsQuery($source, $entity_type_id, $ids, $langcodes);
          $revision = $entity_type->getKey('revision');
          $langcode = $entity_type->getKey('langcode');
          $query->innerJoin($rows, 's', "[s].[$revision] = [f].[revision_id] AND [s].[$langcode] = [f].[langcode]");
        }
        else {
          $query->condition('f.entity_id', $ids, 'IN');
        }
      }
      else {
        if ($revisioned) {
          $query = $this->tables->rowsQuery($source, $entity_type_id, $ids, $langcodes);
          $revision = $entity_type->getKey('revision');
          $query->innerJoin($entity_type->getRevisionTable(), 'r', "[r].[$revision] = [fr].[$revision]");
          $this->tables->joinDataTable($query, $entity_type_id);
          $alias = match ($info['table']) {
            $entity_type->getRevisionDataTable() => 'fr',
            $entity_type->getRevisionTable() => 'r',
            $entity_type->getDataTable() => 'd',
            default => 'b',
          };
        }
        else {
          $query = $this->tables->currentQuery($source, $entity_type_id, $ids);
          $alias = $info['table'] === $entity_type->getBaseTable() ? 'b' : 'd';
        }
        $query->addField($alias, $column, 'value');
      }

      $field = $info['dedicated'] ? "f.$column" : "$alias.$column";
      if ($is_layout) {
        $query->condition($field, '%_block:%', 'LIKE');
      }
      elseif ($is_text) {
        $query->condition($query->orConditionGroup()
          ->condition($field, '%data-entity-uuid%', 'LIKE')
          ->condition($field, '%/sites/default/files/%', 'LIKE'));
      }
      $values = [];
      foreach ($query->execute() as $record) {
        if ($is_reference && is_numeric($record->value)) {
          $values[$record->value] = TRUE;
        }
        elseif ($is_layout && $record->value !== NULL) {
          $this->collectLayoutReferences($record->value, $uuids, $menus);
        }
        elseif (!$is_reference && $record->value !== NULL) {
          $this->collectUuids($record->value, $uuids);
          $this->collectPaths($record->value, $paths);
        }
      }
      if ($values) {
        $found[$target_type] = array_merge($found[$target_type] ?? [], array_keys($values));
      }
    }
    foreach ($this->resolveUuids($source, $uuids) as $target_type => $target_ids) {
      $found[$target_type] = array_merge($found[$target_type] ?? [], $target_ids);
    }
    foreach ($this->resolvePaths($source, $paths) as $target_type => $target_ids) {
      $found[$target_type] = array_merge($found[$target_type] ?? [], $target_ids);
    }
    if ($menus) {
      $found['menu_link_content'] = array_merge($found['menu_link_content'] ?? [], $source->select($this->tables->entityType('menu_link_content')->getDataTable(), 'l')
        ->fields('l', ['id'])
        ->condition('l.menu_name', array_keys($menus), 'IN')
        ->distinct()
        ->execute()
        ->fetchCol());
    }
    return $found;
  }

  /**
   * Collects the UUIDs of the entities embedded in formatted text.
   *
   * @param string $text
   *   Markup.
   * @param array $uuids
   *   UUIDs found so far, keyed by entity type ID and UUID.
   */
  private function collectUuids(string $text, array &$uuids): void {
    preg_match_all('/<[a-z-]+\s[^>]*data-entity-uuid="[^"]*"[^>]*>/i', $text, $tags);
    foreach ($tags[0] as $tag) {
      if (preg_match('/data-entity-type="([a-z_]+)"/', $tag, $type)
        && preg_match('/data-entity-uuid="([0-9a-f-]{36})"/', $tag, $uuid)
        && in_array($type[1], self::TYPES, TRUE)) {
        $uuids[$type[1]][$uuid[1]] = TRUE;
      }
    }
  }

  /**
   * Collects the public files linked by path in formatted text.
   *
   * Relative links and links to Bebbo (<img src="/sites/default/files/…">);
   * an image style URL gives its original file.
   *
   * @param string $text
   *   Markup.
   * @param array $paths
   *   public:// URIs found so far, as keys.
   */
  private function collectPaths(string $text, array &$paths): void {
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
    preg_match_all('#(?:(?:https?:)?//([^/"\'\s]+))?/sites/default/files/([^"\'?\#<>)]+)#', $text, $matches, PREG_SET_ORDER);
    foreach ($matches as [, $host, $path]) {
      if ($host !== '' && !preg_match('/(^|\.)bebbo\.app$/', $host)) {
        continue;
      }
      $path = preg_replace('#^styles/[^/]+/public/#', '', rawurldecode(trim($path)));
      if (!str_starts_with($path, 'styles/') && !str_starts_with($path, 'private/')) {
        $paths['public://' . $path] = TRUE;
      }
    }
  }

  /**
   * Returns the files with the given URIs, and the media that use them.
   *
   * @param \Drupal\Core\Database\Connection $source
   *   The source database.
   * @param array $paths
   *   public:// URIs, as keys.
   *
   * @return array
   *   Entity IDs keyed by entity type ID (file, media).
   */
  private function resolvePaths(Connection $source, array $paths): array {
    $files = [];
    foreach (array_chunk(array_keys($paths), self::CHUNK) as $chunk) {
      $files = array_merge($files, $source->select('file_managed', 'f')
        ->fields('f', ['fid'])
        ->condition('f.uri', $chunk, 'IN')
        ->execute()
        ->fetchCol());
    }
    if (!$files) {
      return [];
    }
    $media = [];
    foreach (array_chunk($files, self::CHUNK) as $chunk) {
      $media = array_merge($media, $source->select('file_usage', 'u')
        ->fields('u', ['id'])
        ->condition('u.fid', $chunk, 'IN')
        ->condition('u.type', 'media')
        ->distinct()
        ->execute()
        ->fetchCol());
    }
    return ['file' => $files, 'media' => $media];
  }

  /**
   * Collects the custom blocks and menus placed in a Layout Builder section.
   *
   * @param string $section
   *   A serialized section.
   * @param array $uuids
   *   UUIDs found so far, keyed by entity type ID and UUID.
   * @param array $menus
   *   Menu names found so far, as keys: their menu links are selected.
   */
  private function collectLayoutReferences(string $section, array &$uuids, array &$menus): void {
    $section = unserialize($section, [
      'allowed_classes' => [
        'Drupal\layout_builder\Section',
        'Drupal\layout_builder\SectionComponent',
      ],
    ]);
    if (!$section instanceof Section) {
      return;
    }
    foreach ($section->getComponents() as $component) {
      if (preg_match('/^block_content:([0-9a-f-]{36})$/', $component->getPluginId(), $uuid)) {
        $uuids['block_content'][$uuid[1]] = TRUE;
      }
      elseif (preg_match('/^system_menu_block:(.+)$/', $component->getPluginId(), $menu)) {
        $menus[$menu[1]] = TRUE;
      }
    }
  }

  /**
   * Returns the IDs of the entities with the given UUIDs.
   *
   * @param \Drupal\Core\Database\Connection $source
   *   The source database.
   * @param array $uuids
   *   UUIDs keyed by entity type ID and UUID.
   *
   * @return array
   *   Entity IDs keyed by entity type ID.
   */
  private function resolveUuids(Connection $source, array $uuids): array {
    $found = [];
    foreach ($uuids as $entity_type_id => $list) {
      $entity_type = $this->tables->entityType($entity_type_id);
      foreach (array_chunk(array_keys($list), self::CHUNK) as $chunk) {
        $found[$entity_type_id] = array_merge($found[$entity_type_id] ?? [], $source->select($entity_type->getBaseTable(), 'b')
          ->fields('b', [$entity_type->getKey('id')])
          ->condition('b.' . $entity_type->getKey('uuid'), $chunk, 'IN')
          ->execute()
          ->fetchCol());
      }
    }
    return $found;
  }

  /**
   * Returns the source relationships of the groups that exist on this site.
   *
   * @return object[]
   *   Records with id, gid, entity_id, uid and target_type (the related
   *   entity type), for the relation plugins in RELATION_TYPES.
   */
  private function groupRelationships(Connection $source): array {
    if (!$this->tables->hasEntityType('group') || !$this->tables->hasEntityType('group_relationship')) {
      return [];
    }
    $groups = $this->groupIds();
    if (!$groups) {
      return [];
    }
    $records = $source->select($this->tables->entityType('group_relationship')->getDataTable(), 'r')
      ->fields('r', ['id', 'gid', 'entity_id', 'uid', 'plugin_id'])
      ->condition('r.gid', $groups, 'IN')
      ->condition('r.default_langcode', 1)
      ->orderBy('r.id')
      ->execute()
      ->fetchAll();
    $relationships = [];
    foreach ($records as $record) {
      $base = strtok($record->plugin_id, ':');
      if (isset(self::RELATION_TYPES[$base])) {
        $record->target_type = self::RELATION_TYPES[$base];
        $relationships[] = $record;
      }
    }
    return $relationships;
  }

  /**
   * Returns the custom blocks that this site's block placements show.
   *
   * A block placement (block.block.gin_logo) is config: no migrated entity
   * references its custom block, which can be in English only.
   *
   * @return int[]
   *   Custom block IDs on the source.
   */
  private function placedBlocks(Connection $source): array {
    $uuids = [];
    foreach ($this->entityTypeManager->getStorage('block')->loadMultiple() as $block) {
      if (preg_match('/^block_content:([0-9a-f-]{36})$/', $block->getPluginId(), $uuid)) {
        $uuids['block_content'][$uuid[1]] = TRUE;
      }
    }
    return array_map('intval', $this->resolveUuids($source, $uuids)['block_content'] ?? []);
  }

  /**
   * Returns the media that the groups this site has reference.
   *
   * @return int[]
   *   Media IDs.
   */
  private function groupMedia(): array {
    if (!$this->tables->hasEntityType('group')) {
      return [];
    }
    $media = [];
    foreach ($this->entityTypeManager->getStorage('group')->loadMultiple($this->groupIds()) as $group) {
      foreach ($group->getFieldDefinitions() as $name => $definition) {
        if ($definition->getType() === 'entity_reference' && $definition->getSetting('target_type') === 'media') {
          $media = array_merge($media, array_column($group->get($name)->getValue(), 'target_id'));
        }
      }
    }
    return array_map('intval', $media);
  }

  /**
   * Returns the IDs of the groups this site has.
   *
   * @return int[]
   *   Group IDs.
   */
  private function groupIds(): array {
    if (!$this->tables->hasEntityType('group')) {
      return [];
    }
    return array_map('intval', $this->database->select($this->tables->entityType('group')->getBaseTable(), 'g')
      ->fields('g', ['id'])
      ->execute()
      ->fetchCol());
  }

  /**
   * Returns the feeds named after one of the given languages.
   *
   * Bebbo's feeds are named after the country language they import
   * ("Uzbek keywords", "Taxonomy - Keywords - Karakalpak"), also those that
   * imported nothing yet. The words come from the languages' names, with
   * the country's name also without "istan" (Uzbekistan, Uzbek). Feeds of a
   * feed type this site does not have are left out later, see build().
   *
   * @return int[]
   *   Feed IDs.
   */
  private function countryFeeds(Connection $source, array $langcodes): array {
    $words = [];
    foreach ($this->entityTypeManager->getStorage('configurable_language')->loadMultiple($langcodes) as $language) {
      foreach (preg_split('/[^a-z]+/', strtolower((string) $language->label())) as $word) {
        if (strlen($word) >= 4 && !in_array($word, ['english', 'russian'], TRUE)) {
          $words[$word] = TRUE;
          $words[preg_replace('/istan$/', '', $word)] = TRUE;
        }
      }
    }
    if (!$words) {
      return [];
    }
    $query = $source->select('feeds_feed', 'f')->fields('f', ['fid']);
    $titles = $query->orConditionGroup();
    foreach (array_keys($words) as $word) {
      $titles->condition('f.title', '%' . $source->escapeLike($word) . '%', 'LIKE');
    }
    return array_map('intval', $query->condition($titles)->execute()->fetchCol());
  }

  /**
   * Returns the translation jobs from or into one of the given languages.
   *
   * With all their items, messages and remote mappings. The items keep the
   * node they translate as an ID only: that node is migrated only when it is
   * selected for itself.
   *
   * @return array
   *   IDs keyed by entity type ID (tmgmt_job, tmgmt_job_item, tmgmt_message,
   *   tmgmt_remote).
   */
  private function translationJobs(Connection $source, array $langcodes): array {
    if (!$this->tables->hasEntityType('tmgmt_job')) {
      return [];
    }
    $query = $source->select('tmgmt_job', 'j')->fields('j', ['tjid']);
    $query->condition($query->orConditionGroup()
      ->condition('j.source_language', $langcodes, 'IN')
      ->condition('j.target_language', $langcodes, 'IN'));
    $jobs = $query->execute()->fetchCol();
    $found = ['tmgmt_job' => $jobs];
    foreach (['tmgmt_job_item' => 'tjiid', 'tmgmt_message' => 'mid', 'tmgmt_remote' => 'trid'] as $entity_type_id => $id) {
      $found[$entity_type_id] = [];
      foreach (array_chunk($jobs, self::CHUNK) as $chunk) {
        $found[$entity_type_id] = array_merge($found[$entity_type_id], $source->select($entity_type_id, 't')
          ->fields('t', [$id])
          ->condition('t.tjid', $chunk, 'IN')
          ->execute()
          ->fetchCol());
      }
    }
    return $found;
  }

  /**
   * Returns the rows of the TABLES that belong to the groups this site has.
   *
   * @return array
   *   Row IDs keyed by table, for the tables this site and the source have.
   */
  private function tableRows(Connection $source): array {
    $groups = $this->groupIds();
    $rows = [];
    foreach (self::TABLES as $table => $info) {
      if (!$this->database->schema()->tableExists($table) || !$source->schema()->tableExists($table)) {
        continue;
      }
      $rows[$table] = $groups ? array_map('intval', $source->select($table, 't')
        ->fields('t', [$info['id']])
        ->condition('t.' . $info['group'], array_map('strval', $groups), 'IN')
        ->orderBy('t.' . $info['id'])
        ->execute()
        ->fetchCol()) : [];
    }
    return $rows;
  }

  /**
   * Returns the path aliases of the selected nodes, media, terms and group
   * relationships.
   *
   * Only aliases in a kept language (see SourceTables::keptLangcodes()).
   *
   * @return int[]
   *   Path alias IDs.
   */
  private function pathAliases(Connection $source, array $ids, array $langcodes): array {
    $kept = $this->tables->keptLangcodes($langcodes);
    $aliases = [];
    foreach (self::PATHS as $entity_type_id => $prefix) {
      foreach (array_chunk($ids[$entity_type_id], self::CHUNK) as $chunk) {
        $aliases = array_merge($aliases, $source->select('path_alias', 'p')
          ->fields('p', ['id'])
          ->condition('p.path', array_map(static fn($id) => $prefix . $id, $chunk), 'IN')
          ->condition('p.langcode', $kept, 'IN')
          ->execute()
          ->fetchCol());
      }
    }
    // A group relationship's path names its group: /group/51/content/16786.
    if ($ids['group_relationship']) {
      $paths = [];
      $records = $source->select($this->tables->entityType('group_relationship')->getDataTable(), 'r')
        ->fields('r', ['id', 'gid'])
        ->condition('r.id', $ids['group_relationship'], 'IN')
        ->condition('r.default_langcode', 1)
        ->execute();
      foreach ($records as $record) {
        $paths[] = "/group/$record->gid/content/$record->id";
      }
      foreach (array_chunk($paths, self::CHUNK) as $chunk) {
        $aliases = array_merge($aliases, $source->select('path_alias', 'p')
          ->fields('p', ['id'])
          ->condition('p.path', $chunk, 'IN')
          ->condition('p.langcode', $kept, 'IN')
          ->execute()
          ->fetchCol());
      }
    }
    $aliases = array_map('intval', $aliases);
    sort($aliases);
    return $aliases;
  }

  /**
   * Counts the selected entities per bundle.
   *
   * @return array
   *   Per entity type ID, the number of entities keyed by bundle.
   */
  private function bundles(Connection $source, array $ids): array {
    $counts = [];
    foreach (['node', 'media', 'taxonomy_term', 'feeds_feed', 'block_content', 'shortcut'] as $entity_type_id) {
      $entity_type = $this->tables->entityType($entity_type_id);
      $bundle = $entity_type->getKey('bundle');
      foreach (array_chunk($ids[$entity_type_id], self::CHUNK) as $chunk) {
        $query = $source->select($entity_type->getBaseTable(), 'b');
        $query->addField('b', $bundle, 'bundle');
        $query->addExpression('COUNT(*)', 'total');
        $query->condition('b.' . $entity_type->getKey('id'), $chunk, 'IN');
        $query->groupBy('b.' . $bundle);
        foreach ($query->execute()->fetchAllKeyed() as $name => $total) {
          $counts[$entity_type_id][$name] = ($counts[$entity_type_id][$name] ?? 0) + (int) $total;
        }
      }
    }
    return $counts;
  }

  /**
   * Counts the selected IDs that exist on this site but were not migrated.
   *
   * Such an entity was created here, and would be overwritten by the Bebbo
   * entity with the same ID. Users 0 and 1 are left out: they exist on every
   * site and are meant to be overwritten.
   *
   * @return int[]
   *   Counts keyed by entity type ID.
   */
  public function collisions(array $ids): array {
    $collisions = [];
    foreach (self::MIGRATIONS as $entity_type_id => $migration_id) {
      $entity_type = $this->tables->entityType($entity_type_id);
      $id = $entity_type->getKey('id');
      $map = 'migrate_map_' . $migration_id;
      $has_map = $this->database->schema()->tableExists($map);
      foreach (array_chunk($ids[$entity_type_id] ?? [], self::CHUNK) as $chunk) {
        $query = $this->database->select($entity_type->getBaseTable(), 'b');
        $query->addExpression('COUNT(*)');
        $query->condition("b.$id", $chunk, 'IN');
        if ($entity_type_id === 'user') {
          $query->condition("b.$id", [0, 1], 'NOT IN');
        }
        if ($has_map) {
          $migrated = $this->database->select($map, 'm');
          $migrated->addExpression('1');
          $migrated->where("[m].[destid1] = [b].[$id]");
          $query->notExists($migrated);
        }
        $count = (int) $query->execute()->fetchField();
        if ($count) {
          $collisions[$entity_type_id] = ($collisions[$entity_type_id] ?? 0) + $count;
        }
      }
    }
    return $collisions;
  }

}
