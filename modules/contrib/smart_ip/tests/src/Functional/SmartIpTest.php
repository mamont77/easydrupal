<?php

namespace Drupal\Tests\smart_ip\Functional;

use Drupal\Core\Url;
use Drupal\Tests\BrowserTestBase;

/**
 * Test Smart IP module.
 *
 * @group smart_ip
 */
class SmartIpTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'smart_ip',
    'device_geolocation',
    'smart_ip_abstract_web_service',
    'smart_ip_ip2location_bin_db',
    'smart_ip_ipinfodb_web_service',
    'smart_ip_maxmind_geoip2_bin_db',
    'smart_ip_maxmind_geoip2_web_service',
  ];

  /**
   * Test settings form.
   */
  public function testSettingsForm(): void {
    $admin_user = $this->drupalCreateUser(['administer smart_ip']);
    $this->drupalLogin($admin_user);
    $this->drupalGet(Url::fromRoute('smart_ip.settings'));
    $this->assertSession()->pageTextContains('Smart IP source');
  }

}
