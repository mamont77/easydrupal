<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use Drupal\upgrade_status\RecipeExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding deprecated features in recipes.
 *
 * @group upgrade_status
 * @coversDefaultClass \Drupal\upgrade_status\RecipeDeprecationAnalyzer
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class RecipeDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'system',
    'user',
    'upgrade_status',
  ];

  /**
   * Tests which deprecated features of a recipe project are reported.
   *
   * @covers ::analyze
   */
  public function testAnalyzeRecipe(): void {
    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/recipes/upgrade_status_test_recipe';
    $recipe = new RecipeExtension($this->root, 'recipe', "$path/recipe.yml");

    $deprecations = $this->container->get('upgrade_status.recipe_deprecation_analyzer')->analyze($recipe);

    $link = 'https://drupal.org/project/upgrade_status';
    $this->assertEqualsCanonicalizing(
      [
        "$path/recipe.yml:6 The core/recipes/article_tags core recipe is applied. Deprecated in drupal:11.4.0 and is removed from drupal:12.0.0. Core no longer uses it. Add the configuration you need to this recipe instead. See https://www.drupal.org/project/drupal/issues/3608069.",
        "$path/recipe.yml:10 The recipe installs the upgrade_status_test_deprecated module, which is deprecated. See $link.",
        "$path/recipe.yml:11 The recipe installs the upgrade_status_test_obsolete module, which is obsolete. See $link.",
        "$path/recipe.yml:15 The recipe imports configuration from the upgrade_status_test_deprecated module, which is deprecated. See $link.",
        "$path/recipe.yml:21 The simpleConfigUpdate config action is used on the user.role.authenticated config entity. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 throws an exception. Use the setProperties config action instead. See https://www.drupal.org/node/3515543.",
        "$path/recipe.yml:24 The entity_create:ensure_exists config action is used. Deprecated in drupal:10.3.1 and is removed from drupal:12.0.0. Use entity_create:createIfNotExists instead. See https://www.drupal.org/node/3458273.",
        "$path/recipe.yml:27 The simple_config_update config action is used. Deprecated in drupal:10.3.1 and is removed from drupal:12.0.0. Use simpleConfigUpdate instead. See https://www.drupal.org/node/3458273.",
        "$path/config/block.block.upgrade_status_test.yml:10 The configuration depends on the upgrade_status_test_deprecated module, which is deprecated. See $link.",
      ],
      $this->formatMessages($deprecations),
    );

    foreach ($deprecations as $deprecation) {
      $this->assertSame('RecipeDeprecationAnalyzer', $deprecation->getAnalyzer());
    }

    // A recipe without deprecated features gets no messages.
    $recipe = new RecipeExtension($this->root, 'recipe', dirname($path) . '/upgrade_status_test_recipe_compatible/recipe.yml');
    $this->assertSame([], $this->container->get('upgrade_status.recipe_deprecation_analyzer')->analyze($recipe));
  }

  /**
   * Tests that the recipes shipped with a module are analyzed.
   *
   * @covers ::analyze
   */
  public function testAnalyzeModuleRecipes(): void {
    // Upgrade Status itself has the recipe of a test module. The recipes in
    // test fixtures are skipped.
    $extension = $this->container->get('extension.list.module')->get('upgrade_status');
    $path = $extension->getPath() . '/tests/modules/upgrade_status_test_obsolete/recipes/upgrade_status_test_module_recipe';

    $deprecations = $this->container->get('upgrade_status.recipe_deprecation_analyzer')->analyze($extension);

    $this->assertSame(
      ["$path/recipe.yml:5 The recipe installs the upgrade_status_test_obsolete module, which is obsolete. See https://drupal.org/project/upgrade_status."],
      $this->formatMessages($deprecations),
    );
  }

  /**
   * Formats deprecation messages with their file and line.
   *
   * @param \Drupal\upgrade_status\DeprecationMessage[] $deprecations
   *   The deprecation messages.
   *
   * @return string[]
   *   The messages, each starting with its file and line.
   */
  private function formatMessages(array $deprecations): array {
    return array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations);
  }

}
