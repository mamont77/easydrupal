<?php

declare(strict_types=1);

namespace Drupal\upgrade_status_test_session;

/**
 * Stores values in $_SESSION.
 */
final class SessionStorage {

  /**
   * Stores a value with a key from a constant, and in a look-alike property.
   */
  public function store(int $id): \stdClass {
    $_SESSION[self::class][$id] = $id;
    $object = new \stdClass();
    $object->_SESSION['upgrade_status_test'] = $id;
    return $object;
  }

}
