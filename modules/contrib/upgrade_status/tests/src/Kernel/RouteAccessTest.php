<?php

declare(strict_types=1);

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Routing\RouteMatch;
use Drupal\KernelTests\KernelTestBase;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\upgrade_status\AnalysisBatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests the access requirements of the routes.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class RouteAccessTest extends KernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'upgrade_status'];

  /**
   * Tests the permission of all routes and the token of the analyze route.
   */
  public function testRouteAccess(): void {
    $this->installEntitySchema('user');
    $routes = [];
    foreach ($this->container->get('router.route_provider')->getAllRoutes() as $name => $route) {
      if (str_starts_with($name, 'upgrade_status.')) {
        $routes[$name] = $route;
      }
    }
    $this->assertGreaterThanOrEqual(5, count($routes));

    // All routes need the permission, also the ones only used with POST.
    $parameters = ['project_machine_name' => 'upgrade_status', 'format' => 'csv'];
    $access_manager = $this->container->get('access_manager');
    // The first user has all permissions.
    $this->createUser();
    $without_permission = $this->createUser();
    $with_permission = $this->createUser(['administer software updates']);
    foreach ($routes as $name => $route) {
      $this->assertSame('administer software updates', $route->getRequirement('_permission'), $name);
      $route_parameters = array_intersect_key($parameters, array_flip($route->compile()->getVariables()));
      $this->assertFalse($access_manager->checkNamedRoute($name, $route_parameters, $without_permission), $name);
      // The token of the analyze route is tested below.
      if ($name !== 'upgrade_status.analyze') {
        $this->assertTrue($access_manager->checkNamedRoute($name, $route_parameters, $with_permission), $name);
      }
    }

    // The analyze route starts an analysis, so it also needs a CSRF token.
    $route = $routes['upgrade_status.analyze'];
    $this->assertSame('TRUE', $route->getRequirement('_csrf_token'));
    $route_match = new RouteMatch('upgrade_status.analyze', $route, ['project_machine_name' => 'upgrade_status'], ['project_machine_name' => 'upgrade_status']);
    $check = $this->container->get('access_check.csrf');
    $request = Request::create('/admin/reports/upgrade-status/analyze/upgrade_status', 'POST');
    $this->assertFalse($check->access($route, $request, $route_match)->isAllowed());
    $request = Request::create('/admin/reports/upgrade-status/analyze/upgrade_status?token=wrong', 'POST');
    $this->assertFalse($check->access($route, $request, $route_match)->isAllowed());

    // The URL of the request of the module has a token that the check accepts.
    // The URL generator alone only adds a placeholder while a page is built.
    $url = AnalysisBatch::getAnalyzeUrl('upgrade_status');
    $request = Request::create($url->toString(), 'POST');
    $this->assertNotEmpty($request->query->get('token'));
    $this->assertTrue($check->access($route, $request, $route_match)->isAllowed());
    // The token is for one project.
    $url = AnalysisBatch::getAnalyzeUrl('other_project');
    $request = Request::create($url->toString(), 'POST');
    $this->assertFalse($check->access($route, $request, $route_match)->isAllowed());
  }

}
