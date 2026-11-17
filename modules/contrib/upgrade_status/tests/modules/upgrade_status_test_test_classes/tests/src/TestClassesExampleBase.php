<?php

declare(strict_types=1);

namespace Drupal\Tests\upgrade_status_test_test_classes;

use PHPUnit\Framework\TestCase;

/**
 * Calls an undefined function, which PHPStan reports if it checks tests.
 */
abstract class TestClassesExampleBase extends TestCase {

  /**
   * Calls the undefined function.
   */
  public function callUndefined(): void {
    upgrade_status_test_undefined_function();
  }

}
