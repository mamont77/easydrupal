<?php

declare(strict_types=1);

namespace Drupal\upgrade_status_test_test_classes;

/**
 * Calls an undefined function, which PHPStan reports.
 */
final class TestClassesHelper {

  /**
   * Calls the undefined function.
   */
  public function call(): void {
    upgrade_status_test_undefined_function();
  }

}
