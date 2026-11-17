<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Extension\Extension;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\Controller\ScanResultController;
use Drupal\upgrade_status\ProjectCollector;
use Drupal\upgrade_status\RecipeExtension;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Tests which extensions are collected as projects.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ProjectCollectorTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = ['update', 'upgrade_status'];

  /**
   * Tests that core themes are not collected, but other themes are.
   */
  public function testCoreThemesAreNotCollected(): void {
    $this->installConfig(['update']);
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    $this->assertArrayNotHasKey('stark', $projects);
    $this->assertArrayNotHasKey('starterkit_theme', $projects);
    $this->assertArrayHasKey('upgrade_status_test_theme', $projects);
  }

  /**
   * Tests that recipes are collected, except core recipes.
   */
  public function testRecipesAreCollected(): void {
    $this->installConfig(['update']);
    $collector = $this->container->get('upgrade_status.project_collector');
    $this->assertArrayNotHasKey('upgrade_status_test_recipe', $collector->collectProjects());

    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/recipes';
    $this->setSetting('upgrade_status_recipe_directories', [
      $this->root . '/core/recipes',
      $this->root . '/' . $path,
    ]);
    $projects = $collector->collectProjects();
    $this->assertArrayNotHasKey('standard', $projects);

    $recipe = $projects['upgrade_status_test_recipe'];
    $this->assertInstanceOf(RecipeExtension::class, $recipe);
    $this->assertSame('recipe', $recipe->getType());
    $this->assertSame("$path/upgrade_status_test_recipe/recipe.yml", $recipe->getPathname());
    $this->assertSame('Upgrade status test recipe', $recipe->info['name']);
    $this->assertSame(ProjectCollector::TYPE_CUSTOM, $recipe->info['upgrade_status_type']);
    // Its composer.json requires drupal/core ^10.
    $this->assertFalse($recipe->info['upgrade_status_next_major_compatible']);
    $this->assertSame(ProjectCollector::NEXT_SCAN, $recipe->info['upgrade_status_next']);

    $recipe = $projects['upgrade_status_test_recipe_compatible'];
    $this->assertSame('Upgrade status test compatible recipe', $recipe->info['name']);
    $this->assertTrue($recipe->info['upgrade_status_next_major_compatible']);
    // Recipes are applied, not installed, so they are not to be removed.
    $this->assertSame(ProjectCollector::NEXT_SCAN, $recipe->info['upgrade_status_next']);
  }

  /**
   * Tests a recipe with a name and a core requirement that are not valid.
   */
  public function testInvalidRecipe(): void {
    $this->installConfig(['update']);
    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/invalid_recipes';
    $this->setSetting('upgrade_status_recipe_directories', [$this->root . '/' . $path]);
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();

    // The other projects are still collected.
    $this->assertArrayHasKey('upgrade_status', $projects);
    $recipe = $projects['upgrade_status_test_recipe_invalid'];
    // The name is not a string, so the directory name is used.
    $this->assertSame('upgrade_status_test_recipe_invalid', $recipe->info['name']);
    // The drupal/core requirement cannot be parsed, so the recipe is not
    // compatible.
    $this->assertFalse($recipe->info['upgrade_status_next_major_compatible']);
  }

  /**
   * Tests that the pages of a project that does not exist are not found.
   */
  public function testUnknownProject(): void {
    $this->installConfig(['update']);
    $controller = ScanResultController::create($this->container);
    foreach (['resultPage', 'resultTitle', 'analyze'] as $method) {
      try {
        $controller->$method('no_such_project');
        $this->fail($method);
      }
      catch (NotFoundHttpException) {
      }
    }
    try {
      $controller->resultExport('no_such_project', 'csv');
      $this->fail('resultExport');
    }
    catch (NotFoundHttpException) {
    }
    $this->assertSame('upgrade_status', $this->container->get('upgrade_status.project_collector')->loadProject('upgrade_status')->getName());
  }

  /**
   * Tests the next step of projects.
   */
  public function testNextSteps(): void {
    $this->enableModules(['upgrade_status_test_error', 'upgrade_status_test_contrib_error']);
    $collector = $this->container->get('upgrade_status.project_collector');
    $storage = $this->container->get('upgrade_status.result_storage');
    $next = static function (string $name) use ($collector): string {
      return $collector->collectProjects()[$name]->info['upgrade_status_next'];
    };
    $result = static fn (string $next_step): array => [
      'date' => 1,
      'data' => ['totals' => ['upgrade_status_next' => $next_step], 'files' => []],
    ];

    // An installed custom project is analyzed first. After that, its next
    // step comes from its results.
    $custom = 'upgrade_status_test_error';
    $this->assertSame(ProjectCollector::NEXT_SCAN, $next($custom));
    $steps = [
      ProjectCollector::NEXT_RECTOR,
      ProjectCollector::NEXT_MANUAL,
      ProjectCollector::NEXT_REQUIREMENTS,
      ProjectCollector::NEXT_RELAX,
    ];
    foreach ($steps as $step) {
      $storage->set($custom, $result($step));
      $this->assertSame($step, $next($custom));
    }

    // A project that is not installed is removed, whatever its results are.
    $uninstalled = 'upgrade_status_test_deprecated';
    $this->assertSame(ProjectCollector::NEXT_REMOVE, $next($uninstalled));
    $storage->set($uninstalled, $result(ProjectCollector::NEXT_RELAX));
    $this->assertSame(ProjectCollector::NEXT_REMOVE, $next($uninstalled));

    // An installed contributed project that is not compatible, without a
    // known update, is for collaboration. Its results don't change that.
    $contrib = 'upgrade_status_test_contrib_error';
    $this->assertSame(ProjectCollector::TYPE_CONTRIB, $collector->collectProjects()[$contrib]->info['upgrade_status_type']);
    $this->assertSame(ProjectCollector::NEXT_COLLABORATE, $next($contrib));
    $storage->set($contrib, $result(ProjectCollector::NEXT_MANUAL));
    $this->assertSame(ProjectCollector::NEXT_COLLABORATE, $next($contrib));

    // With a release that is not installed yet, it is updated first.
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $releases = $this->container->get('keyvalue.expirable')->get('update_available_releases');
    $releases->setWithExpire($contrib, [
      'link' => "https://www.drupal.org/project/$contrib",
      'releases' => ['2.0.0' => ['version' => '2.0.0', 'status' => 'published', 'core_compatibility' => "^$major"]],
    ], 3600);
    $project = $collector->collectProjects()[$contrib];
    $this->assertSame(ProjectCollector::NEXT_UPDATE, $project->info['upgrade_status_next']);
    $this->assertSame('2.0.0', $project->info['upgrade_status_update_version']);
    $this->assertSame("https://www.drupal.org/project/$contrib/releases/2.0.0", $project->info['upgrade_status_update_link']);

    // A contributed project that is compatible with the next major version
    // has nothing to do, also when it is not installed. The project name of
    // this module differs from its machine name.
    $project = $collector->collectProjects()['upgrade_status_test_contrib_12_compatible'];
    $this->assertSame('upgrade_status_test_contributed_12_compatible', $project->info['project']);
    $this->assertSame(
      $project->info['upgrade_status_next_major_compatible'] ? ProjectCollector::NEXT_RELAX : ProjectCollector::NEXT_REMOVE,
      $project->info['upgrade_status_next'],
    );
    $this->assertSame($major + 1 <= 12, $project->info['upgrade_status_next_major_compatible']);
  }

  /**
   * Tests sorting contributed projects without a version.
   */
  public function testContribProjectsWithoutVersion(): void {
    // The checks look at real files, not the virtual file system of the test.
    $base = 'sites/simpletest/' . $this->randomMachineName();
    $project = function (string $path, string $name, string $type, array $info = []): Extension {
      if (!is_dir($this->root . "/$path")) {
        mkdir($this->root . "/$path", 0777, TRUE);
      }
      if (!file_exists($this->root . "/$path/$name.info.yml")) {
        touch($this->root . "/$path/$name.info.yml");
      }
      $extension = new Extension($this->root, 'module', "$path/$name.info.yml");
      $extension->info = $info + [
        'name' => $name,
        'project' => $name,
        'upgrade_status_type' => $type,
      ];
      return $extension;
    };
    mkdir($this->root . "/$base/git_project/.git", 0777, TRUE);

    try {
      $projects = [
        // Composer knows drupal/core in the test environment.
        'system' => $project('core/modules/system', 'system', ProjectCollector::TYPE_CONTRIB, ['project' => 'core']),
        'git_project' => $project("$base/git_project", 'git_project', ProjectCollector::TYPE_CONTRIB),
        'copied_project' => $project("$base/copied_project", 'copied_project', ProjectCollector::TYPE_CONTRIB),
        // Projects with a version, and custom projects, are left out.
        'packaged_project' => $project("$base/packaged_project", 'packaged_project', ProjectCollector::TYPE_CONTRIB, ['version' => '2.0.0']),
        'custom_project' => $project("$base/custom_project", 'custom_project', ProjectCollector::TYPE_CUSTOM),
      ];

      $result = $this->container->get('upgrade_status.project_collector')->getContribProjectsWithoutVersion($projects);
      $this->assertSame(['system'], array_keys($result['composer']));
      $this->assertSame(['git_project'], array_keys($result['git']));
      $this->assertSame(['copied_project'], array_keys($result['other']));
    }
    finally {
      $this->container->get('file_system')->deleteRecursive($this->root . "/$base");
    }
  }

  /**
   * Tests the number of problems in the summary of a project.
   */
  public function testResultCounts(): void {
    $this->installConfig(['update']);
    $collector = $this->container->get('upgrade_status.project_collector');
    $storage = $this->container->get('upgrade_status.result_storage');
    $name = 'upgrade_status_test_error';
    $project = $collector->collectProjects()[$name];

    $summary = $collector->getProjectSummary($project);
    $actual = [$summary['result_count'], $summary['ignore_count'], (string) $summary['result']];
    $this->assertSame([NULL, NULL, 'N/A'], $actual);

    // Messages of the 'ignore' category are for later versions than the next
    // major Drupal version. Those are counted apart from the problems.
    $next_major = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    $cases = [
      'no messages' => [0, [], 0, 0, 'No problems found'],
      'one problem' => [1, ['safe' => 1], 1, 0, '1 problem'],
      'problems and messages to ignore' => [
        5,
        ['old' => 2, 'later' => 1, 'ignore' => 2],
        3,
        2,
        "3 problems, +2 after Drupal $next_major",
      ],
      'only a message to ignore' => [1, ['ignore' => 1], 0, 1, "No problems found, +1 after Drupal $next_major"],
      // The result of a failed analysis has no categories.
      'failed analysis' => [1, NULL, 1, 0, '1 problem'],
    ];
    foreach ($cases as $label => [$messages, $categories, $problems, $ignored, $text]) {
      $totals = ['errors' => $messages, 'file_errors' => $messages];
      if ($categories !== NULL) {
        $totals['upgrade_status_category'] = $categories;
      }
      $storage->set($name, ['date' => 100, 'data' => ['totals' => $totals, 'files' => []]]);
      $storage->resetTotals($name);
      $summary = $collector->getProjectSummary($project);
      $actual = [$summary['result_count'], $summary['ignore_count'], (string) $summary['result']];
      $this->assertSame([$problems, $ignored, $text], $actual, $label);
    }
  }

}
