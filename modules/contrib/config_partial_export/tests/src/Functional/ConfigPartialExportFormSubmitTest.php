<?php

namespace Drupal\Tests\config_partial_export\Functional;

use Drupal\Tests\BrowserTestBase;

/**
 * Tests the full form submission pipeline of the Partial Export form.
 *
 * These tests cover the end-to-end flow: loading the form, selecting
 * checkboxes, and clicking Export. This exercises the submitForm() →
 * createArchive() bridge which is not covered by the Kernel or Unit tests.
 *
 * @group config_partial_export
 */
class ConfigPartialExportFormSubmitTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'config',
    'config_partial_export',
  ];

  /**
   * Administrative user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $adminUser;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->adminUser = $this->drupalCreateUser([], 'testadmin', TRUE);
  }

  /**
   * Creates a config change so that the form has items to display.
   *
   * The Partial Export form compares the active config storage against the
   * snapshot storage. Changing a config value after install creates a diff
   * that appears in the tableselect.
   */
  protected function createConfigChange(): void {
    $this->config('system.site')
      ->set('name', 'Changed Site Name')
      ->save();
  }

  /**
   * Tests form submission with selected items and addSystemSiteInfo unchecked.
   *
   * This is the exact scenario that triggers the TypeError when
   * $add_system_site_info is passed as NULL to createArchive().
   * The addSystemSiteInfo checkbox is not checked, so getUserInput()
   * returns NULL for that key.
   */
  public function testSubmitWithoutSystemSiteInfo(): void {
    $this->createConfigChange();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/development/configuration/single/config-partial-export');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Partial Export');

    // Select system.site from the tableselect (do NOT check addSystemSiteInfo).
    $this->submitForm([
      'change_list[system.site]' => 'system.site',
    ], 'Export');

    // If the bug is present (NULL passed to bool parameter), a TypeError
    // would result in a 500 error with "unexpected error" message.
    // After the fix, the form redirects to the download route.
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('unexpected error');
    $this->assertSession()->pageTextNotContains('TypeError');
  }

  /**
   * Tests form submission with addSystemSiteInfo explicitly checked.
   *
   * Verifies that the happy path (checkbox checked → TRUE) still works
   * and does not regress.
   */
  public function testSubmitWithSystemSiteInfo(): void {
    $this->createConfigChange();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/development/configuration/single/config-partial-export');

    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'change_list[system.site]' => 'system.site',
      'addSystemSiteInfo' => TRUE,
    ], 'Export');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('unexpected error');
    $this->assertSession()->pageTextNotContains('TypeError');
  }

  /**
   * Tests form submission with only addSystemSiteInfo checked (no items).
   *
   * The validation allows this case: empty change_list is accepted when the
   * system site flag is truthy.
   */
  public function testSubmitWithOnlySystemSiteInfo(): void {
    $this->createConfigChange();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/development/configuration/single/config-partial-export');

    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([
      'addSystemSiteInfo' => TRUE,
    ], 'Export');

    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextNotContains('unexpected error');
    $this->assertSession()->pageTextNotContains('TypeError');
  }

  /**
   * Tests that submitting with nothing selected shows a validation error.
   *
   * Neither change_list items nor addSystemSiteInfo are checked.
   */
  public function testSubmitWithNothingSelectedShowsError(): void {
    $this->createConfigChange();
    $this->drupalLogin($this->adminUser);
    $this->drupalGet('admin/config/development/configuration/single/config-partial-export');

    $this->assertSession()->statusCodeEquals(200);

    $this->submitForm([], 'Export');

    $this->assertSession()->pageTextContains('No items selected.');
  }

}
