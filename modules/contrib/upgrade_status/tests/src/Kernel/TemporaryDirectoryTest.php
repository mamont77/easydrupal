<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\PhpStanAnalyzer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the temporary directory of the analysis.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class TemporaryDirectoryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * Tests that the directory is for this Drupal root and user.
   */
  public function testTemporaryDirectory(): void {
    $analyzer = $this->container->get('upgrade_status.phpstan_analyzer');
    $analyzer->initEnvironment();
    $directory = (new \ReflectionProperty($analyzer, 'temporaryDirectory'))->getValue($analyzer);

    $expected = $this->container->get('file_system')->getTempDirectory() . '/upgrade_status-' . substr(hash('sha256', DRUPAL_ROOT), 0, 12);
    if (function_exists('posix_geteuid')) {
      $expected .= '-' . posix_geteuid();
    }
    $this->assertSame($expected, $directory);
    $this->assertFileExists($directory . '/deprecation_testing.neon');
    $this->assertDirectoryExists($directory . '/phpstan');
  }

  /**
   * Tests that only the current user gets access to the directory.
   */
  public function testDirectoryAccess(): void {
    if (!function_exists('posix_geteuid')) {
      $this->markTestSkipped('The user of the directory is not known without the POSIX functions.');
    }
    $base = sys_get_temp_dir() . '/upgrade_status_test_' . $this->databasePrefix;
    $this->setSetting('file_temp_path', $base);
    $directory = $base . '/upgrade_status-' . substr(hash('sha256', DRUPAL_ROOT), 0, 12) . '-' . posix_geteuid();
    $neon = $directory . '/deprecation_testing.neon';
    // The service keeps a prepared environment, so each case has a new one.
    $init = function (): void {
      clearstatcache();
      $analyzer = new PhpStanAnalyzer(
        $this->container->get('logger.channel.upgrade_status'),
        $this->container->get('file_system'),
        $this->container->get('extension.list.module'),
        $this->container->get('cache.default'),
        $this->container->get('upgrade_status.file_finder'),
      );
      $analyzer->initEnvironment();
      clearstatcache();
    };

    try {
      $init();
      $this->assertSame(0700, fileperms($directory) & 0777);
      $this->assertSame(0700, fileperms($directory . '/phpstan') & 0777);

      // Earlier versions created the directory with access for the group.
      chmod($directory, 0775);
      $init();
      $this->assertSame(0700, fileperms($directory) & 0777);

      // A link in place of the configuration file is replaced, not followed.
      $target = $base . '/target.txt';
      file_put_contents($target, 'unchanged');
      unlink($neon);
      symlink($target, $neon);
      $init();
      $this->assertFalse(is_link($neon));
      $this->assertStringContainsString('tmpDir:', file_get_contents($neon));
      $this->assertSame('unchanged', file_get_contents($target));

      // A link in place of the directory is refused.
      rename($directory, $base . '/elsewhere');
      symlink($base . '/elsewhere', $directory);
      try {
        $init();
        $this->fail('A link in place of the directory was used.');
      }
      catch (\Exception $e) {
        $this->assertStringContainsString('is not a directory', $e->getMessage());
      }
    }
    finally {
      if (is_link($directory)) {
        unlink($directory);
      }
      $this->container->get('file_system')->deleteRecursive($base);
    }
  }

}
