<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Hook;

use Drupal\bebbo_content_migrate\SyncingMediaStorage;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\media\MediaStorage;

/**
 * Entity type hook implementations for Bebbo content migrate.
 */
final class EntityTypeHooks {

  /**
   * Implements hook_entity_type_alter().
   *
   * Media items saved by the migration keep their metadata and thumbnail.
   *
   * @see \Drupal\bebbo_content_migrate\SyncingMediaStorage
   */
  #[Hook('entity_type_alter')]
  public function entityTypeAlter(array &$entity_types): void {
    /** @var \Drupal\Core\Entity\EntityTypeInterface[] $entity_types */
    if (isset($entity_types['media']) && $entity_types['media']->getStorageClass() === MediaStorage::class) {
      $entity_types['media']->setStorageClass(SyncingMediaStorage::class);
    }
  }

}
