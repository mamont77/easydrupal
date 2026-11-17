<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\Command\AnalyzeCommand;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests the options of the analyze command that pick the projects.
 *
 * The projects have stored results, so nothing is analyzed here. The
 * functional tests of the commands cover the analysis and the formats.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class AnalyzeCommandTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'update',
    'upgrade_status',
    'upgrade_status_test_error',
    'upgrade_status_test_contrib_error',
  ];

  /**
   * An installed custom project.
   */
  private const CUSTOM = 'upgrade_status_test_error';

  /**
   * An installed contributed project.
   */
  private const CONTRIB = 'upgrade_status_test_contrib_error';

  /**
   * A custom project that is not installed.
   */
  private const UNINSTALLED = 'upgrade_status_test_deprecated';

  /**
   * A recipe of the test fixtures.
   */
  private const RECIPE = 'upgrade_status_test_recipe';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // The results have dates, which need the date formats.
    $this->installConfig(['system']);
    $this->setSetting('file_temp_path', $this->siteDirectory . '/temp');
    $this->setSetting('upgrade_status_php_binary', PHP_BINARY);
    $this->setSetting('upgrade_status_check_tool_updates', FALSE);
    // The recipes of the test fixtures. The codebase that runs the tests may
    // have no recipes of its own.
    $path = $this->container->get('extension.list.module')->getPath('upgrade_status') . '/tests/fixtures/recipes';
    $this->setSetting('upgrade_status_recipe_directories', [$this->root . '/' . $path]);
  }

  /**
   * Tests the options that pick from all projects.
   */
  public function testAllProjects(): void {
    $storage = $this->container->get('upgrade_status.result_storage');
    $projects = $this->container->get('upgrade_status.project_collector')->collectProjects();
    $this->assertSame(ProjectCollector::TYPE_CUSTOM, $projects[self::CUSTOM]->info['upgrade_status_type']);
    $this->assertSame(ProjectCollector::TYPE_CONTRIB, $projects[self::CONTRIB]->info['upgrade_status_type']);
    $this->assertSame(0, $projects[self::UNINSTALLED]->status);
    foreach (array_keys($projects) as $name) {
      $storage->set($name, [
        'date' => 100,
        'data' => [
          'totals' => ['errors' => 0, 'file_errors' => 0, 'upgrade_status_next' => ProjectCollector::NEXT_RELAX],
          'files' => [],
        ],
      ]);
    }
    $all = array_keys($projects);
    $contrib = array_keys(array_filter($projects, static fn ($project): bool => ProjectCollector::isContributed($project)));
    // Recipes are applied, not installed, so they have no status and are
    // never left out as uninstalled.
    $uninstalled = array_keys(array_filter($projects, static fn ($project): bool => ($project->status ?? NULL) === 0));
    $recipes = array_keys(array_filter($projects, static fn ($project): bool => $project->getType() === 'recipe'));
    $this->assertContains(self::RECIPE, $recipes);
    $this->assertSame([], array_intersect($recipes, $uninstalled));
    $this->assertGreaterThan(3, count($all));

    // With existing results, no project is analyzed again.
    [$code, $names] = $this->runCommand(['--all' => TRUE, '--skip-existing' => TRUE]);
    $this->assertSame(AnalyzeCommand::SUCCESS, $code);
    $this->assertEqualsCanonicalizing($all, $names);
    foreach ($all as $name) {
      $this->assertSame(100, $storage->get($name)['date'], $name);
    }

    $cases = [
      'ignore-contrib' => [['--ignore-contrib' => TRUE], array_diff($all, $contrib)],
      'ignore-custom' => [['--ignore-custom' => TRUE], $contrib],
      'ignore-uninstalled' => [['--ignore-uninstalled' => TRUE], array_diff($all, $uninstalled)],
      'ignore-list' => [
        ['--ignore-list' => self::CUSTOM . ',' . self::CONTRIB],
        array_diff($all, [self::CUSTOM, self::CONTRIB]),
      ],
      'two options' => [
        ['--ignore-contrib' => TRUE, '--ignore-uninstalled' => TRUE],
        array_diff($all, $contrib, $uninstalled),
      ],
    ];
    foreach ($cases as $label => [$options, $expected]) {
      [$code, $names] = $this->runCommand(['--all' => TRUE, '--skip-existing' => TRUE] + $options);
      $this->assertSame(AnalyzeCommand::SUCCESS, $code, $label);
      $this->assertEqualsCanonicalizing(array_values($expected), $names, $label);
    }
    $this->assertContains(self::CONTRIB, $contrib);
    $this->assertContains(self::UNINSTALLED, $uninstalled);

    // Results with messages give the exit code for found issues.
    $result = $storage->get(self::CUSTOM);
    $result['data']['totals']['file_errors'] = 1;
    $storage->set(self::CUSTOM, $result);
    [$code, $names] = $this->runCommand(['projects' => [self::CUSTOM, self::CONTRIB], '--skip-existing' => TRUE]);
    $this->assertSame(AnalyzeCommand::EXIT_ISSUES_FOUND, $code);
    $this->assertEqualsCanonicalizing([self::CUSTOM, self::CONTRIB], $names);

    // Messages in the 'ignore' category are for a later major version. A
    // result with only those is not a found issue.
    $result['data']['totals']['upgrade_status_category'] = ['ignore' => 1];
    $storage->set(self::CUSTOM, $result);
    [$code] = $this->runCommand(['projects' => [self::CUSTOM, self::CONTRIB], '--skip-existing' => TRUE]);
    $this->assertSame(AnalyzeCommand::SUCCESS, $code);
    $result['data']['totals']['file_errors'] = 2;
    $result['data']['totals']['upgrade_status_category'] = ['ignore' => 1, 'later' => 1];
    $storage->set(self::CUSTOM, $result);
    [$code] = $this->runCommand(['projects' => [self::CUSTOM, self::CONTRIB], '--skip-existing' => TRUE]);
    $this->assertSame(AnalyzeCommand::EXIT_ISSUES_FOUND, $code);
  }

  /**
   * Tests project names that the command does not accept.
   */
  public function testInvalidProjects(): void {
    $one = 'The project machine name %s is invalid. Is this a project on this site? (For community projects, use the machine name of the drupal.org project).';
    $more = 'The project machine names %s are invalid. Are these projects on this site? (For community projects, use the machine name of the drupal.org project).';
    $cases = [
      [[], "You need to provide at least one installed project's machine_name."],
      [['projects' => ['not_a_project']], sprintf($one, 'not_a_project')],
      [['projects' => [self::CUSTOM, 'not_a_project', 'other']], sprintf($more, 'not_a_project, other')],
      // A project that an option leaves out is not accepted by name either.
      [['projects' => [self::CONTRIB], '--ignore-contrib' => TRUE], sprintf($one, self::CONTRIB)],
      [['projects' => [self::CUSTOM], '--ignore-custom' => TRUE], sprintf($one, self::CUSTOM)],
      [['projects' => [self::UNINSTALLED], '--ignore-uninstalled' => TRUE], sprintf($one, self::UNINSTALLED)],
    ];
    foreach ($cases as [$input, $message]) {
      try {
        $this->runCommand($input + ['--skip-existing' => TRUE]);
        $this->fail("No exception for: $message");
      }
      catch (InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
    // Nothing was analyzed.
    $this->assertNull($this->container->get('upgrade_status.result_storage')->get(self::CUSTOM));
  }

  /**
   * Runs the analyze command.
   *
   * @param array $input
   *   The arguments and options.
   *
   * @return array
   *   The exit code, and the machine names of the projects in the output.
   */
  protected function runCommand(array $input): array {
    $command = new AnalyzeCommand(
      $this->container->get('upgrade_status.result_formatter'),
      $this->container->get('upgrade_status.project_collector'),
      $this->container->get('upgrade_status.tool_update_checker'),
      $this->container->get('upgrade_status.deprecation_analyzer'),
      $this->container->get('upgrade_status.result_storage'),
    );
    $tester = new CommandTester($command);
    $code = $tester->execute($input);
    preg_match_all('/^## .* \((\w+)\)$/m', $tester->getDisplay(), $matches);
    return [$code, $matches[1]];
  }

}
