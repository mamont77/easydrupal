<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Extension\Extension;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the checks of info.yml files.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ExtensionMetadataDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * The message about a missing core_version_requirement.
   */
  private const MISSING_REQUIREMENT = 'Add core_version_requirement to designate which Drupal versions is the extension compatible with. See https://drupal.org/node/3070687.';

  /**
   * Tests the core_version_requirement checks.
   */
  public function testCoreVersionRequirement(): void {
    $missing = self::MISSING_REQUIREMENT;
    $this->assertSame(["upgrade_status_test_error.info.yml:1 $missing"], $this->getMessages('upgrade_status_test_error'));
    $this->assertSame(["upgrade_status_test_fatal.info.yml:1 $missing"], $this->getMessages('upgrade_status_test_fatal'));

    // The info.yml files of submodules are checked with the project.
    $this->assertEqualsCanonicalizing([
      "upgrade_status_test_submodules_with_error.info.yml:1 $missing",
      "modules/upgrade_status_test_submodules_with_error_a/upgrade_status_test_submodules_with_error_a.info.yml:1 $missing",
    ], $this->getMessages('upgrade_status_test_submodules_with_error'));

    // A requirement that doesn't allow the next major version is reported on
    // the line of the key.
    $next_major = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    foreach ([
      'upgrade_status_test_12_compatible' => ['^10 || ^11 || ^12', 5, 12],
      'upgrade_status_test_contrib_12_compatible' => ['^10.1 || ^11 || ^12', 7, 12],
      'upgrade_status_test_13_compatible' => ['^10 || ^11 || ^12 || ^13', 5, 13],
    ] as $name => [$requirement, $line, $compatible_major]) {
      $expected = $next_major <= $compatible_major ? [] : [
        "$name.info.yml:$line Value of core_version_requirement: $requirement is not compatible with the next major version of Drupal core. See https://drupal.org/node/3070687.",
      ];
      $this->assertSame($expected, $this->getMessages($name), $name);
    }
  }

  /**
   * Tests requirements in composer.json that are not version constraints.
   */
  public function testInvalidComposerRequirements(): void {
    // The directory only has a composer.json file, so no project is added to
    // the codebase for other tests. An extension is made from a file that
    // exists, and only the directory of the file is used.
    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/invalid_requirements';
    $extension = new Extension($this->root, 'module', "$path/composer.json");
    $deprecations = $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer')->analyze($extension);
    $messages = array_map(static fn ($deprecation): string => basename($deprecation->getFile()) . ':' . $deprecation->getLine() . ' ' . $deprecation->getMessage(), $deprecations);
    $this->assertSame([
      'composer.json:1 The drupal/core requirement in composer.json is not a valid version constraint. See https://www.drupal.org/docs/develop/using-composer/add-a-composerjson-file#core-compatibility.',
      'composer.json:1 The PHP requirement in composer.json is not a valid version constraint.',
    ], $messages);
  }

  /**
   * Tests the message for a deprecated extension.
   */
  public function testDeprecatedExtension(): void {
    $this->assertEqualsCanonicalizing([
      'upgrade_status_test_deprecated.info.yml:1 ' . self::MISSING_REQUIREMENT,
      "upgrade_status_test_deprecated.info.yml:5 This extension is deprecated. Don't use it. See https://drupal.org/project/upgrade_status.",
    ], $this->getMessages('upgrade_status_test_deprecated'));
  }

  /**
   * Analyzes a module.
   *
   * @param string $name
   *   The name of the module.
   *
   * @return string[]
   *   The messages as "file:line message", with the file relative to the
   *   module.
   */
  protected function getMessages(string $name): array {
    $extension = $this->container->get('extension.list.module')->get($name);
    $deprecations = $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer')->analyze($extension);
    return array_map(
      static fn ($deprecation): string => str_replace($extension->getPath() . '/', '', $deprecation->getFile()) . ':' . $deprecation->getLine() . ' ' . $deprecation->getMessage(),
      $deprecations,
    );
  }

}
