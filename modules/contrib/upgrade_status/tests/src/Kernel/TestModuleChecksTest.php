<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Extension\Extension;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the checks on test modules and themes in tests directories.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class TestModuleChecksTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_deprecated_hook',
    'upgrade_status_test_library',
    'upgrade_status_test_test_modules',
    'user',
  ];

  /**
   * Tests the hook checks on test modules and themes.
   */
  public function testHooks(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_test_modules');
    $old = 'implements hook_deprecated_hook_old(). Deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. Use hook_deprecated_hook_new() instead.';
    $helper = 'upgrade_status_test_test_modules_helper';
    $nested = 'upgrade_status_test_test_modules_nested';
    $theme = 'upgrade_status_test_test_modules_theme';
    $helper_path = "tests/modules/$helper";

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // The hook in the include file of the theme is not reported, because themes
    // load their include files themselves. The hook in the submodule of the
    // test module is only reported once.
    $expected = [
      ["{$helper}_deprecated_hook_old() $old", "$helper_path/$helper.module", 11],
      [
        "The theme-settings.php file of the $theme theme is used. Deprecated in drupal:11.5.0 and is removed from drupal:12.0.0. Drupal 12 doesn't load the hook implementations in this file, so the hooks don't run. Move the implementations to the $theme.theme file, or to a class with the Hook attribute. See https://www.drupal.org/node/3587273.",
        "tests/themes/$theme/theme-settings.php",
        1,
      ],
      ["The Hook attribute $old", "$helper_path/src/Hook/HelperHooks.php", 15],
      ["{$nested}_deprecated_hook_old() $old", "$helper_path/modules/$nested/$nested.module", 11],
      ["{$theme}_deprecated_hook_old() $old", "tests/themes/$theme/$theme.theme", 11],
      [
        "{$helper}_form_alter() in an include file may be an implementation of hook_form_alter(). Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 only loads procedural hooks from .module, .profile and .install files, so this hook is ignored. Move the function to the .module file or to a class with Hook attributes. See https://www.drupal.org/node/3489765.",
        "$helper_path/$helper.inc",
        11,
      ],
    ];
    // Code in .module and .theme files is reported from Drupal 11, where Drupal
    // 13 is a later major version than the next one. The .theme file of the
    // converted theme has the ExtensionFileIsConverted attribute, so it is not
    // reported.
    if (ProjectCollector::getDrupalCoreMajorVersion() >= 11) {
      $convert = 'Deprecated in drupal:11.5.0 and is removed from drupal:13.0.0. Move the code to classes, like hook implementations to classes with the Hook attribute.';
      $legacy = 'Functions that are still needed for older Drupal versions can stay with the LegacyHook attribute. See https://www.drupal.org/node/3619765.';
      $expected[] = [
        "Code in the $theme.theme file is used. $convert See https://www.drupal.org/node/3581222.",
        "tests/themes/$theme/$theme.theme",
        11,
      ];
      $expected[] = ["Code in the $helper.module file is used. $convert $legacy", "$helper_path/$helper.module", 11];
      $expected[] = [
        "Code in the $nested.module file is used. $convert $legacy",
        "$helper_path/modules/$nested/$nested.module",
        11,
      ];
    }
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $extension->getPath() . "/$file", $line, 'HookDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Tests the library checks on test modules and themes.
   */
  public function testLibraries(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_test_modules');
    $helper = 'upgrade_status_test_test_modules_helper';
    $theme = 'upgrade_status_test_test_modules_theme';
    $library_file = $extension->getPath() . "/tests/modules/$helper/$helper.libraries.yml";
    $info_file = $extension->getPath() . "/tests/themes/$theme/$theme.info.yml";
    $deprecated = 'The "upgrade_status_test_library/deprecated_library" asset library is deprecated for testing.';
    $invalid = 'The invalid_library_without_extension library does not have an extension name and is therefore not valid.';

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Dependencies on libraries that are not deprecated or from an extension
    // that is not on the site are not reported.
    $expected = [
      ["The 'uses_deprecated' library is depending on a deprecated library. $deprecated", $library_file, 1],
      [$invalid, $library_file, 0],
      ["Theme is overriding a deprecated library. $deprecated", $info_file, 0],
      ["Theme is extending a deprecated library. $deprecated", $info_file, 0],
      ["Theme is adding a deprecated library to every page. $deprecated", $info_file, 15],
    ];
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $file, $line, 'LibraryDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Tests the library checks on regular submodules and subthemes.
   */
  public function testSubExtensions(): void {
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
   * Tests the libraries that a theme adds to every page.
   */
  public function testThemeLibraries(): void {
    $theme = $this->container->get('extension.list.theme')->get('upgrade_status_test_sub_libraries_theme');
    $info_file = $theme->getPathname();
    $deprecated = 'The "upgrade_status_test_library/deprecated_library" asset library is deprecated for testing.';

    $sut = $this->container->get('upgrade_status.library_deprecation_analyzer');
    $deprecations = $sut->analyze($theme);

    // The library that is not deprecated and the comment are not reported.
    $expected = [
      new DeprecationMessage("Theme is adding a deprecated library to every page. $deprecated", $info_file, 14, 'LibraryDeprecationAnalyzer'),
      new DeprecationMessage("The 'upgrade_status_test_missing/library' library is not defined because the defining extension is not installed. Whether the library is deprecated cannot be checked.", $info_file, 15, 'LibraryDeprecationAnalyzer'),
    ];
    $this->assertEqualsCanonicalizing($expected, array_values(array_filter($deprecations, static fn (DeprecationMessage $message): bool => $message->getFile() === $info_file)));
  }

  /**
   * Tests the info file check on test modules.
   */
  public function testInfoFiles(): void {
    // Core does not find fixtures, and the analyzer treats our own test modules
    // differently, so this fixture is not an installable test module.
    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/info_files';
    $extension = new Extension($this->root, 'module', "$path/info_files.info.yml");

    $sut = $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // A compatible value is fine, and test modules can leave out the key. Other
    // keys of test modules are not checked. The expected messages come from
    // the values in the files, because the CI job for the next major version
    // of Drupal core rewrites them all to that version.
    $expected = [];
    $files = ["$path/info_files.info.yml"];
    $modules = [
      'info_files_compatible',
      'info_files_incompatible',
      'info_files_no_requirement',
      'info_files_other_package',
    ];
    foreach ($modules as $module) {
      $files[] = "$path/tests/modules/$module/$module.info.yml";
    }
    foreach ($files as $file) {
      $lines = file($this->root . '/' . $file);
      foreach ($lines as $index => $line) {
        if (preg_match('/^core_version_requirement:\s*(.+?)\s*$/', $line, $matches)) {
          $value = trim($matches[1], "'\"");
          if (!ProjectCollector::isCompatibleWithNextMajorDrupal($value)) {
            $expected[] = new DeprecationMessage(
              "Value of core_version_requirement: $value is not compatible with the next major version of Drupal core. See https://drupal.org/node/3070687.",
              $file,
              $index + 1,
              'ExtensionMetadataDeprecationAnalyzer'
            );
          }
        }
      }
    }
    // Some fixtures are always incompatible, so the check is always tested.
    $this->assertNotEmpty($expected);
    $this->assertEqualsCanonicalizing($expected, $deprecations, var_export($deprecations, TRUE));
  }

}
