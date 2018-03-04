<?php

namespace Drupal\Tests\advupdate\Functional;

use Drupal\Tests\update\Functional\UpdateTestBase;

/**
 * Tests the Update Manager Advanced module
 * through a series of functional tests.
 *
 * @group update
 */
class AdvupdateTest extends UpdateTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = [
    'advupdate',
  ];

  /**
   * Tests settings.
   */
  public function testAdvupdateSettings() {
    $config = $this->config('advupdate.settings');
    $this->assertIdentical(TRUE, $config->get('notification.extend_email_report'));

    $this->drupalGet('admin/reports/updates/settings');
    $this->assertNoText(t('Expand the report using "Update Manager Advanced" module'));
  }
}
