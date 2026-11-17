<?php

declare(strict_types=1);

namespace Drupal\upgrade_status_test_templates\Hook;

use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for upgrade status module testing.
 */
final class TemplateHooks {

  /**
   * Implements hook_theme_suggestions_HOOK_alter().
   */
  #[Hook('theme_suggestions_upgrade_status_test_deprecated_alter')]
  public function suggestionsAlter(array &$suggestions): void {
  }

}
