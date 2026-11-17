<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests analysing config schema files.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ConfigSchemaDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_config_schema',
  ];

  /**
   * Tests the messages for constraint options in a config schema.
   */
  public function testConstraintOptions(): void {
    $extension = $this->container->get('module_handler')->getModule('upgrade_status_test_config_schema');

    $sut = $this->container->get('upgrade_status.config_schema_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    $expected = [
      23 => 'Choice',
      28 => 'Length',
      31 => 'Regex',
      37 => 'AllowedValues',
      43 => 'Length',
    ];
    $this->assertCount(count($expected) + 1, $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as $line => $constraint) {
      $this->assertContainsEquals(new DeprecationMessage(
        "The options of the $constraint constraint in this config schema are not a named array. Deprecated in drupal:11.4.0 and is removed from drupal:12.0.0. Name each option, for example Choice: {choices: [a, b]} instead of Choice: [a, b]. See https://www.drupal.org/node/3554746.",
        $extension->getPath() . '/config/schema/upgrade_status_test_config_schema.schema.yml',
        $line,
        'ConfigSchemaDeprecationAnalyzer'
      ), $deprecations);
    }

    // Schema files of test modules are checked too.
    $this->assertContainsEquals(new DeprecationMessage(
      'The options of the Length constraint in this config schema are not a named array. Deprecated in drupal:11.4.0 and is removed from drupal:12.0.0. Name each option, for example Choice: {choices: [a, b]} instead of Choice: [a, b]. See https://www.drupal.org/node/3554746.',
      $extension->getPath() . '/tests/modules/upgrade_status_test_config_schema_nested/config/schema/upgrade_status_test_config_schema_nested.schema.yml',
      5,
      'ConfigSchemaDeprecationAnalyzer'
    ), $deprecations);
  }

  /**
   * Tests that a module without config schema is not reported.
   *
   * This does not use a core module, because core's schema files on Drupal 10
   * still have constraint options that are not named arrays.
   */
  public function testNoConfigSchema(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_13_compatible');

    $sut = $this->container->get('upgrade_status.config_schema_deprecation_analyzer');
    $this->assertSame([], $sut->analyze($extension));
  }

  /**
   * Tests the messages for shipped config that uses deprecated schema keys.
   */
  public function testDeprecatedKeys(): void {
    // The module is enabled for its schema, but its config is not installed.
    $this->enableModules(['upgrade_status_test_config_deprecated']);
    $name = 'upgrade_status_test_config_deprecated';
    $extension = $this->container->get('module_handler')->getModule($name);
    $path = $extension->getPath();
    $recipe = "$path/recipes/{$name}_recipe";

    $sut = $this->container->get('upgrade_status.config_schema_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    $old = "The old key of the $name.settings config is used. Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0. Use 'current' instead. See https://www.drupal.org/project/upgrade_status.";
    $nested = "The nested.old key of the $name.settings config is used. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. There is no replacement. See https://www.drupal.org/project/upgrade_status.";
    $item = "The items.0.old key of the $name.settings config is used. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. There is no replacement. See https://www.drupal.org/project/upgrade_status.";
    $legacy = "The $name.legacy config is used. Deprecated in drupal:11.3.0 and is removed from drupal:13.0.0. There is no replacement. See https://www.drupal.org/project/upgrade_status.";
    // Keys of a type with a name, of a type that extends it, of a type that
    // depends on the config data, and of a type that is part of its own
    // definition. The keys of the type without deprecations are not reported.
    $typed = static fn (string $key): string => "The $key key of the $name.types config is used. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. There is no replacement. See https://www.drupal.org/project/upgrade_status.";
    // The config action on several config objects is not checked.
    $this->assertEqualsCanonicalizing(
      [
        "$path/config/install/$name.settings.yml:2 $old",
        "$path/config/install/$name.settings.yml:5 $nested",
        "$path/config/install/$name.settings.yml:8 $item",
        "$path/config/install/$name.types.yml:7 " . $typed('shared.old'),
        "$path/config/install/$name.types.yml:10 " . $typed('extended.old'),
        "$path/config/install/$name.types.yml:14 " . $typed('plugin.settings.old'),
        "$path/config/install/$name.types.yml:20 " . $typed('tree.children.0.children.0.old'),
        "$path/config/optional/$name.legacy.yml:1 $legacy",
        "$recipe/config/$name.settings.yml:3 $nested",
        "$recipe/recipe.yml:9 $nested",
      ],
      array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations),
    );
  }

  /**
   * Tests the message for default_argument_skip_url in shipped views.
   */
  public function testViewsSkipUrl(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_error');

    $deprecations = $this->container->get('upgrade_status.config_schema_deprecation_analyzer')->analyze($extension);

    $this->assertEquals([
      new DeprecationMessage('Support from all Views contextual filter settings for the default_argument_skip_url setting is removed from drupal:11.0.0. No replacement is provided. See https://www.drupal.org/node/3382316.', $extension->getPath() . '/config/views.view.remove_default_argument_skip_url.yml', 109, 'ConfigSchemaDeprecationAnalyzer'),
    ], $deprecations);
  }

}
