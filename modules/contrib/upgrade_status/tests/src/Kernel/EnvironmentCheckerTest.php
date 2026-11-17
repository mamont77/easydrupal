<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Update\UpdateHookRegistry;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\EnvironmentChecker;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the environment checks.
 *
 * @coversDefaultClass \Drupal\upgrade_status\EnvironmentChecker
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class EnvironmentCheckerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'upgrade_status',
  ];

  /**
   * Tests the structure of the checks and the web server check.
   */
  public function testChecks(): void {
    $checker = $this->container->get('upgrade_status.environment_checker');
    $environment = $checker->getChecks();
    $this->assertSame(['description', 'status', 'checks', 'warnings'], array_keys($environment));
    if (empty($environment['checks'])) {
      $this->markTestSkipped('The requirements of the next major Drupal version are not defined yet.');
    }
    foreach ($environment['checks'] as $check) {
      $this->assertSame(['requirement', 'name', 'qualifier', 'expected', 'help', 'status', 'items', 'url', 'severity'], array_keys($check));
      $this->assertContains($check['severity'], ['success', 'warning', 'error', NULL]);
    }

    $cases = [
      'Apache/2.4.58 (Unix)' => 'success',
      'Apache/2.2.34' => 'error',
      'nginx/1.27.0' => 'success',
      'Microsoft-IIS/10.0' => 'error',
      // The version or the server is not known.
      'Apache' => NULL,
      'PHP 8.5.1 Development Server' => NULL,
      '' => NULL,
    ];
    foreach ($cases as $software => $severity) {
      $request = Request::create('/');
      $request->server->set('SERVER_SOFTWARE', $software);
      $this->container->get('request_stack')->push($request);
      $checks = array_filter($checker->getChecks()['checks'], fn ($check) => str_starts_with((string) $check['requirement'], 'The web server should be'));
      $this->assertCount(1, $checks);
      $check = reset($checks);
      $this->assertSame($severity, $check['severity'], $software);
      $this->assertSame($software ?: 'Cannot be detected, check manually', (string) $check['status']);
      $this->container->get('request_stack')->pop();
    }
  }

  /**
   * Tests the check for database updates that did not run yet.
   */
  public function testDatabaseUpdates(): void {
    $this->enableModules(['upgrade_status_test_database_updates']);
    // A test theme of Drupal core with one post update.
    $this->container->get('theme_installer')->install(['test_theme_updates']);
    $post_update_registry = $this->container->get('update.post_update_registry');
    $post_updates = $this->container->get('keyvalue')->get('post_update');

    // No post update ran, so all of them are pending. The check finds the
    // same post updates of modules and themes as the registry of Drupal core,
    // and loads the files without the registry.
    $post_updates->set('existing_updates', []);
    $this->assertFalse(function_exists('upgrade_status_test_database_updates_post_update_first'));
    $check = $this->getDatabaseUpdatesCheck([11001]);
    if (is_null($check)) {
      $this->markTestSkipped('The requirements of the next major Drupal version are not defined yet.');
    }
    $this->assertTrue(function_exists('upgrade_status_test_database_updates_post_update_first'));
    $pending = $post_update_registry->getPendingUpdateFunctions();
    $this->assertContains('upgrade_status_test_database_updates_post_update_first', $pending);
    $this->assertContains('upgrade_status_test_database_updates_post_update_second', $pending);
    $this->assertContains('test_theme_updates_post_update_test', $pending);
    $this->assertSame('error', $check['severity']);
    $this->assertSame(count($pending) . ' updates pending', (string) $check['status']);
    $this->assertStringEndsWith('/update.php', $check['url']);

    // No updates are pending.
    $post_update_registry->registerInvokedUpdates($pending);
    $check = $this->getDatabaseUpdatesCheck([11001]);
    $this->assertSame('success', $check['severity']);
    $this->assertSame('None pending', (string) $check['status']);
    $this->assertNull($check['url']);

    // Two hook_update_N() functions of the system module and one post update
    // function did not run.
    $post_updates->set('existing_updates', array_values(array_diff($pending, ['upgrade_status_test_database_updates_post_update_second'])));
    $check = $this->getDatabaseUpdatesCheck([11001, 11002, 11003]);
    $this->assertSame('error', $check['severity']);
    $this->assertSame('3 updates pending', (string) $check['status']);
    $this->assertStringEndsWith('/update.php', $check['url']);

    // The post update of a theme that is not installed does not count, while
    // its function is still defined.
    $post_updates->set('existing_updates', array_values(array_diff($pending, ['test_theme_updates_post_update_test'])));
    $check = $this->getDatabaseUpdatesCheck([11001]);
    $this->assertSame('1 update pending', (string) $check['status']);
    $this->container->get('theme_installer')->uninstall(['test_theme_updates']);
    $post_updates->set('existing_updates', array_values(array_diff($pending, ['test_theme_updates_post_update_test'])));
    $check = $this->getDatabaseUpdatesCheck([11001]);
    $this->assertSame('None pending', (string) $check['status']);
  }

  /**
   * Tests the check for database updates with the update registries of core.
   *
   * The registry of hook_update_N() functions lists the functions of all
   * modules on its first use. So the check needs to find the updates of a
   * module that is not the first one in the list of modules.
   */
  public function testDatabaseUpdatesOfLaterModule(): void {
    $this->enableModules(['upgrade_status_test_database_updates']);
    $modules = array_keys($this->container->get('module_handler')->getModuleList());
    $this->assertNotSame('upgrade_status_test_database_updates', reset($modules));

    // A kernel test has no installed schema versions, and no post updates
    // that ran. Set a version above every update, without loading the
    // .install files, which is the job of the check.
    $update_hook_registry = $this->container->get('update.update_hook_registry');
    foreach ($modules as $module) {
      $update_hook_registry->setInstalledVersion($module, 99999);
    }
    $post_update_registry = $this->container->get('update.post_update_registry');
    $post_update_registry->registerInvokedUpdates($post_update_registry->getPendingUpdateFunctions());

    // The two updates of the test module did not run.
    $update_hook_registry->setInstalledVersion('upgrade_status_test_database_updates', 9000);
    $check = $this->findDatabaseUpdatesCheck($this->container->get('upgrade_status.environment_checker')->getChecks());
    if (is_null($check)) {
      $this->markTestSkipped('The requirements of the next major Drupal version are not defined yet.');
    }
    $this->assertSame('error', $check['severity']);
    $this->assertSame('2 updates pending', (string) $check['status']);

    // One of them ran, then both.
    $update_hook_registry->setInstalledVersion('upgrade_status_test_database_updates', 9001);
    $check = $this->findDatabaseUpdatesCheck($this->container->get('upgrade_status.environment_checker')->getChecks());
    $this->assertSame('1 update pending', (string) $check['status']);
    $update_hook_registry->setInstalledVersion('upgrade_status_test_database_updates', 9002);
    $check = $this->findDatabaseUpdatesCheck($this->container->get('upgrade_status.environment_checker')->getChecks());
    $this->assertSame('success', $check['severity']);
    $this->assertSame('None pending', (string) $check['status']);
  }

  /**
   * Finds the database updates check in the environment checks.
   *
   * @param array $environment
   *   The environment from EnvironmentChecker::getChecks().
   *
   * @return array|null
   *   The database updates check, or NULL if there are no checks.
   */
  protected function findDatabaseUpdatesCheck(array $environment): ?array {
    if (empty($environment['checks'])) {
      return NULL;
    }
    $checks = array_filter($environment['checks'], fn ($check) => str_starts_with((string) $check['requirement'], 'All database updates'));
    $this->assertCount(1, $checks);
    return reset($checks);
  }

  /**
   * Tests the check of the Drupal core version.
   */
  public function testCoreVersion(): void {
    $checker = $this->container->get('upgrade_status.environment_checker');
    $check_core = new \ReflectionMethod($checker, 'checkCore');
    $major = (int) \Drupal::VERSION;
    $current = \Drupal::VERSION;
    // A version that this major version always meets, also before its first
    // stable release, when the version is 12.0-dev, 12.0.0-dev or
    // 12.0.0-beta1.
    $minimum = "$major.0-dev";

    // This version allows the upgrade, and no newer release is known.
    $status = TRUE;
    $check = $check_core->invokeArgs($checker, [$minimum, &$status]);
    $this->assertTrue($status);
    $this->assertSame("Drupal core should be at least $minimum.", (string) $check['requirement']);
    $this->assertSame("$minimum or later", (string) $check['expected']);
    $this->assertSame("Version $current", (string) $check['status']);
    $this->assertSame([NULL, 'success'], [$check['url'], $check['severity']]);

    // A newer release of this major version is a warning. Releases that are
    // not stable and releases of other major versions are not suggested.
    $this->container->get('keyvalue.expirable')->get('update_available_releases')->setWithExpire('drupal', [
      'link' => 'https://www.drupal.org/project/drupal',
      'releases' => [
        ($major + 1) . '.0.0' => [],
        "$major.99.1-rc1" => [],
        "$major.99.0" => [],
        "$major.98.0" => [],
      ],
    ], 3600);
    $check = $check_core->invokeArgs($checker, [$minimum, &$status]);
    $this->assertTrue($status);
    $this->assertSame("Version $current allows upgrading, but $major.99.0 is available", (string) $check['status']);
    $this->assertSame("https://www.drupal.org/project/drupal/releases/$major.99.0", $check['url']);
    $this->assertSame('warning', $check['severity']);

    // A version before the minimum is an error.
    $check = $check_core->invokeArgs($checker, ["$major.99.0", &$status]);
    $this->assertFalse($status);
    $this->assertSame("Version $current does not allow upgrading, $major.99.0 is available", (string) $check['status']);
    $this->assertSame('error', $check['severity']);

    // A next major version without known requirements has no checks.
    $environment = (new \ReflectionMethod($checker, 'getChecksWithoutRequirements'))->invoke($checker, 99);
    $this->assertSame('Drupal 99 environment requirements are not yet defined.', (string) $environment['description']);
    $this->assertSame([NULL, []], [$environment['status'], $environment['checks']]);
  }

  /**
   * Runs the checks with the given updates, and returns the database check.
   *
   * @param int[] $system_updates
   *   The hook_update_N() versions of the system module. The installed schema
   *   of the system module is 11001.
   *
   * @return array|null
   *   The database updates check, or NULL if there are no checks.
   */
  protected function getDatabaseUpdatesCheck(array $system_updates): ?array {
    $update_hook_registry = $this->createMock(UpdateHookRegistry::class);
    $update_hook_registry->method('getAllInstalledVersions')->willReturn(['system' => 11001, 'user' => 8000]);
    $update_hook_registry->method('getAvailableUpdates')->willReturnCallback(fn (string $module) => $module === 'system' ? $system_updates : []);

    $checker = new EnvironmentChecker(
      $this->container->get('database'),
      $this->container->get('module_handler'),
      $this->container->get('keyvalue.expirable'),
      $this->container->get('upgrade_status.project_collector'),
      $this->container->get('request_stack'),
      $update_hook_registry,
      $this->container->get('theme_handler'),
      $this->container->get('keyvalue'),
      $this->container->getParameter('app.root'),
    );
    $environment = $checker->getChecks();
    return $this->findDatabaseUpdatesCheck($environment);
  }

}
