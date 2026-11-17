<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding the PHP binary that runs PHPStan.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class PhpBinaryTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * Tests the PHP binary from settings.php and the checks of the binary.
   */
  public function testPhpBinary(): void {
    $analyzer = $this->container->get('upgrade_status.phpstan_analyzer');
    $method = new \ReflectionMethod($analyzer, 'findPhpPath');

    // Without the setting, the binary that runs the tests is found.
    $this->assertSame(realpath(PHP_BINARY), realpath($method->invoke($analyzer)));

    // The setting is used first.
    $this->setSetting('upgrade_status_php_binary', PHP_BINARY);
    $this->assertSame(PHP_BINARY, $method->invoke($analyzer));

    // Another binary is run once to get its version, which is then kept,
    // because the report page prepares the environment on every view.
    $link = sys_get_temp_dir() . '/upgrade_status_test_php_' . $this->databasePrefix;
    symlink(PHP_BINARY, $link);
    try {
      $this->setSetting('upgrade_status_php_binary', $link);
      $this->assertSame($link, $method->invoke($analyzer));
      $cid = 'upgrade_status:php_binary:' . hash('sha256', implode(':', [$link, filemtime($link), filesize($link)]));
      $this->assertSame(PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION, $this->container->get('cache.default')->get($cid)->data);
    }
    finally {
      unlink($link);
    }

    // Something that is not PHP is not used.
    $not_php = $this->root . '/index.php';
    $this->setSetting('upgrade_status_php_binary', $not_php);
    try {
      $method->invoke($analyzer);
      $this->fail('A file that is not PHP was used as the PHP binary.');
    }
    catch (\Exception $e) {
      $this->assertSame("The PHP binary $not_php is not a working PHP command line binary. Set \$settings['upgrade_status_php_binary'] in settings.php to the PHP command line binary, like /usr/bin/php.", $e->getMessage());
    }
  }

}
