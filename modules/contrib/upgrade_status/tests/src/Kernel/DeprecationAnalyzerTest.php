<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the stored result of a scan.
 *
 * The messages themselves are tested in the tests of each analyzer. This test
 * checks how they are combined, counted and stored.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class DeprecationAnalyzerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'upgrade_status',
    // The library analyzer reports modules that are not installed.
    'upgrade_status_test_13_compatible',
  ];

  /**
   * A real temporary directory, because PHPStan runs in another process.
   */
  protected string $temporaryDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->temporaryDirectory = sys_get_temp_dir() . '/upgrade_status_test_' . $this->databasePrefix;
    $this->setSetting('file_temp_path', $this->temporaryDirectory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->container->get('file_system')->deleteRecursive($this->temporaryDirectory);
    parent::tearDown();
  }

  /**
   * Tests the totals and the next step of a project with problems.
   */
  public function testProjectWithProblems(): void {
    $result = $this->scan('upgrade_status_test_error');
    $messages = $this->getMessages($result);

    // Messages of PHPStan and of the other analyzers are stored together.
    $analyzers = array_unique(array_column($messages, 'analyzer'));
    $this->assertContains('PHPStan', $analyzers);
    $this->assertContains('ExtensionMetadataDeprecationAnalyzer', $analyzers);
    foreach ($messages as $message) {
      $this->assertNotEmpty($message['upgrade_status_category']);
    }

    // The totals are counted from the messages.
    $totals = $result['data']['totals'];
    $categories = array_count_values(array_column($messages, 'upgrade_status_category'));
    $this->assertSame(count($messages), $totals['file_errors']);
    $this->assertSame($categories, $totals['upgrade_status_category']);
    $this->assertSame(($categories['safe'] ?? 0) + ($categories['old'] ?? 0) + ($categories['rector'] ?? 0), $totals['upgrade_status_split']['error'] ?? 0);
    $warning_categories = ['later', 'uncategorized', 'requirements', 'contrib'];
    $warnings = array_sum(array_intersect_key($categories, array_flip($warning_categories)));
    $this->assertSame($warnings, $totals['upgrade_status_split']['warning'] ?? 0);

    // The info.yml file has no core_version_requirement, so the project is
    // not declared ready. drupal-rector can't fix any of the problems.
    $this->assertFalse($totals['upgrade_status_split']['declared_ready']);
    $this->assertSame(ProjectCollector::NEXT_MANUAL, $totals['upgrade_status_next']);
    $this->assertFalse($totals['upgrade_status_test_classes_skipped']);
  }

  /**
   * Tests the result of a project without problems.
   */
  public function testCompatibleProject(): void {
    $totals = $this->scan('upgrade_status_test_13_compatible')['data']['totals'];
    $this->assertSame(0, $totals['file_errors']);
    $this->assertTrue($totals['upgrade_status_split']['declared_ready']);
    $this->assertSame(ProjectCollector::NEXT_RELAX, $totals['upgrade_status_next']);
  }

  /**
   * Tests the result when PHPStan fails.
   */
  public function testPhpStanFailed(): void {
    // An invalid memory limit makes PHPStan fail without JSON output. PHPStan
    // only runs for projects with PHP files.
    $result = $this->scan('upgrade_status_test_test_classes', ['phpstan-memory-limit' => "invalid\x1B[31m"]);
    $this->assertArrayHasKey('PHPStan failed', $result['data']['files']);
    $message = $result['data']['files']['PHPStan failed']['messages'][0];
    $this->assertStringStartsWith('PHPStan command failed: ', $message['message']);
    // The message is plain text, without the control character of the option
    // and without the paths of the site on the server.
    $this->assertStringContainsString('--memory-limit=invalid[31m', $message['message']);
    $this->assertStringNotContainsString("\x1B", $message['message']);
    $this->assertStringNotContainsString('<h6>', $message['message']);
    $this->assertStringContainsString('[Drupal root]/', $message['message']);
    $this->assertStringContainsString('[temporary directory]/', $message['message']);
    $this->assertStringNotContainsString(DRUPAL_ROOT, $message['message']);
    $this->assertStringNotContainsString($this->temporaryDirectory, $message['message']);
    $this->assertSame('PHPStan', $message['analyzer']);
    $this->assertSame('uncategorized', $message['upgrade_status_category']);
    // The failure is counted with the messages of the other analyzers.
    $this->assertCount(1, $result['data']['files']['PHPStan failed']['messages']);
    $this->assertSame(count($this->getMessages($result)), $result['data']['totals']['file_errors']);
    $this->assertSame(ProjectCollector::NEXT_MANUAL, $result['data']['totals']['upgrade_status_next']);
  }

  /**
   * Scans a module and returns the stored result.
   *
   * @param string $name
   *   The name of the module.
   * @param array $options
   *   The options of the scan.
   *
   * @return array
   *   The stored result.
   */
  protected function scan(string $name, array $options = []): array {
    $analyzer = $this->container->get('upgrade_status.deprecation_analyzer');
    $analyzer->initEnvironment();
    $analyzer->analyze($this->container->get('extension.list.module')->get($name), $options);
    return $this->container->get('keyvalue')->get('upgrade_status_scan_results')->get($name);
  }

  /**
   * Gets all messages of a result.
   *
   * @param array $result
   *   The stored result.
   *
   * @return array[]
   *   The messages.
   */
  protected function getMessages(array $result): array {
    $messages = [];
    foreach ($result['data']['files'] as $errors) {
      array_push($messages, ...$errors['messages']);
    }
    return $messages;
  }

}
