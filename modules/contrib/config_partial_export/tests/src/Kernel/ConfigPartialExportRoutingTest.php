<?php

namespace Drupal\Tests\config_partial_export\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Symfony\Component\Routing\Exception\RouteNotFoundException;

/**
 * Tests the route definitions of config_partial_export.
 *
 * KernelTestBase replaces router.route_provider with a lazy wrapper
 * (\Drupal\KernelTests\RouteProvider) that automatically calls
 * router.builder->rebuild() on the first lookup, so no explicit rebuild
 * call is needed here.
 *
 * Routes under test (from config_partial_export.routing.yml):
 *   - config_partial.export_partial
 *   - config_partial.export_partial_download
 *
 * @group config_partial_export
 */
class ConfigPartialExportRoutingTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config_partial_export'];

  /**
   * Route names defined by the module.
   */
  const ROUTE_FORM = 'config_partial.export_partial';
  const ROUTE_DOWNLOAD = 'config_partial.export_partial_download';

  /**
   * Expected paths for each route.
   */
  const PATH_FORM = '/admin/config/development/configuration/single/config-partial-export';
  const PATH_DOWNLOAD = '/admin/config/development/configuration/single/partial-export-download';

  /**
   * The permission both routes must require.
   */
  const REQUIRED_PERMISSION = 'export configuration';

  /**
   * The route provider (lazy-rebuilds on first use in Kernel tests).
   *
   * @var \Drupal\Core\Routing\RouteProviderInterface
   */
  protected $routeProvider;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->routeProvider = $this->container->get('router.route_provider');
  }

  /**
   * Tests that both module routes are discoverable by name.
   */
  public function testRoutesExist(): void {
    // getRouteByName() throws RouteNotFoundException for unknown routes,
    // so a successful return is itself an assertion of existence.
    $form_route = $this->routeProvider->getRouteByName(self::ROUTE_FORM);
    $this->assertNotNull($form_route, self::ROUTE_FORM . ' is registered in the router.');

    $download_route = $this->routeProvider->getRouteByName(self::ROUTE_DOWNLOAD);
    $this->assertNotNull($download_route, self::ROUTE_DOWNLOAD . ' is registered in the router.');
  }

  /**
   * Tests that both routes are not accessible without the required permission.
   *
   * The routing.yml specifies `_permission: 'export configuration'` for both
   * routes. This test reads that requirement directly from the Route object.
   */
  public function testRoutesRequireExportConfigurationPermission(): void {
    $form_route = $this->routeProvider->getRouteByName(self::ROUTE_FORM);
    $this->assertSame(
      self::REQUIRED_PERMISSION,
      $form_route->getRequirement('_permission'),
      self::ROUTE_FORM . " requires the '" . self::REQUIRED_PERMISSION . "' permission."
    );

    $download_route = $this->routeProvider->getRouteByName(self::ROUTE_DOWNLOAD);
    $this->assertSame(
      self::REQUIRED_PERMISSION,
      $download_route->getRequirement('_permission'),
      self::ROUTE_DOWNLOAD . " requires the '" . self::REQUIRED_PERMISSION . "' permission."
    );
  }

  /**
   * Tests that both routes resolve to their expected URL paths.
   */
  public function testRoutePathsAreCorrect(): void {
    $form_route = $this->routeProvider->getRouteByName(self::ROUTE_FORM);
    $this->assertSame(
      self::PATH_FORM,
      $form_route->getPath(),
      self::ROUTE_FORM . ' maps to the expected path.'
    );

    $download_route = $this->routeProvider->getRouteByName(self::ROUTE_DOWNLOAD);
    $this->assertSame(
      self::PATH_DOWNLOAD,
      $download_route->getPath(),
      self::ROUTE_DOWNLOAD . ' maps to the expected path.'
    );
  }

  /**
   * Tests that a non-existent route throws the expected exception.
   *
   * Serves as a sanity check that the route provider is genuinely querying
   * the router (not silently returning NULL for anything).
   */
  public function testNonExistentRouteThrows(): void {
    $this->expectException(RouteNotFoundException::class);
    $this->routeProvider->getRouteByName('config_partial_export.does_not_exist');
  }

}
