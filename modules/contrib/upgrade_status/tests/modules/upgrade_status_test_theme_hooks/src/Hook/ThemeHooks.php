<?php

namespace Drupal\upgrade_status_test_theme_hooks\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for testing.
 */
class ThemeHooks {

  /**
   * Implements hook_theme().
   */
  #[Hook('theme')]
  public function theme(): array {
    return [
      'in_class' => [
        'variables' => [],
        'file' => 'upgrade_status_test_theme_hooks.theme.inc',
      ],
      'in_class_registered' => [
        'variables' => [],
        'initial preprocess' => static::class . ':preprocess',
      ],
    ];
  }

}
