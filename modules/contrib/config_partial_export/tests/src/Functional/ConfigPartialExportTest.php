<?php

namespace Drupal\Tests\config_partial_export\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Class to functional tests in config partial export module.
 */
class ConfigPartialExportTest extends BrowserTestBase {

  /**
   * Administrative User.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * {@inheritDoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritDoc}
   */
  protected static $modules = [
    'config',
  ];

  /**
   * {@inheritDoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([], 'user', TRUE);
  }

  /**
   * Test if the module has been installed and uninstalled correctly.
   */
  public function testInstallAndUninstall() {
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/modules');
    $this->submitForm(["modules[config_partial_export][enable]" => TRUE], 'Install');
    $this->assertSession()->pageTextContains('Module Configuration Partial Export has been installed.');
    // Uninstall the module.
    $this->drupalGet('admin/modules/uninstall');
    $this->submitForm(["uninstall[config_partial_export]" => TRUE], 'Uninstall');
    $this->submitForm([], 'Uninstall');
    $this->assertSession()->pageTextContains('The selected modules have been uninstalled.');
  }

}
