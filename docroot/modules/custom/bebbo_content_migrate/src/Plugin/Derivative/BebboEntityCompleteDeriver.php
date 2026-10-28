<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate\Plugin\Derivative;

use Drupal\Component\Plugin\Derivative\DeriverBase;
use Drupal\Core\Entity\ContentEntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\Discovery\ContainerDeriverInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Derives a bebbo_entity_complete destination per revisionable entity type.
 */
final class BebboEntityCompleteDeriver extends DeriverBase implements ContainerDeriverInterface {

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, $base_plugin_id) {
    return new self($container->get('entity_type.manager'));
  }

  /**
   * {@inheritdoc}
   */
  public function getDerivativeDefinitions($base_plugin_definition) {
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if ($entity_type instanceof ContentEntityTypeInterface && $entity_type->isRevisionable()) {
        $this->derivatives[$entity_type_id] = [
          'id' => "bebbo_entity_complete:$entity_type_id",
          'provider' => $entity_type->getProvider(),
        ] + $base_plugin_definition;
      }
    }
    return $this->derivatives;
  }

}
