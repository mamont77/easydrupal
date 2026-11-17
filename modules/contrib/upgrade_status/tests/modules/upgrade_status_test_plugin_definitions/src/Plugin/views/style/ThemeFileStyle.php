<?php

namespace Drupal\upgrade_status_test_plugin_definitions\Plugin\views\style;

use Drupal\views\Plugin\views\style\StylePluginBase;

/**
 * A style plugin with a theme_file key.
 *
 * @ViewsStyle(
 *   id = "upgrade_status_test_theme_file",
 *   title = @Translation("Theme file"),
 *   theme = "upgrade_status_test_style",
 *   theme_file = "upgrade_status_test_plugin_definitions.theme.inc",
 *   display_types = {"normal"}
 * )
 */
class ThemeFileStyle extends StylePluginBase {

}
