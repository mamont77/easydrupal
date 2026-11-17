<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that test classes are not checked when PHPUnit is not installed.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class TestClassesWithoutPhpUnitTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'upgrade_status',
    'upgrade_status_test_12_compatible',
    'upgrade_status_test_test_classes',
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
    $this->installConfig(['system']);
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
   * Tests that test classes are checked when PHPUnit is installed.
   */
  public function testWithPhpUnit(): void {
    $result = $this->scan('upgrade_status_test_test_classes', TRUE);
    $this->assertFalse($result['data']['totals']['upgrade_status_test_classes_skipped']);
    $this->assertSame(['src/TestClassesHelper.php', 'tests/src/TestClassesExampleBase.php'], $this->getPhpStanFiles($result, 'upgrade_status_test_test_classes'));
  }

  /**
   * Tests that test classes are not checked without PHPUnit.
   */
  public function testWithoutPhpUnit(): void {
    $result = $this->scan('upgrade_status_test_test_classes', FALSE);
    $this->assertTrue($result['data']['totals']['upgrade_status_test_classes_skipped']);
    $this->assertSame(['src/TestClassesHelper.php'], $this->getPhpStanFiles($result, 'upgrade_status_test_test_classes'));

    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_test_classes');
    $formatter = $this->container->get('upgrade_status.result_formatter');
    $this->assertStringContainsString('Test classes were not checked, because PHPUnit is not installed. To check test classes, install the Drupal development dependencies, for example with `composer require --dev drupal/core-dev`.', $formatter->formatMarkdownResult($extension));
    $notes = implode(' ', array_map(fn ($note) => (string) $note['#markup'], $formatter->formatResult($extension, FALSE)['#notes']));
    $this->assertStringContainsString('for example with <code>composer require --dev drupal/core-dev</code>.', $notes);

    // A project without test classes has nothing to skip.
    $result = $this->scan('upgrade_status_test_12_compatible', FALSE);
    $this->assertFalse($result['data']['totals']['upgrade_status_test_classes_skipped']);
    $this->assertStringNotContainsString('Test classes were not checked', $formatter->formatMarkdownResult($this->container->get('extension.list.module')->get('upgrade_status_test_12_compatible')));
  }

  /**
   * Scans a module.
   *
   * @param string $name
   *   The name of the module.
   * @param bool $phpunit_installed
   *   Whether the analyzer should act as if PHPUnit was installed.
   *
   * @return array
   *   The scan result.
   */
  protected function scan(string $name, bool $phpunit_installed): array {
    $phpstan_analyzer = $this->container->get('upgrade_status.phpstan_analyzer');
    (new \ReflectionProperty($phpstan_analyzer, 'phpUnitInstalled'))->setValue($phpstan_analyzer, $phpunit_installed);
    $this->container->get('upgrade_status.deprecation_analyzer')->analyze($this->container->get('extension.list.module')->get($name));
    return $this->container->get('keyvalue')->get('upgrade_status_scan_results')->get($name);
  }

  /**
   * Gets the files with PHPStan messages, relative to the module directory.
   *
   * @param array $result
   *   The scan result.
   * @param string $name
   *   The name of the module.
   *
   * @return string[]
   *   The sorted file paths.
   */
  protected function getPhpStanFiles(array $result, string $name): array {
    $module_dir = DRUPAL_ROOT . '/' . $this->container->get('extension.list.module')->getPath($name) . '/';
    $files = [];
    foreach ($result['data']['files'] as $file => $errors) {
      if (array_filter($errors['messages'], static fn (array $error): bool => $error['analyzer'] === 'PHPStan')) {
        $files[] = str_replace($module_dir, '', $file);
      }
    }
    sort($files);
    return $files;
  }

}
