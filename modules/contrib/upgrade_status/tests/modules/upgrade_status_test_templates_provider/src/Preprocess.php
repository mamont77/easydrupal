<?php

declare(strict_types=1);

namespace Drupal\upgrade_status_test_templates_provider;

/**
 * Initial preprocess callbacks for upgrade status module testing.
 */
final class Preprocess {

  /**
   * Prepares variables for the upgrade_status_test_variables theme hook.
   */
  public function preprocessVariables(array &$variables): void {
    $variables['deprecations']['older'] = "'older' is deprecated in drupal:11.0.0 and is removed in drupal:13.0.0. Use 'new' instead. See https://www.drupal.org/node/3157353";
    $variables['older'] = $variables['old'];
  }

}
