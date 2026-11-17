<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Composer\InstalledVersions;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that PHPStan is found with Composer's runtime data.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class PhpStanPathTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * Tests the path of the PHPStan script.
   */
  public function testPhpStanPath(): void {
    $analyzer = $this->container->get('upgrade_status.phpstan_analyzer');
    $analyzer->initEnvironment();
    $path = (new \ReflectionProperty($analyzer, 'phpstanPath'))->getValue($analyzer);

    // The script in the package is used, not the proxy in the bin directory.
    $this->assertSame(realpath(InstalledVersions::getInstallPath('phpstan/phpstan') . '/phpstan'), $path);
    $this->assertStringStartsWith('#!/usr/bin/env php', file_get_contents($path));
  }

}
