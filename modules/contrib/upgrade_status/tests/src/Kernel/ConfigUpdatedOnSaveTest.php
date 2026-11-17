<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the messages for shipped config that Drupal 11 updates on save.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ConfigUpdatedOnSaveTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
  ];

  /**
   * Tests the messages for views, blocks and form modes.
   */
  public function testConfigUpdatedOnSave(): void {
    $name = 'upgrade_status_test_config_updates';
    $extension = $this->container->get('extension.list.module')->get($name);
    $install = $extension->getPath() . '/config/install';
    $optional = $extension->getPath() . '/config/optional';
    $recipe = $extension->getPath() . "/recipes/{$name}_recipe/config";

    $table = "The table style of the %s display in %s has no class option. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Add class: '' to the style options. See https://www.drupal.org/node/3499943.";
    $block_content = "The %s setting of the content block in block.block.{$name}_content is not used. Deprecated in drupal:11.3.0 and is removed from drupal:12.0.0. Remove the setting. See https://www.drupal.org/node/3499836.";
    $description = 'The description of %s is an empty string. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use null. See https://www.drupal.org/node/3452144.';
    // The table style without options, the table style with a class, the
    // correct block and the view mode are not reported.
    $expected = [
      "$install/views.view.$name.yml:33 " . sprintf($table, 'default', "views.view.$name"),
      "$install/views.view.$name.yml:69 " . sprintf($table, 'block_1', "views.view.$name"),
      "$install/block.block.{$name}_content.yml:11 The weight of block.block.{$name}_content is not an integer. Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0. Use an integer. See https://www.drupal.org/node/3462474.",
      "$install/block.block.{$name}_content.yml:19 " . sprintf($block_content, 'status'),
      "$install/block.block.{$name}_content.yml:20 " . sprintf($block_content, 'info'),
      "$optional/block.block.{$name}_search.yml:19 The page_id setting of the search block in block.block.{$name}_search is an empty string. Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0. Use null for the default search page. See https://www.drupal.org/node/3463132.",
      "$optional/core.entity_form_mode.entity_test.$name.yml:8 " . sprintf($description, "core.entity_form_mode.entity_test.$name"),
      "$recipe/core.entity_form_mode.entity_test.{$name}_recipe.yml:8 " . sprintf($description, "core.entity_form_mode.entity_test.{$name}_recipe"),
      "$recipe/views.view.{$name}_recipe.yml:22 " . sprintf($table, 'default', "views.view.{$name}_recipe"),
    ];

    // Without the views module, the contextual filters are not checked.
    $sut = $this->container->get('upgrade_status.config_schema_deprecation_analyzer');
    $this->assertEqualsCanonicalizing($expected, $this->formatMessages($sut->analyze($extension)));

    // The user_id field of entity_test is an entity reference, but id is not.
    $this->enableModules(['system', 'user', 'field', 'text', 'filter', 'views', 'entity_test']);
    $expected[] = "$install/views.view.$name.yml:25 The user_id contextual filter of the default display in views.view.$name uses the numeric plugin for an entity reference. Deprecated in drupal:10.3.0 and is removed from drupal:12.0.0. Use plugin_id: entity_target_id and target_entity_type_id: user. See https://www.drupal.org/node/3441945.";
    $sut = $this->container->get('upgrade_status.config_schema_deprecation_analyzer');
    $this->assertEqualsCanonicalizing($expected, $this->formatMessages($sut->analyze($extension)));
  }

  /**
   * Formats messages as "file:line message" strings.
   *
   * @param \Drupal\upgrade_status\DeprecationMessage[] $messages
   *   The messages.
   *
   * @return list<string>
   *   The formatted messages.
   */
  private function formatMessages(array $messages): array {
    return array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $messages);
  }

}
