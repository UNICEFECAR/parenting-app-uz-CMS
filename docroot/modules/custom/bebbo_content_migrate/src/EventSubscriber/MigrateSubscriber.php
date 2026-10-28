<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\EventSubscriber;

use Drupal\bebbo_content_migrate\SourceTables;
use Drupal\bebbo_content_migrate\SourceValueRestorer;
use Drupal\migrate\Event\MigrateEvents;
use Drupal\migrate\Event\MigrateImportEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Restores the source values Drupal changed, after each import.
 *
 * @see \Drupal\bebbo_content_migrate\SourceValueRestorer
 */
final class MigrateSubscriber implements EventSubscriberInterface {

  public function __construct(
    private readonly SourceValueRestorer $restorer,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [MigrateEvents::POST_IMPORT => 'onPostImport'];
  }

  /**
   * Restores the source values of the migration's entity type.
   */
  public function onPostImport(MigrateImportEvent $event): void {
    $source = $event->getMigration()->getSourceConfiguration();
    $entity_type_id = $source['entity_type'] ?? NULL;
    if (($source['plugin'] ?? NULL) !== 'bebbo_content_entity') {
      return;
    }
    if ($entity_type_id === 'user') {
      $event->logMessage('Empty timezones restored: ' . $this->restorer->restoreUserTimezones() . '.');
      return;
    }
    if (!in_array($entity_type_id, SourceTables::REVISIONED, TRUE)) {
      return;
    }
    $counts = array_filter($this->restorer->restore($entity_type_id));
    $message = $counts
      ? implode(', ', array_map(static fn($key, $count) => "$key: $count", array_keys($counts), $counts))
      : 'nothing to restore';
    $event->logMessage("Source values restored for $entity_type_id ($message).");
  }

}
