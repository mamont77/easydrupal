<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding deprecated plugin definitions.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class PluginDefinitionDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
  ];

  /**
   * Tests the messages for a module with deprecated plugin definitions.
   *
   * Similar code is not reported: an 'access callback' key outside of Views
   * data, reads of the key, and the text in values and comments, a theme_file
   * key that Views doesn't use, icons with the Icon prefix, SVG icons and
   * icons outside of drupalElementStyles, region labels, and layouts with a
   * label or a deriver.
   */
  public function testPluginDefinitions(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_plugin_definitions');
    $path = $extension->getPath();

    $deprecations = $this->container->get('upgrade_status.plugin_definition_deprecation_analyzer')->analyze($extension);

    $access = "The 'access callback' key is used in a Views handler definition. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Implement the access() method of the handler class instead. See https://www.drupal.org/node/3539918.";
    $icon = 'The %s icon is used in drupalElementStyles of the upgrade_status_test_plugin_definitions_styles CKEditor 5 plugin. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use %s instead. See https://www.drupal.org/node/3528806.';
    $layout = 'The %s layout has no label. Deprecated in drupal:11.4.0 and is removed from drupal:12.0.0. Add a label to the layout definition. See https://www.drupal.org/node/3464076.';
    $this->assertEqualsCanonicalizing(
      [
        "$path/upgrade_status_test_plugin_definitions.views.inc:16 $access",
        "$path/upgrade_status_test_plugin_definitions.views.inc:21 $access",
        "$path/src/Plugin/views/style/ThemeFileStyle.php:14 The upgrade_status_test_theme_file Views plugin has a theme_file key. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Remove the key, and move the preprocess code of the include file to the preprocessUpgradeStatusTestStyle() method of the Drupal\upgrade_status_test_plugin_definitions\Hook\UpgradeStatusTestPluginDefinitionsThemeHooks class. See https://www.drupal.org/node/3548325.",
        "$path/upgrade_status_test_plugin_definitions.ckeditor5.yml:9 " . sprintf($icon, 'objectBlockLeft', 'IconObjectLeft'),
        "$path/upgrade_status_test_plugin_definitions.ckeditor5.yml:22 " . sprintf($icon, 'threeVerticalDots', 'IconThreeVerticalDots'),
        "$path/upgrade_status_test_plugin_definitions.layouts.yml:8 " . sprintf($layout, 'upgrade_status_test_unlabeled'),
        "$path/src/Plugin/Layout/UnlabeledLayout.php:12 " . sprintf($layout, 'upgrade_status_test_unlabeled_attribute'),
        "$path/src/Plugin/Layout/AnnotationLayout.php:10 " . sprintf($layout, 'upgrade_status_test_unlabeled_annotation'),
      ],
      array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations),
    );
    foreach ($deprecations as $deprecation) {
      $this->assertSame('PluginDefinitionDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
  }

}
