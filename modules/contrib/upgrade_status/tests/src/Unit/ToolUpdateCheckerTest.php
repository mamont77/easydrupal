<?php

namespace Drupal\Tests\upgrade_status\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\upgrade_status\ToolUpdateChecker;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which version of a tool is suggested as an update.
 *
 * @group upgrade_status
 */
#[Group('upgrade_status')]
final class ToolUpdateCheckerTest extends UnitTestCase {

  /**
   * Tests finding the newest version in the same major version.
   */
  public function testFindUpdate(): void {
    $core = \Drupal::VERSION;
    $newer_php = [['version' => '2.2.0', 'php' => '>=99'], ['version' => '2.1.0', 'php' => '>=7.4']];
    $other_core = [['version' => '2.2.0', 'core' => '^99'], ['version' => '2.1.0', 'core' => "^$core"]];
    $cases = [
      'newest in the same major' => [
        [['version' => '2.0.5'], ['version' => '2.3.1'], ['version' => '3.0.0']],
        '2.0.4',
        '2.3.1',
      ],
      'already newest' => [[['version' => '2.0.4'], ['version' => '2.0.3']], '2.0.4', NULL],
      'only a newer major' => [[['version' => '3.0.0']], '2.0.4', NULL],
      'v prefix' => [[['version' => 'v2.1.0']], 'v2.0.0', '2.1.0'],
      'drupal.org prefix' => [[['version' => '8.x-1.5']], '8.x-1.4', '1.5'],
      'development versions' => [[['version' => '2.x-dev'], ['version' => 'dev-main']], '2.0.0', NULL],
      'development installed' => [[['version' => '2.1.0']], '2.x-dev', NULL],
      'unknown installed' => [[['version' => '2.1.0']], '', NULL],
      'pre-release for stable' => [[['version' => '2.1.0-beta1'], ['version' => '2.0.1']], '2.0.0', '2.0.1'],
      'pre-release for pre-release' => [[['version' => '2.1.0-rc1']], '2.1.0-beta1', '2.1.0-rc1'],
      'needs a newer PHP' => [$newer_php, '2.0.0', '2.1.0'],
      'needs another core' => [$other_core, '2.0.0', '2.1.0'],
      'invalid constraint' => [[['version' => '2.1.0', 'php' => 'not a constraint']], '2.0.0', NULL],
    ];
    foreach ($cases as $label => [$versions, $installed, $expected]) {
      $this->assertSame($expected, ToolUpdateChecker::findUpdate($versions, $installed), $label);
    }
  }

}
