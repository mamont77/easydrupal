<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the check of the PHP requirement in composer.json.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class PhpRequirementTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_php_requirement',
  ];

  /**
   * Tests the minimum PHP version of the next major version of Drupal.
   */
  public function testNextMajorMinimumPhp(): void {
    // Drupal 13's requirements are not known yet, so Drupal 12's are used.
    $expected = ProjectCollector::getDrupalCoreMajorVersion() === 10 ? '8.3.0' : '8.5.0';
    $this->assertSame($expected, ProjectCollector::getNextMajorMinimumPhp());

    // A requirement is compatible if it allows the version or a later one.
    $this->assertTrue(ProjectCollector::isCompatibleWithPhp('>=7.4', '8.5.0'));
    $this->assertTrue(ProjectCollector::isCompatibleWithPhp('^8.1', '8.5.0'));
    $this->assertTrue(ProjectCollector::isCompatibleWithPhp('^8.3 || ^9', '8.5.0'));
    $this->assertFalse(ProjectCollector::isCompatibleWithPhp('~8.1.0', '8.5.0'));
    $this->assertFalse(ProjectCollector::isCompatibleWithPhp('>=8.1 <8.5', '8.5.0'));
  }

  /**
   * Tests the message about the PHP requirement in composer.json.
   */
  public function testComposerPhpRequirement(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_php_requirement');
    $deprecations = $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer')->analyze($extension);
    $messages = array_map(static fn ($deprecation): string => $deprecation->getFile() . ':' . $deprecation->getLine() . ' ' . $deprecation->getMessage(), $deprecations);

    // The module allows PHP 8.3, which Drupal 11 requires, but not PHP 8.5,
    // which Drupal 12 requires.
    $expected = ProjectCollector::getDrupalCoreMajorVersion() === 10 ? [] : [
      $extension->getPath() . '/composer.json:1 The PHP requirement (>=8.1 <8.5) is not compatible with PHP 8.5, which the next major version of Drupal requires. Once the code works with PHP 8.5, update the requirement to allow it.',
    ];
    $this->assertSame($expected, $messages);
  }

}
