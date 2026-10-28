<?php

declare(strict_types=1);

namespace Drupal\bebbo_content_migrate;

use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Database;
use Drupal\Core\State\StateInterface;

/**
 * The connection to the copy of the Bebbo database.
 *
 * The connection info is kept in state, in the format SqlBase reads with its
 * database_state_key option, so the migrations and the Drush commands use the
 * same connection. Nothing is written to the source database.
 *
 * @see \Drupal\migrate\Plugin\migrate\source\SqlBase::getDatabase()
 */
final class SourceDatabase {

  /**
   * The state key holding the connection info.
   */
  public const STATE_KEY = 'bebbo_content_migrate.database';

  /**
   * The connection key the source database is registered under.
   */
  public const CONNECTION_KEY = 'bebbo_content_migrate_source';

  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * Saves the connection info used by the migrations.
   *
   * @param array $database
   *   A connection info array, as in $databases in settings.php.
   */
  public function setInfo(array $database): void {
    $this->state->set(self::STATE_KEY, [
      'key' => self::CONNECTION_KEY,
      'target' => 'default',
      'database' => $database,
    ]);
    // Drop a connection opened with the previous info.
    Database::removeConnection(self::CONNECTION_KEY);
  }

  /**
   * Returns the saved connection info, or NULL when it is not set.
   */
  public function getInfo(): ?array {
    return $this->state->get(self::STATE_KEY);
  }

  /**
   * Opens the connection to the source database.
   *
   * @throws \RuntimeException
   *   When the connection was not configured with
   *   drush bebbo-content-migrate:connect.
   */
  public function connection(): Connection {
    $info = $this->getInfo();
    if (!$info) {
      throw new \RuntimeException('The Bebbo database is not configured. Run drush bebbo-content-migrate:connect first.');
    }
    if (!Database::getConnectionInfo($info['key'])) {
      Database::addConnectionInfo($info['key'], $info['target'], $info['database']);
    }
    return Database::getConnection($info['target'], $info['key']);
  }

}
