<?php

namespace Drupal\upgrade_status_test_plugin_definitions\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * A style plugin with a theme_file key, which Views doesn't use.
 *
 * @ViewsStyle(
 *   id = "upgrade_status_test_unregistered",
 *   title = @Translation("Unregistered"),
 *   theme = "upgrade_status_test_unregistered",
 *   theme_file = "upgrade_status_test_plugin_definitions.theme.inc",
 *   register_theme = FALSE,
 *   display_types = {"normal"}
 * )
 */
class UnregisteredThemeFileStyle extends StylePluginBase {

}
