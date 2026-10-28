<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\Sql\SqlContentEntityStorage;
use Drupal\Core\Entity\SynchronizableInterface;
use Drupal\media\MediaStorage;

/**
 * Media storage that saves synced media items as they are.
 *
 * MediaStorage::save() asks the media source for metadata and a thumbnail
 * before every save of a new item, or of an item whose source field changed.
 * For remote videos that is an oEmbed request to YouTube or Vimeo, and the
 * result replaces the thumbnail of the migrated item (often with a new file
 * entity). A synced item, such as one saved by a migration, already has its
 * metadata and thumbnail, so this storage skips that step for it.
 */
class SyncingMediaStorage extends MediaStorage {

  /**
   * {@inheritdoc}
   */
  public function save(EntityInterface $media) {
    if ($media instanceof SynchronizableInterface && $media->isSyncing()) {
      return SqlContentEntityStorage::save($media);
    }
    return parent::save($media);
  }

}
