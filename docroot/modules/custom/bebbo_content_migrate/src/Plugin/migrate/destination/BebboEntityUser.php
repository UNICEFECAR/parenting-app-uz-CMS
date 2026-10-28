<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Plugin\migrate\destination;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\migrate\Attribute\MigrateDestination;
use Drupal\migrate\Plugin\migrate\destination\EntityContentBase;
use Drupal\migrate\Row;

/**
 * Saves users as they are on the source, users 0 and 1 included.
 *
 * Core's entity:user destination keeps this site's password for user 1, and
 * cannot update user 0. Here they are updated like every other user, so they
 * end up as on Bebbo (UUID, name, mail, password hash, roles, ...). Passwords
 * are copied as hashes.
 *
 * Example:
 *
 * @code
 * destination:
 *   plugin: bebbo_entity_user
 * @endcode
 */
#[MigrateDestination('bebbo_entity_user')]
class BebboEntityUser extends EntityContentBase {

  /**
   * {@inheritdoc}
   */
  protected static function getEntityTypeId($plugin_id) {
    return 'user';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEntity(Row $row, array $old_destination_id_values) {
    // The anonymous user (0) exists on every site: update it. The parent
    // treats ID 0 as no ID and would create a new user.
    if ((string) $row->getDestinationProperty('uid') === '0' && ($entity = $this->storage->load(0))) {
      return $this->updateEntity($entity, $row) ?: $entity;
    }
    return parent::getEntity($row, $old_destination_id_values);
  }

  /**
   * {@inheritdoc}
   */
  protected function save(ContentEntityInterface $entity, array $old_destination_id_values = []) {
    // The source password is already hashed.
    // @see \Drupal\Core\Field\Plugin\Field\FieldType\PasswordItem::preSave()
    $entity->pass->pre_hashed = TRUE;
    return parent::save($entity, $old_destination_id_values);
  }

}
