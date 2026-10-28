<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Plugin\migrate\destination;

use Drupal\bebbo_content_migrate\Plugin\Derivative\BebboEntityCompleteDeriver;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\MigrateIdMapInterface;
use Drupal\migrate\Plugin\migrate\destination\Entity;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Row;

/**
 * Saves every revision of an entity, with its translations, in order.
 *
 * Each row is one revision in one language, keyed by entity ID, revision ID
 * and langcode, and sorted by revision ID, original language first (see the
 * bebbo_content_entity source). The IDs of the source are kept:
 * - the first row of an entity creates it;
 * - the first row of a new revision copies the latest saved revision into a
 *   new revision with the source revision ID, as Drupal does when an editor
 *   saves, and drops the translations the source revision does not have;
 * - the next rows of the same revision add or update its translations.
 *
 * The source decides whether a revision is the default one (revision_default)
 * and which translations it affected (revision_translation_affected), so the
 * revision history, the current revision and the content moderation states
 * end up as on the source. Saves are flagged as syncing: Drupal and contrib
 * modules leave changed times, publishing status and computed fields alone.
 *
 * This replaces core's entity_complete destination, deprecated in Drupal
 * 11.3 without a replacement.
 *
 * Available configuration keys:
 * - translations: Must be TRUE.
 *
 * Example:
 *
 * @code
 * destination:
 *   plugin: 'bebbo_entity_complete:node'
 *   translations: true
 * @endcode
 *
 * @see \Drupal\bebbo_content_migrate\Plugin\migrate\source\BebboContentEntity
 */
#[MigrateDestination(
  id: 'bebbo_entity_complete',
  deriver: BebboEntityCompleteDeriver::class,
)]
class BebboEntityComplete extends EntityContentBase {

  /**
   * {@inheritdoc}
   */
  public function getIds() {
    $ids = [];
    foreach (['id', 'revision', 'langcode'] as $key) {
      $name = $this->getKey($key);
      $ids[$name] = $this->getDefinitionFromEntity($name);
    }
    return $ids;
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    /** @var \Drupal\Core\Entity\RevisionableStorageInterface $storage */
    $storage = $this->storage;
    $revision_id = $old_destination_id_values[1] ?? $row->getDestinationProperty($this->getKey('revision'));
    $entity_id = $row->getDestinationProperty($this->getKey('id'));

    // A further language of a revision saved by an earlier row, or a row
    // imported again (--update). The revision keeps the default flag Drupal
    // loaded it with: Bebbo flags every revision that was the default when it
    // was saved, and flagging an older one again would make Drupal copy its
    // values to the current values of the entity.
    $existing = FALSE;
    if ($revision_id && ($entity = $storage->loadRevision($revision_id))) {
      $entity->setNewRevision(FALSE);
      $existing = TRUE;
    }
    // A new revision of a saved entity: start from the latest revision, as
    // an editor would, and keep the source's revision ID.
    elseif ($entity_id && ($latest_id = $storage->getLatestRevisionId($entity_id)) && ($entity = $storage->loadRevision($latest_id))) {
      $entity->enforceIsNew(FALSE);
      $entity->setNewRevision(TRUE);
      $langcodes = $row->getSourceProperty('revision_langcodes') ?: [];
      foreach (array_keys($entity->getTranslationLanguages(FALSE)) as $langcode) {
        if (!in_array($langcode, $langcodes, TRUE)) {
          $entity->removeTranslation($langcode);
        }
      }
      // The original language changed in this revision (node 61421: en, then
      // uk on Bebbo, which becomes uz-ru here): change the language of the
      // original, as Drupal does when an editor changes it.
      $langcode = $row->getSourceProperty('target_langcode');
      if ($row->getSourceProperty('target_default_langcode') && $entity->language()->getId() !== $langcode && !$entity->hasTranslation($langcode)) {
        $entity->set($this->getKey('langcode'), $langcode);
      }
    }
    // The first revision of the entity.
    else {
      if ($bundle = $this->getBundle($row)) {
        $row->setDestinationProperty($this->getKey('bundle'), $bundle);
      }
      $entity = $storage->create($row->getDestination());
      $entity->enforceIsNew();
    }

    // Flag the entity as syncing before the row values are set: otherwise
    // setting moderation_state makes content_moderation recompute the default
    // revision flag and the publishing status (a draft translation added to
    // an existing default revision would make it non-default, which Drupal
    // refuses to save).
    $entity->setSyncing(TRUE);
    $default = $entity->isDefaultRevision();
    $entity = $this->updateEntity($entity, $row);
    $entity->isDefaultRevision($existing ? $default : (bool) $row->getDestinationProperty('revision_default'));
    if ($entity instanceof ContentEntityInterface && $row->hasDestinationProperty('revision_translation_affected')) {
      $entity->setRevisionTranslationAffectedEnforced(TRUE);
      $entity->setRevisionTranslationAffected($row->getDestinationProperty('revision_translation_affected'));
    }
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function updateEntity(EntityInterface $entity, Row $row) {
    $entity = parent::updateEntity($entity, $row);
    // Rolling back any row deletes the whole entity.
    $this->setRollbackAction($row->getIdMap(), MigrateIdMapInterface::ROLLBACK_DELETE);
    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    parent::save($entity, $old_destination_id_values);
    // import() adds the langcode.
    return [
      $entity->id(),
      $entity->getRevisionId(),
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function rollback(array $destination_identifier) {
    // Delete the entity with all its revisions and translations.
    Entity::rollback($destination_identifier);
  }

}
