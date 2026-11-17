<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding uses of deprecated routes.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class RouteDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'user',
  ];

  /**
   * Tests the messages for a module that uses deprecated routes.
   */
  public function testRoutes(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_routes');
    $path = $extension->getPath();
    $old = 'The upgrade_status_test_routes_provider.old route is used. Deprecated in drupal:10.0.0 and is removed from drupal:11.0.0. Use "upgrade_status_test_routes_provider.new" instead. See https://www.drupal.org/node/3317784';
    $alias = 'The upgrade_status_test_routes_provider.alias route is used. Deprecated in drupal:10.0.0.';

    $sut = $this->container->get('upgrade_status.route_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Routes that are not deprecated, longer route names, comments, YAML keys
    // and other text in YAML values are not reported.
    $expected = [
      [$old, "$path/upgrade_status_test_routes.module", 15],
      [$alias, "$path/upgrade_status_test_routes.module", 16],
      [$old, "$path/upgrade_status_test_routes.links.menu.yml", 3],
      [$alias, "$path/upgrade_status_test_routes.links.task.yml", 4],
      [$old, "$path/upgrade_status_test_routes.routing.yml", 2],
      [$old, "$path/templates/upgrade-status-test-routes.html.twig", 7],
      [$old, "$path/config/install/upgrade_status_test_routes.settings.yml", 2],
    ];
    $this->assertCount(count($expected), $deprecations, var_export($deprecations, TRUE));
    foreach ($expected as [$message, $file, $line]) {
      $this->assertContainsEquals(new DeprecationMessage($message, $file, $line, 'RouteDeprecationAnalyzer'), $deprecations);
    }
  }

  /**
   * Tests that a project can use the routes that it deprecates.
   */
  public function testOwnRoutes(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_routes_provider');
    $sut = $this->container->get('upgrade_status.route_deprecation_analyzer');
    $this->assertSame([], $sut->analyze($extension));
  }

}
