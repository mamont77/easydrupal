<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding dependencies on deprecated and obsolete extensions.
 *
 * @group upgrade_status
 * @coversDefaultClass \Drupal\upgrade_status\ExtensionMetadataDeprecationAnalyzer
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class DeprecatedDependencyTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
  ];

  /**
   * Tests which dependencies, base themes and requirements are reported.
   *
   * @covers ::analyze
   */
  public function testAnalyze(): void {
    $module_list = $this->container->get('extension.list.module');
    $extension = $module_list->get('upgrade_status_test_dependencies');
    $path = $extension->getPath();
    $link = 'https://drupal.org/project/upgrade_status';

    $expected = [
      "$path/upgrade_status_test_dependencies.info.yml:8 This extension depends on the upgrade_status_test_deprecated module, which is deprecated. See $link.",
      "$path/upgrade_status_test_dependencies.info.yml:9 This extension depends on the upgrade_status_test_obsolete module, which is obsolete. See $link.",
      "$path/tests/themes/upgrade_status_test_dependencies_theme/upgrade_status_test_dependencies_theme.info.yml:5 The base theme is the upgrade_status_test_deprecated_theme theme, which is deprecated. See $link.",
      "$path/composer.json:8 The drupal/upgrade_status_test_deprecated package is required, and it provides the upgrade_status_test_deprecated module, which is deprecated. See $link.",
    ];
    // Drupal 11 deprecates the Contact module, and Drupal 12 removes it.
    if ($module_list->exists('contact') && ($module_list->get('contact')->info['lifecycle'] ?? '') === 'deprecated') {
      $expected[] = "$path/upgrade_status_test_dependencies.info.yml:10 This extension depends on the contact module, which is deprecated in Drupal core. Use the contributed project that replaces it, if there is one, and require it in composer.json. See " . $module_list->get('contact')->info['lifecycle_link'] . '.';
    }

    $deprecations = array_values(array_filter(
      $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer')->analyze($extension),
      static fn (DeprecationMessage $message): bool => (bool) preg_match('/^(This extension depends on|The base theme is|The \S+ package is required, and)/', $message->getMessage()),
    ));
    $this->assertEqualsCanonicalizing(
      $expected,
      array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations),
    );
  }

}
