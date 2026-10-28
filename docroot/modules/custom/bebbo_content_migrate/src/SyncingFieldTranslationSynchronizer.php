<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\content_translation\FieldTranslationSynchronizerInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Field\FieldDefinitionInterface;

/**
 * Leaves the translations of a synced entity as they are.
 *
 * Before an entity is saved, content_translation copies the untranslatable
 * columns of fields (such as the file of an image field) from the source
 * language of a new translation to the other translations. A synced entity,
 * such as one saved by a migration, already has those values in sync. Its
 * source language may also not be a translation of the entity (Bebbo
 * content has 'und' there), which makes the synchronizer throw.
 */
final class SyncingFieldTranslationSynchronizer implements FieldTranslationSynchronizerInterface {

  public function __construct(
    private readonly FieldTranslationSynchronizerInterface $inner,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function synchronizeFields(ContentEntityInterface $entity, $sync_langcode, $original_langcode = NULL) {
    if ($entity->isSyncing()) {
      return;
    }
    $this->inner->synchronizeFields($entity, $sync_langcode, $original_langcode);
  }

  /**
   * {@inheritdoc}
   */
  public function synchronizeItems(array &$field_values, array $unchanged_items, $sync_langcode, array $translations, array $properties) {
    $this->inner->synchronizeItems($field_values, $unchanged_items, $sync_langcode, $translations, $properties);
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldSynchronizedProperties(FieldDefinitionInterface $field_definition) {
    return $this->inner->getFieldSynchronizedProperties($field_definition);
  }

}
