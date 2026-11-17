<?php

namespace Drupal\upgrade_status_test_removed_api;

/**
 * Runs a query on its own PDO connection, which is not reported.
 */
final class PdoConnection {

  /**
   * Returns a column of results.
   *
   * @return array
   *   The results.
   */
  public function fetchColumn(): array {
    $pdo = new \PDO('sqlite::memory:');
    return $pdo->query('SELECT 1')->fetchAll(\PDO::FETCH_COLUMN);
  }

}
