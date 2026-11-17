<?php

namespace Drupal\upgrade_status_test_test_modules_helper\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for testing.
 */
class HelperHooks {

  /**
   * Implements hook_deprecated_hook_old().
   */
  #[Hook('deprecated_hook_old')]
  public function old(): void {
  }

}
