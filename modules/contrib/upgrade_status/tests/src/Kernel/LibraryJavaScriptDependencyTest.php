<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding libraries with missing core library dependencies.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class LibraryJavaScriptDependencyTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = ['upgrade_status'];

  /**
   * Tests the messages, for a module that is not installed.
   */
  public function testMissingDependencies(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_library_js');
    $path = $extension->getPath();
    $file = "$path/upgrade_status_test_library_js.libraries.yml";
    $sub = 'upgrade_status_test_library_js_sub';

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = array_filter(
      $sut->analyze($extension),
      static fn (DeprecationMessage $message): bool => str_contains($message->getMessage(), 'in JavaScript'),
    );

    $expected = [
      ['upgrade_status_test_library_js/missing_all', 'jQuery', 'core/jquery', $file, 2],
      ['upgrade_status_test_library_js/missing_all', 'Drupal', 'core/drupal', $file, 2],
      ['upgrade_status_test_library_js/missing_all', 'drupalSettings', 'core/drupalSettings', $file, 2],
      ['upgrade_status_test_library_js/missing_all', 'once', 'core/once', $file, 2],
      ['upgrade_status_test_library_js/missing_core_library', 'Drupal', 'core/drupal', $file, 31],
      ['upgrade_status_test_library_js/minified', 'jQuery', 'core/jquery', $file, 36],
      ["$sub/in_submodule", 'Drupal', 'core/drupal', "$path/modules/$sub/$sub.libraries.yml", 1],
    ];
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$library, $global, $core_library, $library_file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage(
        "The '$library' library uses $global in JavaScript, but does not depend on $core_library. Add $core_library to the dependencies of the library. Without the dependency, the script can fail on pages where no other library loads $core_library.",
        $library_file,
        $line,
        'LibraryDeprecationAnalyzer'
      ), $deprecations);
    }
  }

  /**
   * Tests that a module without these problems is not reported.
   */
  public function testNoMissingDependencies(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_13_compatible');

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $messages = array_map(static fn (DeprecationMessage $message): string => $message->getMessage(), $sut->analyze($extension));
    $this->assertSame([], array_filter($messages, static fn (string $message): bool => str_contains($message, 'in JavaScript')));
  }

  /**
   * Tests finding scripts with a negative weight that use core libraries.
   */
  public function testNegativeWeights(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_library_js');

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = array_filter(
      $sut->analyze($extension),
      static fn (DeprecationMessage $message): bool => str_contains($message->getMessage(), 'negative weight'),
    );

    // Scripts that do not use core libraries, or with no code to read, are not
    // reported.
    $this->assertEquals([
      new DeprecationMessage(
        "The 'upgrade_status_test_library_js/negative_weight' library sets a negative weight for js/uses_all.js, which uses jQuery, Drupal, drupalSettings, once. Core libraries have no weights from drupal:11.5.0, so this script loads before the core libraries and can fail, or the changes of the script to Drupal can be lost. Remove the weight, and use the before or after keys of the library if the order still matters. See https://www.drupal.org/node/3467489.",
        $extension->getPath() . '/upgrade_status_test_library_js.libraries.yml',
        49,
        'LibraryDeprecationAnalyzer'
      ),
    ], array_values($deprecations));
  }

}
