<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ScanResultStorage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the storage of analysis results.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class ScanResultStorageTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * Tests storing results and reading their totals.
   */
  public function testResults(): void {
    $storage = $this->container->get('upgrade_status.result_storage');
    $this->assertNull($storage->get('foo'));
    $this->assertNull($storage->getTotals('foo'));

    $result = [
      'date' => 100,
      'data' => ['totals' => ['errors' => 2], 'files' => ['foo.php' => ['messages' => []]]],
    ];
    $storage->set('foo', $result);
    // Results are stored with the installed versions of the analysis tools.
    $versions = $this->container->get('upgrade_status.tool_update_checker')->getInstalledVersions();
    $this->assertArrayHasKey('phpstan/phpstan', $versions);
    $this->assertSame($result + ['versions' => $versions], $storage->get('foo'));
    // Storing results forgets the totals that were loaded before.
    $this->assertSame(['date' => 100, 'totals' => ['errors' => 2], 'versions' => $versions], $storage->getTotals('foo'));

    // Results that another request stored are found after a reset. Results
    // without versions are from before the versions were stored.
    $result['data']['totals']['errors'] = 3;
    $this->container->get('keyvalue')->get(ScanResultStorage::COLLECTION)->set('foo', $result);
    $this->assertSame(2, $storage->getTotals('foo')['totals']['errors']);
    $storage->resetTotals('foo');
    $this->assertSame(3, $storage->getTotals('foo')['totals']['errors']);
    $this->assertNull($storage->getTotals('foo')['versions']);

    // Versions that come with the results are kept.
    $storage->set('foo', $result + ['versions' => ['phpstan/phpstan' => '1.0.0']]);
    $this->assertSame(['phpstan/phpstan' => '1.0.0'], $storage->get('foo')['versions']);
    $this->assertSame(['phpstan/phpstan' => '1.0.0'], $storage->getTotals('foo')['versions']);

    // Totals are loaded for many projects at once.
    $storage->set('bar', ['date' => 200, 'data' => ['totals' => ['errors' => 1]]]);
    $storage->resetTotals();
    $storage->loadTotals(['foo', 'bar', 'baz']);
    $this->container->get('keyvalue')->get(ScanResultStorage::COLLECTION)->deleteAll();
    $this->assertSame(3, $storage->getTotals('foo')['totals']['errors']);
    $this->assertSame(200, $storage->getTotals('bar')['date']);
    $this->assertNull($storage->getTotals('baz'));

    $storage->deleteAll();
    $this->assertNull($storage->getTotals('foo'));
    $this->assertNull($storage->get('bar'));
  }

  /**
   * Tests that the update to this version removes the stored results.
   */
  public function testPostUpdate(): void {
    $store = $this->container->get('keyvalue')->get(ScanResultStorage::COLLECTION);
    // 4.x stored results without the versions of the analysis tools, and
    // with the categories of that version.
    $store->set('foo', [
      'date' => 100,
      'data' => [
        'totals' => ['file_errors' => 1, 'upgrade_status_next' => 'manual'],
        'files' => [
          'foo.info.yml' => [
            'messages' => [
              [
                'message' => 'Value of core_version_requirement',
                'line' => 1,
                'upgrade_status_category' => 'uncategorized',
              ],
            ],
          ],
        ],
      ],
    ]);
    $storage = $this->container->get('upgrade_status.result_storage');
    $storage->set('bar', ['date' => 200, 'data' => ['totals' => ['errors' => 1]]]);
    $this->assertCount(2, $store->getAll());
    // The values of other collections are kept.
    $this->container->get('keyvalue')->get('upgrade_status_test')->set('foo', 'bar');

    require_once $this->root . '/' . $this->getModulePath('upgrade_status') . '/upgrade_status.post_update.php';
    $message = upgrade_status_post_update_remove_results_of_older_versions();
    $this->assertSame([], $store->getAll());
    $storage->resetTotals();
    $this->assertNull($storage->get('foo'));
    $this->assertNull($storage->getTotals('bar'));
    $this->assertSame('bar', $this->container->get('keyvalue')->get('upgrade_status_test')->get('foo'));
    $this->assertStringContainsString('Analyze your projects again.', (string) $message);
  }

  /**
   * Tests the results of a failed analysis.
   */
  public function testFailure(): void {
    $storage = $this->container->get('upgrade_status.result_storage');
    $storage->setFailure('foo', 'PHP Fatal error', 'Allowed memory size exhausted');

    $result = $storage->get('foo');
    $this->assertSame($this->container->get('datetime.time')->getRequestTime(), $result['date']);
    $this->assertSame($this->container->get('upgrade_status.tool_update_checker')->getInstalledVersions(), $result['versions']);
    $this->assertSame(['errors' => 1, 'file_errors' => 1, 'upgrade_status_split' => ['warning' => 1]], $result['data']['totals']);
    $this->assertSame(
      [
        'PHP Fatal error' => [
          'errors' => 1,
          'messages' => [['message' => 'Allowed memory size exhausted', 'line' => 0]],
        ],
      ],
      $result['data']['files'],
    );
    $this->assertSame(1, $storage->getTotals('foo')['totals']['errors']);
  }

}
