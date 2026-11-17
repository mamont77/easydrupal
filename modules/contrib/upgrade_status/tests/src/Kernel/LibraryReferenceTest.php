<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests libraries of extensions that are not installed.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class LibraryReferenceTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_library_references',
  ];

  /**
   * Tests the messages when the extension of the library is not installed.
   */
  public function testNotInstalled(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('upgrade_status_test_library'));
    $this->assertFalse($this->container->get('module_handler')->moduleExists('image'));

    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_library_references');
    $name = 'upgrade_status_test_library_references';
    $path = $extension->getPath();
    $deprecated = 'The "upgrade_status_test_library/deprecated_library" asset library is deprecated for testing.';

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Deprecated libraries are reported, also from core. Libraries that are not
    // deprecated are not, and neither are strings that are not libraries, like
    // image/png.
    $expected = [
      [
        "The 'uses_deprecated' library is depending on a deprecated library. $deprecated",
        "$path/$name.libraries.yml",
        0,
      ],
      [
        "The 'no_such_extension/library' library (a dependency of 'uses_not_found') is not defined because the defining extension is not installed. Whether the library is deprecated cannot be checked.",
        "$path/$name.libraries.yml",
        0,
      ],
      [
        "Template is attaching a deprecated library. $deprecated",
        "$path/templates/upgrade-status-test-library-references.html.twig",
        1,
      ],
      ["The referenced library is deprecated. $deprecated", "$path/$name.module", 13],
      [
        'The referenced library is deprecated. The "core/once" asset library is deprecated for testing.',
        "$path/$name.module",
        18,
      ],
    ];
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $message_file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $message_file, $line, 'LibraryDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Tests submodules and subthemes using a library that is not installed.
   */
  public function testSubExtensions(): void {
    $this->assertFalse($this->container->get('module_handler')->moduleExists('upgrade_status_test_library'));
    $this->enableModules(['upgrade_status_test_sub_libraries']);

    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_sub_libraries');
    $module = 'upgrade_status_test_sub_libraries_module';
    $theme = 'upgrade_status_test_sub_libraries_theme';
    $library_file = $extension->getPath() . "/modules/$module/$module.libraries.yml";
    $info_file = $extension->getPath() . "/themes/$theme/$theme.info.yml";
    $deprecated = 'The "upgrade_status_test_library/deprecated_library" asset library is deprecated for testing.';

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    $expected = [
      ["The 'uses_deprecated' library is depending on a deprecated library. $deprecated", $library_file, 1],
      ["Theme is overriding a deprecated library. $deprecated", $info_file, 0],
      ["Theme is extending a deprecated library. $deprecated", $info_file, 0],
      ["Theme is adding a deprecated library to every page. $deprecated", $info_file, 14],
    ];
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $file, $line, 'LibraryDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Tests a libraries.yml file that core can't parse.
   */
  public function testIncompleteLibrary(): void {
    $this->enableModules(['upgrade_status_test_library_exception']);
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_library_exception');

    $deprecations = $this->container->get('upgrade_status.library_deprecation_analyzer')->analyze($extension);

    $this->assertEquals([
      new DeprecationMessage("Incomplete library definition for definition 'library_exception' in extension 'upgrade_status_test_library_exception'", $extension->getPath(), 0, 'LibraryDeprecationAnalyzer'),
    ], $deprecations);
  }

  /**
   * Tests overrides of library files that moved.
   */
  public function testMovedFileOverrides(): void {
    $module = $this->container->get('extension.list.module')->get('upgrade_status_test_moved_files');
    $theme = $this->container->get('extension.list.theme')->get('upgrade_status_test_moved_files_theme');
    $info_file = $theme->getPathname();
    $this->assertSame($module->getPath() . '/themes/upgrade_status_test_moved_files_theme/upgrade_status_test_moved_files_theme.info.yml', $info_file);

    // Overrides of files that did not move, of files in the new location and
    // the libraries-extend entry are not reported.
    $fixture = 'Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 ignores this override, because the file moved.';
    $expected = [
      "$info_file:11 The libraries-override of upgrade_status_test_moved_files/old_location targets css/old.css in the component category. $fixture Override css/new.css in the base category of upgrade_status_test_moved_files/new_location instead. See https://www.drupal.org/node/3432346.",
      "$info_file:14 The libraries-override of upgrade_status_test_moved_files/old_location targets js/old.js. $fixture Override js/new.js in upgrade_status_test_moved_files/new_location instead. See https://www.drupal.org/node/3432346.",
    ];
    // Core lists the files that moved out of system/base in Drupal 11.2 and
    // 11.3 until Drupal 12, and the ones that moved in Drupal 11.5 until
    // Drupal 13. The moved_files entry of core/drupal.fieldgroup names the base
    // category and a core/ prefix, but the library has the file in the
    // component category without the prefix.
    [$major, $minor] = array_map('intval', explode('.', \Drupal::VERSION));
    if ($major === 11 && $minor >= 2) {
      $expected[] = "$info_file:24 The libraries-override of system/base targets css/components/fieldgroup.module.css in the component category. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 ignores this override, because the file moved. Override misc/components/fieldgroup.module.css in the component category of core/drupal.fieldgroup instead. See https://www.drupal.org/node/3432346.";
    }
    if (($major === 11 && $minor >= 5) || $major === 12) {
      $expected[] = "$info_file:25 The libraries-override of system/base targets css/components/align.module.css in the component category. Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0. Drupal 13 ignores this override, because the file moved. Override css/components/align.module.css in the base category of system/base instead. See https://www.drupal.org/project/drupal/issues/3619507.";
    }

    // The theme is checked when it is analyzed, and as a subtheme when the
    // module is analyzed.
    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    foreach ([$theme, $module] as $extension) {
      $actual = [];
      foreach ($sut->analyze($extension) as $message) {
        if ($message->getFile() === $info_file) {
          $actual[] = $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage();
        }
      }
      $this->assertEqualsCanonicalizing($expected, $actual, $extension->getName());
    }
  }

}
