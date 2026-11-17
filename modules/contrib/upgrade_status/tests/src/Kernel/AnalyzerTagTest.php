<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\Extension;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\AnalyzerInterface;
use Drupal\upgrade_status\ConfigSchemaDeprecationAnalyzer;
use Drupal\upgrade_status\ExtensionMetadataDeprecationAnalyzer;
use Drupal\upgrade_status\FetchModeDeprecationAnalyzer;
use Drupal\upgrade_status\HookDeprecationAnalyzer;
use Drupal\upgrade_status\JavaScriptDeprecationAnalyzer;
use Drupal\upgrade_status\LibraryDeprecationAnalyzer;
use Drupal\upgrade_status\PluginDefinitionDeprecationAnalyzer;
use Drupal\upgrade_status\RecipeDeprecationAnalyzer;
use Drupal\upgrade_status\RouteDeprecationAnalyzer;
use Drupal\upgrade_status\ServiceDeprecationAnalyzer;
use Drupal\upgrade_status\SessionDeprecationAnalyzer;
use Drupal\upgrade_status\TemplateDeprecationAnalyzer;
use Drupal\upgrade_status\TwigDeprecationAnalyzer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Tests the analyzers that the main analyzer gets from tagged services.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class AnalyzerTagTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container): void {
    parent::register($container);
    // An analyzer of another module, between two analyzers of this module.
    $container->register('upgrade_status_test.analyzer', FetchModeDeprecationAnalyzer::class)
      ->addArgument(new Reference('upgrade_status.file_finder'))
      ->addTag('upgrade_status.analyzer', ['priority' => 65]);
  }

  /**
   * Tests the order of the analyzers and which projects they apply to.
   */
  public function testAnalyzers(): void {
    $main = $this->container->get('upgrade_status.deprecation_analyzer');
    $analyzers = (new \ReflectionProperty($main, 'analyzers'))->getValue($main);
    $this->assertContainsOnlyInstancesOf(AnalyzerInterface::class, $analyzers);

    // The order is the order of the messages in the results.
    $this->assertSame([
      TwigDeprecationAnalyzer::class,
      LibraryDeprecationAnalyzer::class,
      RouteDeprecationAnalyzer::class,
      ConfigSchemaDeprecationAnalyzer::class,
      HookDeprecationAnalyzer::class,
      JavaScriptDeprecationAnalyzer::class,
      // The analyzer that this test adds with a priority of 65.
      FetchModeDeprecationAnalyzer::class,
      RecipeDeprecationAnalyzer::class,
      FetchModeDeprecationAnalyzer::class,
      ServiceDeprecationAnalyzer::class,
      TemplateDeprecationAnalyzer::class,
      SessionDeprecationAnalyzer::class,
      PluginDefinitionDeprecationAnalyzer::class,
      ExtensionMetadataDeprecationAnalyzer::class,
    ], array_map('get_class', $analyzers));
    $this->assertSame($this->container->get('upgrade_status_test.analyzer'), $analyzers[6]);

    // All of them analyze modules. Recipes have no extension code.
    $module = new Extension($this->root, 'module', 'core/modules/system/system.info.yml');
    $recipe = new Extension($this->root, 'recipe', 'core/recipes/standard/recipe.yml');
    $for_recipes = [];
    foreach ($analyzers as $analyzer) {
      $this->assertTrue($analyzer->applies($module), get_class($analyzer));
      if ($analyzer->applies($recipe)) {
        $for_recipes[] = get_class($analyzer);
      }
    }
    $this->assertSame([
      ConfigSchemaDeprecationAnalyzer::class,
      RecipeDeprecationAnalyzer::class,
      ExtensionMetadataDeprecationAnalyzer::class,
    ], $for_recipes);
  }

}
