<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding hooks in include files.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class HookDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_broken_api',
    'upgrade_status_test_deprecated_hook',
    'upgrade_status_test_hook_info',
    'user',
  ];

  /**
   * Tests the messages for a module with hooks in include files.
   */
  public function testHooksInIncludeFiles(): void {
    $extension = $this->container->get('module_handler')->getModule('upgrade_status_test_hook_info');
    $path = $extension->getPath();

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $this->withoutExtensionFileMessages($sut->analyze($extension));

    $this->assertCount(6, $deprecations, var_export($deprecations, TRUE));

    // Files in a hook group are reported once, for core's and the module's own
    // groups.
    $this->assertContainsEquals(new DeprecationMessage(
      'Core loads this file through the tokens group of hook_hook_info(). Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Move the functions in this file to the .module file or to a class with Hook attributes. See https://www.drupal.org/node/3489765.',
      $path . '/upgrade_status_test_hook_info.tokens.inc',
      11,
      'HookDeprecationAnalyzer'
    ), $deprecations);
    $this->assertContainsEquals(new DeprecationMessage(
      'Core loads this file through the custom_group group of hook_hook_info(). Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Move the functions in this file to the .module file or to a class with Hook attributes. See https://www.drupal.org/node/3489765.',
      $path . '/upgrade_status_test_hook_info.custom_group.inc',
      11,
      'HookDeprecationAnalyzer'
    ), $deprecations);

    // Other include files are reported per hook.
    foreach ([
      13 => ['upgrade_status_test_hook_info_cron', 'cron'],
      19 => ['upgrade_status_test_hook_info_form_user_login_form_alter', 'form_user_login_form_alter'],
      25 => ['upgrade_status_test_hook_info_preprocess_node', 'preprocess_node'],
      31 => ['upgrade_status_test_hook_info_user_insert', 'user_insert'],
    ] as $line => [$function, $hook]) {
      $this->assertContainsEquals(new DeprecationMessage(
        "$function() in an include file may be an implementation of hook_$hook(). Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 only loads procedural hooks from .module, .profile and .install files, so this hook is ignored. Move the function to the .module file or to a class with Hook attributes. See https://www.drupal.org/node/3489765.",
        $path . '/includes/hooks.inc',
        $line,
        'HookDeprecationAnalyzer'
      ), $deprecations);
    }
  }

  /**
   * Tests that themes are not reported.
   */
  public function testThemeIncludeFiles(): void {
    $this->container->get('theme_installer')->install(['upgrade_status_test_theme']);
    $extension = $this->container->get('extension.list.theme')->get('upgrade_status_test_theme');

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $this->assertSame([], $sut->analyze($extension));
  }

  /**
   * Tests that a module without these problems is not reported.
   */
  public function testNoProblems(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_13_compatible');

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $this->assertSame([], $sut->analyze($extension));
  }

  /**
   * Tests finding hook_module_implements_alter() without a legacy attribute.
   */
  public function testModuleImplementsAlter(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_hook_order');

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $this->withoutExtensionFileMessages($sut->analyze($extension));

    // The functions of the parent module and the test module are reported. The
    // submodule has the attribute, and the other file in tests is not in a
    // module.
    $message = ' implements hook_module_implements_alter() without the LegacyModuleImplementsAlter attribute. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 ignores this function, so hooks can run in another order. Use the order argument of the Hook attribute, and add #[LegacyModuleImplementsAlter] if the function is still needed for older Drupal versions. See https://www.drupal.org/node/3496788.';
    $test_module = 'upgrade_status_test_hook_order_test';
    $this->assertEquals([
      new DeprecationMessage(
        'upgrade_status_test_hook_order_module_implements_alter()' . $message,
        $extension->getPath() . '/upgrade_status_test_hook_order.module',
        11,
        'HookDeprecationAnalyzer'
      ),
      new DeprecationMessage(
        $test_module . '_module_implements_alter()' . $message,
        $extension->getPath() . "/tests/modules/$test_module/$test_module.module",
        11,
        'HookDeprecationAnalyzer'
      ),
    ], $deprecations);
  }

  /**
   * Tests the messages for implementations of deprecated hooks.
   */
  public function testDeprecatedHooks(): void {
    $extension = $this->container->get('module_handler')->getModule('upgrade_status_test_deprecated_hook');
    $path = $extension->getPath();
    $old = 'implements hook_deprecated_hook_old(). Deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. Use hook_deprecated_hook_new() instead.';
    $variable = 'Deprecated in drupal:11.1.0 and is removed from drupal:13.0.0. There is no replacement.';
    $prefix = 'upgrade_status_test_deprecated_hook_deprecated_hook';
    $include_file = 'in an include file may be an implementation of hook_deprecated_hook_other_alter(). Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 only loads procedural hooks from .module, .profile and .install files, so this hook is ignored. Move the function to the .module file or to a class with Hook attributes. See https://www.drupal.org/node/3489765.';

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $this->withoutExtensionFileMessages($sut->analyze($extension));

    $expected = [
      ["{$prefix}_old() $old", 'upgrade_status_test_deprecated_hook.module', 13],
      [
        "{$prefix}_some_alter() implements hook_deprecated_hook_some_alter(). $variable",
        'upgrade_status_test_deprecated_hook.module',
        19,
      ],
      [
        "{$prefix}_other_alter() implements hook_deprecated_hook_other_alter(). $variable",
        'includes/hooks.inc',
        11,
      ],
      ["{$prefix}_other_alter() $include_file", 'includes/hooks.inc', 11],
      ["The Hook attribute $old", 'src/Hook/DeprecatedHooks.php', 15],
      ["The Hook attribute $old", 'src/Hook/DeprecatedHooks.php', 22],
    ];
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, "$path/$file", $line, 'HookDeprecationAnalyzer'), $deprecations);
    }

    // Hooks that core documents as deprecated differ per version. Each one is
    // removed in the next major version, and then it is no longer found.
    $core_hooks = [];
    [$major, $minor] = array_map('intval', explode('.', \Drupal::VERSION));
    if ($major === 10) {
      $core_hooks = ['upgrade_status_test_deprecated_hook_file_validate() implements hook_file_validate(). Deprecated in drupal:10.2.0 and is removed from drupal:11.0.0.'];
    }
    elseif ($major === 11 && $minor >= 3) {
      $core_hooks = ['upgrade_status_test_deprecated_hook_ranking() implements hook_ranking(). Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0.'];
    }
    foreach ($core_hooks as $core_hook) {
      $found = array_filter($deprecations, static fn (DeprecationMessage $message): bool => str_starts_with($message->getMessage(), $core_hook));
      $this->assertCount(1, $found, $core_hook);
    }
    $this->assertCount(count($expected) + count($core_hooks), $deprecations, var_export($deprecations, TRUE));
  }

  /**
   * Tests that a syntax error in a *.api.php file causes no problems.
   */
  public function testBrokenApiFile(): void {
    $extension = $this->container->get('module_handler')->getModule('upgrade_status_test_broken_api');
    $path = $extension->getPath() . '/upgrade_status_test_broken_api.module';
    $deprecated = 'Deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. There is no replacement.';

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $this->withoutExtensionFileMessages($sut->analyze($extension));

    $this->assertCount(2, $deprecations, var_export($deprecations, TRUE));
    $this->assertContainsEquals(new DeprecationMessage(
      "upgrade_status_test_broken_api_broken_api_before() implements hook_broken_api_before(). $deprecated",
      $path,
      11,
      'HookDeprecationAnalyzer'
    ), $deprecations);
    $this->assertContainsEquals(new DeprecationMessage(
      "upgrade_status_test_broken_api_broken_api_after() implements hook_broken_api_after(). $deprecated",
      $path,
      17,
      'HookDeprecationAnalyzer'
    ), $deprecations);
  }

  /**
   * Tests the messages for theme hooks, for a module that is not installed.
   */
  public function testThemeHooks(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_theme_hooks');
    $path = $extension->getPath();
    $module = "$path/upgrade_status_test_theme_hooks.module";
    $include = "$path/upgrade_status_test_theme_hooks.theme.inc";
    $class = "$path/src/Hook/ThemeHooks.php";
    $test_module = "$path/tests/modules/upgrade_status_test_theme_hooks_test/upgrade_status_test_theme_hooks_test.module";
    $file_keys = 'Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Use initial preprocess for template_preprocess instead, and load the file from the .module file if still needed. See https://www.drupal.org/node/3549500.';

    $sut = $this->container->get('upgrade_status.hook_deprecation_analyzer');
    $deprecations = $this->withoutExtensionFileMessages($sut->analyze($extension));

    $expected = [
      ["The assigned_with_file theme hook provides a file with the 'file' key. $file_keys", $module, 16],
      ["The with_includes theme hook provides includes with the 'includes' key. $file_keys", $module, 30],
      ["The old_array_syntax theme hook provides a file with the 'file' key. $file_keys", $module, 36],
      ["The in_class theme hook provides a file with the 'file' key. $file_keys", $class, 20],
      ["The in_test_module theme hook provides a file with the 'file' key. $file_keys", $test_module, 15],
    ];
    $preprocess_functions = [
      'not_registered' => [$module, 44],
      'in_include' => [$include, 11],
      'in_test_module' => [$test_module, 27],
    ];
    foreach ($preprocess_functions as $hook => [$file, $line]) {
      $expected[] = [
        "template_preprocess_$hook() is not set as the initial preprocess of the $hook theme hook. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Add 'initial preprocess' => 'template_preprocess_$hook' to the theme hook. See https://www.drupal.org/node/3504125.",
        $file,
        $line,
      ];
    }
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $file, $line, 'HookDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Removes the messages about code in .module and .theme files.
   *
   * Most test modules have code in their .module files, which is reported on
   * Drupal 12. TestModuleChecksTest and ExtensionFilesTest test these messages.
   *
   * @param \Drupal\upgrade_status\DeprecationMessage[] $deprecations
   *   The messages from the analyzer.
   *
   * @return \Drupal\upgrade_status\DeprecationMessage[]
   *   The messages without the ones about extension files.
   */
  private function withoutExtensionFileMessages(array $deprecations): array {
    return array_values(array_filter($deprecations, fn (DeprecationMessage $deprecation) => !str_starts_with($deprecation->getMessage(), 'Code in the ')));
  }

}
