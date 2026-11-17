<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the checks on .module, .profile, .theme and .engine files.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ExtensionFilesTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'user',
  ];

  /**
   * Tests the messages for files with code to convert to classes.
   */
  public function testExtensionFiles(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_extension_files');
    $path = $extension->getPath();
    $name = 'upgrade_status_test_extension_files';
    $convert = 'Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0. Move the code to classes, like hook implementations to classes with the Hook attribute.';
    $legacy = 'Functions that are still needed for older Drupal versions can stay with the LegacyHook attribute. See https://www.drupal.org/node/3619765.';

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Drupal 12 doesn't load .engine files.
    $expected = [
      [
        "The {$name}_engine.engine theme engine file is used. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Drupal 12 doesn't load .engine files. Convert {$name}_engine.engine to a service. See https://www.drupal.org/node/3547356.",
        "$path/tests/engines/{$name}_engine/{$name}_engine.engine",
        1,
      ],
    ];
    // Code in .module, .profile and .theme files is reported from Drupal 11,
    // where Drupal 13 is a later major version than the next one. The line
    // is the first function to convert. In
    // .module and .profile files, functions with the LegacyHook attribute can
    // stay, but not in .theme files. The legacy, converted and empty test
    // modules are not reported.
    if (ProjectCollector::getDrupalCoreMajorVersion() >= 11) {
      $expected[] = ["Code in the $name.module file is used. $convert $legacy", "$path/$name.module", 23];
      $expected[] = [
        "Code in the {$name}_profile.profile file is used. $convert $legacy",
        "$path/tests/profiles/{$name}_profile/{$name}_profile.profile",
        13,
      ];
      $expected[] = [
        "Code in the {$name}_theme.theme file is used. $convert See https://www.drupal.org/node/3581222.",
        "$path/tests/themes/{$name}_theme/{$name}_theme.theme",
        16,
      ];
    }
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $file, $line, 'HookDeprecationAnalyzer'), $deprecations);
    }
  }

}
