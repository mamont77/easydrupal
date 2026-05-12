<?php

namespace Drupal\Tests\advupdate\Functional;

use Drupal\Tests\update\Functional\UpdateTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the Update Manager Advanced module through a series of tests.
 */
#[Group('advupdate')]
#[RunTestsInSeparateProcesses]
class AdvupdateTest extends UpdateTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  protected static $modules = [
    'advupdate',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests settings.
   */
  public function testAdvupdateSettings() {
    $config = $this->config('advupdate.settings');
    self::assertTrue($config->get('notification.extend_email_report'));

    $this->drupalGet('admin/reports/updates/settings');
    $this->assertSession()->responseNotContains('Expand the report using "Update Manager Advanced" module');
  }

}
