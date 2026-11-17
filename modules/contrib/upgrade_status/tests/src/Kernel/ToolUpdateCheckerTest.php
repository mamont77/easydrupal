<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Composer\InstalledVersions;
use Drupal\Core\Batch\BatchBuilder;
use Drupal\KernelTests\KernelTestBase;
use Drupal\update\UpdateManagerInterface;
use Drupal\upgrade_status\ToolUpdateChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Psr\Http\Message\RequestInterface;

/**
 * Tests the checks for updates of the PHPStan packages.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ToolUpdateCheckerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'update',
    'upgrade_status',
  ];

  /**
   * The URLs requested from Packagist.
   */
  protected array $requests = [];

  /**
   * Whether Packagist can't be reached.
   */
  protected bool $offline = FALSE;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['update']);
    $this->container->set('http_client', new Client(['handler' => HandlerStack::create($this->packagist(...))]));
  }

  /**
   * Tests that releases are only fetched on cron and read later.
   */
  public function testRefresh(): void {
    $expected = [];
    $packages = 0;
    foreach (['phpstan/phpstan', 'mglaman/phpstan-drupal', 'phpstan/phpstan-deprecation-rules'] as $package) {
      if (InstalledVersions::isInstalled($package)) {
        $packages++;
        $installed = (string) InstalledVersions::getPrettyVersion($package);
        if (!str_contains($installed, 'dev')) {
          $expected[$package] = [$installed, $this->major($installed) . '.999.0'];
        }
      }
    }
    if (!$expected) {
      $this->markTestSkipped('No released version of the PHPStan packages is installed.');
    }
    $checker = $this->container->get('upgrade_status.tool_update_checker');

    // Nothing is fetched to find updates.
    $this->assertSame([], array_intersect_key($checker->getUpdates(), $expected));
    $this->assertSame([], $this->requests);

    // Cron fetches the releases at the update module's interval.
    $module_handler = $this->container->get('module_handler');
    $module_handler->invoke('upgrade_status', 'cron');
    $this->assertCount($packages, $this->requests);
    $this->assertSame($expected, array_intersect_key($checker->getUpdates(), $expected));
    $module_handler->invoke('upgrade_status', 'cron');
    $this->assertCount($packages, $this->requests);

    // The releases fetched before are kept if Packagist can't be reached.
    $this->offline = TRUE;
    $checker->refresh();
    $this->assertCount($packages * 2, $this->requests);
    $this->assertSame($expected, array_intersect_key($checker->getUpdates(), $expected));

    $message = (string) $checker->formatUpdates($expected);
    $this->assertStringContainsString('<code>composer update ' . implode(' ', array_keys($expected)) . ' --with-dependencies</code>', $message);

    // The check can be turned off.
    $this->setSetting('upgrade_status_check_tool_updates', FALSE);
    $this->assertSame([], $checker->getUpdates());
    $checker->refresh();
    $this->assertCount($packages * 2, $this->requests);
  }

  /**
   * Tests the installed versions of the analysis tools and their format.
   */
  public function testInstalledVersions(): void {
    $checker = $this->container->get('upgrade_status.tool_update_checker');
    $versions = $checker->getInstalledVersions();
    // Upgrade Status is first, and the PHPStan packages follow.
    $this->assertSame('drupal/upgrade_status', array_key_first($versions));
    $this->assertSame(InstalledVersions::getPrettyVersion('phpstan/phpstan'), $versions['phpstan/phpstan']);
    $this->assertSame(InstalledVersions::getPrettyVersion('mglaman/phpstan-drupal'), $versions['mglaman/phpstan-drupal']);
    // The versions do not depend on the check for updates.
    $this->setSetting('upgrade_status_check_tool_updates', FALSE);
    $this->assertSame($versions, $checker->getInstalledVersions());

    $this->assertSame(
      'Upgrade Status (version not known), PHPStan 2.1.0, phpstan-drupal 2.0.1, other/package 1.0',
      $checker->formatVersions([
        'drupal/upgrade_status' => '',
        'phpstan/phpstan' => '2.1.0',
        'mglaman/phpstan-drupal' => '2.0.1',
        'other/package' => '1.0',
      ])
    );

    // Results without versions are from before the versions were stored, so
    // nothing is known about them.
    $this->assertFalse($checker->isOtherThanInstalled(NULL));
    $this->assertFalse($checker->isOtherThanInstalled($versions));
    $this->assertFalse($checker->isOtherThanInstalled(array_reverse($versions, TRUE)));
    $this->assertTrue($checker->isOtherThanInstalled(['phpstan/phpstan' => '0.0.1'] + $versions));
    $this->assertTrue($checker->isOtherThanInstalled(array_slice($versions, 0, 1, TRUE)));
  }

  /**
   * Tests that releases are fetched with the manual check of updates.
   */
  public function testManualCheck(): void {
    // The update module's controller uses a service callback in newer Drupal
    // versions, and an object callback in older ones.
    $callbacks = [
      [$this->container->get('update.manager'), 'fetchDataBatch'],
      UpdateManagerInterface::class . ':fetchDataBatch',
    ];
    foreach ($callbacks as $callback) {
      // The batch set is an array, because BatchBuilder of Drupal 10 only
      // takes callables, not service callbacks like the second one.
      $batch = [
        'sets' => [
          ['operations' => [[$callback, []]], 'total' => 1, 'count' => 1],
        ],
      ];
      $this->container->get('module_handler')->alter('batch', $batch);
      $this->assertSame([[ToolUpdateChecker::class, 'refreshBatch'], []], $batch['sets'][0]['operations'][1]);
      $this->assertSame(2, $batch['sets'][0]['total']);
      $this->assertSame(2, $batch['sets'][0]['count']);
    }

    // Other batches are not changed.
    $other = ['sets' => [(new BatchBuilder())->addOperation('strlen', [])->toArray() + ['total' => 1, 'count' => 1]]];
    $batch = $other;
    $this->container->get('module_handler')->alter('batch', $batch);
    $this->assertSame($other, $batch);

    // The batch operation fetches the releases.
    $context = [];
    ToolUpdateChecker::refreshBatch($context);
    $this->assertSame(1, $context['finished']);
    $this->assertNotEmpty($this->requests);
  }

  /**
   * Responds to Packagist requests, with minified metadata.
   *
   * @param \Psr\Http\Message\RequestInterface $request
   *   The request.
   *
   * @return \GuzzleHttp\Promise\PromiseInterface
   *   The response.
   */
  public function packagist(RequestInterface $request) {
    $this->requests[] = (string) $request->getUri();
    if ($this->offline) {
      return Create::rejectionFor(new ConnectException('Offline', $request));
    }
    $package = preg_replace('@^/p2/(.+)\.json$@', '$1', $request->getUri()->getPath());
    $major = $this->major((string) InstalledVersions::getPrettyVersion($package));
    $versions = [
      // A version for a newer PHP version than any.
      ['version' => "$major.1000.0", 'require' => ['php' => '>=99']],
      ['version' => "$major.999.0", 'require' => ['php' => '>=7.4']],
      // This also needs PHP 7.4, from the version before.
      ['version' => "$major.998.0"],
      ['version' => ($major + 1) . '.0.0'],
      ['version' => "$major.997.0", 'require' => '__unset'],
    ];
    $body = json_encode(['minified' => 'composer/2.0', 'packages' => [$package => $versions]]);
    return Create::promiseFor(new Response(200, [], $body));
  }

  /**
   * Gets the major version of a version.
   *
   * @param string $version
   *   The version.
   *
   * @return int
   *   The major version.
   */
  protected function major(string $version): int {
    return (int) ltrim($version, 'v');
  }

}
