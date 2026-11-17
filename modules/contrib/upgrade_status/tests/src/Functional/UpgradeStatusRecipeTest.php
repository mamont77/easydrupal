<?php

namespace Drupal\Tests\upgrade_status\Functional;

use Drush\TestTraits\DrushTestTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests listing and analysing recipes.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
class UpgradeStatusRecipeTest extends UpgradeStatusTestBase {

  use DrushTestTrait;

  /**
   * Tests that a recipe is listed, scanned and reported.
   */
  public function testRecipe(): void {
    $this->writeSettings([
      'settings' => [
        'upgrade_status_recipe_directories' => (object) [
          'value' => [DRUPAL_ROOT . '/' . $this->getModulePath('upgrade_status') . '/tests/fixtures/recipes'],
          'required' => TRUE,
        ],
      ],
    ]);
    $this->drupalLogin($this->drupalCreateUser(['administer software updates']));

    $this->drupalGet('admin/reports/upgrade-status');
    $row = $this->assertSession()->elementExists('css', 'tr.project-upgrade_status_test_recipe');
    $this->assertStringContainsString('Upgrade status test recipe', $row->getText());
    $this->assertStringContainsString('upgrade_status_test_recipe', $row->getText());
    $this->assertStringContainsString('Custom recipe', $row->getText());
    $this->submitForm(['scan[data][list][upgrade_status_test_recipe]' => TRUE], 'Analyze selected');

    /** @var \Drupal\Core\KeyValueStore\KeyValueStoreInterface $key_value */
    $key_value = \Drupal::service('keyvalue')->get('upgrade_status_scan_results');
    $report = $key_value->get('upgrade_status_test_recipe');
    $this->assertNotEmpty($report);
    $analyzers = [];
    foreach ($report['data']['files'] as $file) {
      foreach ($file['messages'] as $message) {
        @$analyzers[$message['analyzer']]++;
      }
    }
    // Only the recipe analyzer and the composer.json check apply. The
    // composer.json file requires drupal/core ^10. The messages are tested in
    // the kernel test of the recipe analyzer.
    $this->assertEqualsCanonicalizing(['ExtensionMetadataDeprecationAnalyzer', 'RecipeDeprecationAnalyzer'], array_keys($analyzers));

    $this->drupalGet('admin/reports/upgrade-status/project/upgrade_status_test_recipe');
    $this->assertSession()->pageTextContains('The core/recipes/article_tags core recipe is applied.');
    $this->assertSession()->pageTextContains('The drupal/core requirement is not compatible with the next major version of Drupal.');

    // Drush scans recipes too. They are not skipped as uninstalled projects.
    $this->drush('upgrade_status:analyze', ['upgrade_status_test_recipe'], ['ignore-uninstalled' => NULL], NULL, NULL, 3);
    $this->assertStringContainsString('core/recipes/article_tags', $this->getOutput());
  }

}
