<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Messenger\MessengerInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\AnalysisBatch;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests setting the batch that analyzes projects.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class AnalysisBatchTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status', 'upgrade_status_test_error'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    require_once $this->root . '/core/includes/form.inc';
    $this->setSetting('file_temp_path', $this->siteDirectory . '/temp');
    $this->setSetting('upgrade_status_php_binary', PHP_BINARY);
  }

  /**
   * Tests that a batch is only set when the analysis can run.
   */
  public function testStart(): void {
    $project = $this->container->get('upgrade_status.project_collector')->loadProject('upgrade_status_test_error');
    $messenger = $this->container->get('messenger');

    // Nothing to analyze.
    $this->container->get('upgrade_status.analysis_batch')->start([]);
    $this->assertEmpty(batch_get());
    $this->assertSame(['No projects selected to analyze.'], array_map('strval', $messenger->messagesByType(MessengerInterface::TYPE_ERROR)));
    $messenger->deleteAll();

    // One operation for each project. There is no web server in this test, so
    // the project is analyzed in the request of the batch.
    $this->container->get('upgrade_status.analysis_batch')->start([$project]);
    $batch = batch_get();
    $this->assertSame([[AnalysisBatch::class . '::analyzeProject', [$project, FALSE]]], $batch['sets'][0]['operations']);
    $this->assertSame(AnalysisBatch::class . '::finished', $batch['sets'][0]['finished']);
    $this->assertSame([], $messenger->all());
  }

  /**
   * Tests that no batch is set when the analyzer cannot run.
   */
  public function testEnvironmentNotReady(): void {
    // A file that is not a PHP binary.
    $this->setSetting('upgrade_status_php_binary', __FILE__);
    $project = $this->container->get('upgrade_status.project_collector')->loadProject('upgrade_status_test_error');

    $this->container->get('upgrade_status.analysis_batch')->start([$project]);
    $this->assertEmpty(batch_get());
    $errors = $this->container->get('messenger')->messagesByType(MessengerInterface::TYPE_ERROR);
    $this->assertCount(1, $errors);
    $this->assertStringEndsWith(' Analysis is not possible until this is resolved.', (string) $errors[0]);
  }

}
