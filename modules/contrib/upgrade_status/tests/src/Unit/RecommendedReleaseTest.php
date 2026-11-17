<?php

namespace Drupal\Tests\upgrade_status\Unit;

use Drupal\Tests\UnitTestCase;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests which release is recommended to update to.
 *
 * @group upgrade_status
 */
#[Group('upgrade_status')]
final class RecommendedReleaseTest extends UnitTestCase {

  /**
   * Tests the order of the release choices.
   */
  public function testRecommendedRelease(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $current = "^$major";
    $both = "^$major || ^" . ($major + 1);
    $next_only = '^' . ($major + 1);
    $old_only = '^' . ($major - 1);

    $cases = [
      // A newer branch with only an alpha for older core versions, like
      // Display Suite 5.0.0-alpha3, comes first. The older branch has a
      // release for the next major version.
      'newer branch for older core' => [
        [$this->release('5.0.0-alpha3', "$old_only || $current"), $this->release('8.x-3.37', $both)],
        '8.x-3.37',
      ],
      // A beta of a newer branch, like Rabbit Hole 2.0.0-beta3, is not
      // recommended if a stable release works with both versions.
      'stable before beta' => [
        [$this->release('2.0.0-beta3', $both), $this->release('8.x-1.5', $both)],
        '8.x-1.5',
      ],
      // A pre-release is recommended if it is the only one that works with
      // the next major version.
      'only a pre-release is ready' => [
        [$this->release('2.0.0-rc1', $both), $this->release('8.x-1.5', $current)],
        '2.0.0-rc1',
      ],
      // The newest release needs a newer core version than the site has.
      'newest needs newer core' => [
        [$this->release('3.0.0', $next_only), $this->release('2.1.0', $current)],
        '2.1.0',
      ],
      // Without a release for this core version, the newest release is
      // recommended, as before.
      'nothing installable' => [
        [$this->release('3.0.0', $next_only), $this->release('1.0.0', $old_only)],
        '3.0.0',
      ],
      // Development and unpublished releases are not recommended.
      'dev and unpublished' => [
        [
          $this->release('2.x-dev', $both),
          $this->release('2.0.1', $both, 'unpublished'),
          $this->release('2.0.0', $both),
        ],
        '2.0.0',
      ],
      // Releases without core_compatibility, from before it existed, don't
      // work with this core version.
      'old releases' => [
        [$this->release('8.x-1.0', '')],
        '8.x-1.0',
      ],
    ];
    foreach ($cases as $label => [$releases, $expected]) {
      $this->assertSame($expected, ProjectCollector::getRecommendedRelease($releases)['version'], $label);
    }

    // The update server can return no releases, which is an empty list.
    $this->assertNull(ProjectCollector::getRecommendedRelease([]));
    $this->assertNull(ProjectCollector::getRecommendedRelease([$this->release('1.x-dev', $both)]));
  }

  /**
   * Tests which releases are updates of the installed version.
   */
  public function testIsUpdate(): void {
    $this->assertTrue(ProjectCollector::isUpdate('8.x-1.4', '8.x-1.5'));
    $this->assertTrue(ProjectCollector::isUpdate('8.x-1.5', '2.0.0'));
    $this->assertFalse(ProjectCollector::isUpdate('2.0.0', '2.0.0'));
    // A recommended release that is older than the installed one, like an
    // older branch with a release for the next major version, is no update.
    $this->assertFalse(ProjectCollector::isUpdate('2.0.0-beta3', '8.x-1.5'));
    // Development versions and unknown versions can't be compared.
    $this->assertTrue(ProjectCollector::isUpdate('8.x-1.x-dev', '8.x-1.5'));
    $this->assertTrue(ProjectCollector::isUpdate('', '8.x-1.5'));
  }

  /**
   * Tests which newer releases are security updates.
   */
  public function testHasSecurityUpdate(): void {
    $security = $this->release('2.0.12', '^11') + ['terms' => ['Release type' => ['Security update', 'Bug fixes']]];
    $bug_fix = $this->release('2.0.11', '^11') + ['terms' => ['Release type' => ['Bug fixes']]];
    $this->assertTrue(ProjectCollector::hasSecurityUpdate([$security, $bug_fix], '2.0.10'));
    $this->assertFalse(ProjectCollector::hasSecurityUpdate([$bug_fix], '2.0.10'));
    // A security release that is not newer than the installed one is fixed.
    $this->assertFalse(ProjectCollector::hasSecurityUpdate([$security], '2.0.12'));
    // Development and unknown versions can't be compared.
    $this->assertFalse(ProjectCollector::hasSecurityUpdate([$security], '2.0.x-dev'));
    $this->assertFalse(ProjectCollector::hasSecurityUpdate([$security], ''));
  }

  /**
   * Builds a release as the update module stores it.
   *
   * @param string $version
   *   The version.
   * @param string $core_compatibility
   *   The core_compatibility value, or an empty string for none.
   * @param string $status
   *   The release status.
   *
   * @return array
   *   The release.
   */
  protected function release(string $version, string $core_compatibility, string $status = 'published'): array {
    $release = ['version' => $version, 'status' => $status];
    if ($core_compatibility !== '') {
      $release['core_compatibility'] = $core_compatibility;
    }
    return $release;
  }

}
