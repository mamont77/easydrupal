<?php

namespace Drupal\upgrade_status_test_deprecated_hook\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for testing.
 */
class DeprecatedHooks {

  /**
   * Implements hook_deprecated_hook_old().
   */
  #[Hook('deprecated_hook_old')]
  public function old(): void {
  }

  /**
   * Implements hook_deprecated_hook_old() with a named argument.
   */
  #[Hook(hook: 'deprecated_hook_old')]
  public function named(): void {
  }

  /**
   * Implements hook_deprecated_hook_new().
   */
  #[Hook('deprecated_hook_new')]
  public function new(): void {
  }

}
