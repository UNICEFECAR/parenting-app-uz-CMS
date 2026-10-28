# Bebbo content migrate

Migrates articles from the Bebbo database to this site with their full
revision history and translations, together with everything they reference:
taxonomy terms, media, files, users, other nodes (related articles, video
articles), Feeds feeds, path aliases and the memberships of the country
group; and the custom blocks, menu links, shortcuts, translation jobs and
force update checks of the country. Only the content of this site's
languages (the country's languages and the default language) is migrated. IDs,
UUIDs and values stay as on Bebbo.

Core has no Drupal 8+ to Drupal 8+ migration (`migrate_drupal` reads Drupal 6
and 7 only, and core's `entity_complete` destination is deprecated since
Drupal 11.3), so the module provides:

- `bebbo_content_entity` source plugin: reads any entity type from the Bebbo
  database with this site's table mapping. Node, media, term, custom block
  and menu link: one row per revision and language. User, file, path alias,
  feed, group relationship, shortcut and the TMGMT entities: the current
  values.
- `bebbo_entity_complete` destination plugin: saves the revisions in order,
  with the source revision IDs, translations, default revision, moderation
  states and `revision_translation_affected`. Saves are flagged as syncing.
- `bebbo_table` source plugin: reads the selected rows of a table that is
  not an entity type (`forcefull_check_update_api`), written with
  migrate_plus's `table` destination.
- Sixteen migrations in `migrations/`, run with migrate_tools.
- Drush commands to connect, select, list files and verify (tables, IDs and
  UUIDs).

## What is migrated

Nodes, media, terms and custom blocks are started from
only when they have a translation in one of the country's languages
(`--seed-langcodes`, for Uzbekistan `uz-uz`, `uz-ru`, `uz-kaa`). An entity in
English only is migrated when a migrated entity references it (an English
image or term used by an Uzbek article), and left out otherwise; `bcm-select`
lists those left out. Bebbo's front page node (`page.front` in `system.site`)
is always migrated, also when it has no translation in the country's
languages, with what it references (the custom blocks placed in its Layout
Builder sections included, and the menu links of the menus placed there,
such as the footer) and its path alias in this site's languages.

- **Nodes:** the nodes of the chosen content types (`--bundles`; the
  `content-migrate.sh` script passes every content type of this site) that
  have a translation in one of this site's languages (except the default
  one), and the nodes they reference.
- **Translations:** this site's languages only; no language is added (Drupal
  cannot save a translation in a language the site does not have). When the
  original language of an entity is another country's (an article first
  written in Ukrainian), its first translation here becomes the original:
  English, else uz-uz, uz-ru, uz-kaa (the order of `--langcodes`, not the
  site's default language first: that can change after the migration). The other-language translation, and
  revisions that only exist in it, are not migrated.
- **Revisions:** those in which a migrated translation changed, plus the
  default and the latest revision. Revisions that only changed other
  countries' languages are left out. Older Bebbo content has NULL in
  `revision_translation_affected`; there a translation counts as changed
  when its `changed` time differs from its previous revision.
- **Terms:** the terms of this site's vocabularies.
- **Media:** every media item with a translation in one of this site's
  languages (except the default one), also when no node references it.
- **Media, files, users:** those referenced by the migrated revisions (fields,
  base fields such as `uid`, `thumbnail`, `parent`, and `<drupal-media>` or
  linked entities in formatted text), and the members of the country group.
  A file that formatted text links by path only (`<img
  src="/sites/default/files/…">`, also an image style URL) is migrated when
  Bebbo has a file entity with that URI, with the media that use it
  (`file_usage`), in whatever language they are.
  Users 0 and 1 are always migrated: they exist on every site and are
  updated to be the same as on Bebbo (UUID, name, mail, password hash,
  roles, ...).
- **Group relationships:** of the groups this site has (the country group
  that `bebbo-country-config` created): the memberships with their roles,
  and relationships to migrated entities.
- **Path aliases:** of the migrated nodes, media, terms and group
  relationships, in this site's languages. Bebbo has aliases for only a few
  articles; most of its other aliases are of pages and other countries'
  group memberships.
- **Custom blocks and menu links:** with its revisions and those translations only
  (as for nodes). Bebbo's other-country menu link translations and the
  `xk-sq` block translations are left out.
- **Shortcuts:** those whose language is one of this site's languages.
- **Menu links and shortcuts** are the site's navigation (editorial menu,
  quick links, main menu; English only on Bebbo): migrated without a
  translation in the country's languages, in this site's languages.
- **Custom blocks placed by this site's block config** (`block.block.gin_logo`)
  are migrated, also in English only.
- **Translation jobs (TMGMT):** the jobs from or into one of the country's
  languages (`--seed-langcodes`, for Uzbekistan `uz-uz`, `uz-ru`, `uz-kaa`),
  with all their job items, messages and remote mappings, and the users
  they reference. An item keeps the node it translates as an ID only: the
  node is migrated only when it is selected for itself, so items of nodes
  that are not migrated point at a node that is not here, as items of
  deleted nodes do on Bebbo.
- **Force update checks:** the rows of `forcefull_check_update_api` (a
  `pb_custom_form` table, not an entity type) whose `countries_id` is a
  group this site has.
- **Feeds:** only the feeds named after one of the country's languages
  (for Uzbekistan "Uzbek", "Uzbekistan", "Karakalpak" in the title), also
  empty ones, when this site has their feed type. The `feeds_item` values
  (the feed an entity was imported with) that point at any other feed, such
  as "Media - Image" or another country's, are not migrated; `bcm-verify`
  leaves them out too.
- **Earlier selections:** after the imports, `content-migrate.sh` runs
  `bcm-prune-feeds` (deletes the feeds that are not selected and the
  `feeds_item` values that point at them) and, when confirmed, `bcm-prune`
  (deletes the migrated entities, those with a migrate map row, that the
  current selection leaves out, with their map rows). Content created on
  this site and users 0 and 1 are never deleted.
  Feeds recounts `item_count` for this site when a feed is saved, so the
  feeds migration runs last and `bcm-verify` does not compare that column.

Not migrated: redirects, and the files themselves (copy
them, step 7). An entity with no original-language translation on Bebbo
cannot be loaded by Drupal and is reported by `bcm-select` instead.

## Values Drupal changes on save, restored

Drupal's entity API does not store some Bebbo values as they are. After each
import, `SourceValueRestorer` (run by `MigrateSubscriber` on
`MigrateEvents::POST_IMPORT`) writes the Bebbo values with direct queries, so
the tables are the same as on Bebbo:

- `revision_translation_affected`: NULL on older Bebbo content; Drupal
  recomputes a NULL flag on save (1 for a translation added in a revision).
- Published status: content_moderation sets it from the moderation state,
  also while syncing; some Bebbo translations are unpublished with a
  published state.
- Revision author: Node::preSave() replaces a deleted author with the owner.
- Content moderation states: content_moderation creates a state for every
  translation it saves (Bebbo has none for some; Drupal then shows the state
  of the original language) and removes a translation's states when a later
  revision deletes the translation (Bebbo kept them). A re-rooted original
  language without a state on Bebbo keeps the state of Bebbo's original
  language, the one Drupal shows there.
- A user's empty timezone: User sets the site's default timezone (the
  anonymous user has none on Bebbo).
- Content moderation state IDs: content_moderation creates the state
  entities with this site's IDs. Each state revision gets the ID of Bebbo's
  latest state revision of the same node revision, in Bebbo's state entity
  (with its UUID); a node with two state entities on Bebbo gets the same two.

`bcm-verify` compares every migrated table, and the migrated rows of
`forcefull_check_update_api`, and must report no difference.
It leaves out these columns, which are expected to differ:

- Users in a language this site does not have get English (their field
  values are stored per language); compared in English.
- Feeds: `item_count` is counted on this site, and an empty
  `periodic_import` gets Feeds' default.
- Users: `access` and `login` change whenever an account is used, on either
  site.
- Files: `filesize` is the real size when the file is already on disk when
  it is imported; Bebbo still has the original size of many images that were
  made smaller later.

`bcm-verify-ids` checks that every migrated entity (and the country group)
has Bebbo's ID and UUID, every migrated revision has Bebbo's revision ID, and
that no other entity exists here. Content moderation state entities and
revisions are checked too.

## Commands

`bebbo-country-config/content-migrate.sh --source=<bebbo repo> --target=.
--country=<group ID>` runs all of this in one go (see
`bebbo-country-config/README.md`). By hand, run them from the repo root, with the Bebbo DDEV project running. The
migrations read Bebbo's database and never write to it.

```bash
# 1. Enable the module and point it at the Bebbo database.
ddev drush en bebbo_content_migrate -y
ddev drush bcm-connect --host=ddev-bebbo.app-db

# 2. Set the country group's images (copied from Bebbo) and master language.
ddev drush bcm-group 51 --master-language=uz-uz

# 3. Choose the content (about 5 minutes).
ddev drush bcm-select

# 4. Import, in this order.
ddev drush migrate:status --tag=bebbo_content
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_file
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_user
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_taxonomy_term
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_media
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_node
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_block_content
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_menu_link_content
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_shortcut
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_group_relationship
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_path_alias
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_tmgmt_job
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_tmgmt_job_item
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_tmgmt_message
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_tmgmt_remote
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_forcefull_check_update_api
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php migrate:import bebbo_feeds_feed

# 5. Errors of a migration, if any.
ddev drush migrate:messages bebbo_node

# 6. Compare the IDs and UUIDs, and every migrated table, with Bebbo.
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php bebbo-content-migrate:verify-ids
ddev exec -- php -d memory_limit=-1 vendor/bin/drush.php bebbo-content-migrate:verify

# 7. The URIs of the files to copy from Bebbo's files directories: the
#    migrated file entities, and the files the migrated text links by path
#    only (<img src="/sites/default/files/…"> with no file entity).
ddev drush bcm-files > bebbo-files.txt

# 8. Export the config change (module enabled).
ddev drush config:export -y
```

An interrupted import continues where it stopped when run again. To import
rows again after a fix, add `--update`. To remove a migration's content:
`ddev drush migrate:rollback bebbo_node` (roll back in reverse order).

`bcm-connect` also stops when a migration does not map a stored field of its
entity type, or a column of its table: add new fields to the migration YAML.
