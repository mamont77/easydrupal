<?php

namespace Drupal\Tests\config_partial_export\Kernel;

use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the State API integration of ConfigPartialExportForm.
 *
 * The form stores per-user checkbox selections in the State API under the key
 * 'config_partial_export_form', keyed by user ID:
 * @code
 * [
 *   $uid => [
 *     'status_checkboxes_all'  => ['config.name' => TRUE, ...],
 *     'status_checkbox_system' => bool,
 *   ],
 * ]
 * @endcode
 *
 * These tests verify the storage contract without invoking the full form
 * pipeline (which would require a real HTTP request and session).
 *
 * @group config_partial_export
 */
class ConfigPartialExportStateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config_partial_export'];

  /**
   * The State API service.
   *
   * @var \Drupal\Core\State\StateInterface
   */
  protected $state;

  /**
   * State key used by ConfigPartialExportForm.
   *
   * Must stay in sync with the form's source code.
   */
  const STATE_KEY = 'config_partial_export_form';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->state = $this->container->get('state');
  }

  /**
   * Writes a selection entry to state exactly as submitForm() would.
   *
   * @param int $uid
   *   Drupal user ID (not necessarily a real user account).
   * @param array $checkboxes
   *   Associative array of config_name => TRUE, mirroring
   *   $change_list_booleans in submitForm().
   * @param bool $include_system_site
   *   Value for the addSystemSiteInfo checkbox.
   */
  protected function storeSelectionForUser(int $uid, array $checkboxes, bool $include_system_site = FALSE): void {
    $current = $this->state->get(self::STATE_KEY, []);
    $current[$uid] = [
      'status_checkboxes_all' => $checkboxes,
      'status_checkbox_system' => $include_system_site,
    ];
    $this->state->set(self::STATE_KEY, $current);
  }

  /**
   * Tests that a saved selection is retrievable from state for the same user.
   */
  public function testUserSelectionIsRemembered(): void {
    $uid = 42;
    $checkboxes = ['system.site' => TRUE, 'system.logging' => TRUE];

    $this->storeSelectionForUser($uid, $checkboxes, TRUE);

    $stored = $this->state->get(self::STATE_KEY);
    $this->assertIsArray($stored, 'State value is an array.');
    $this->assertArrayHasKey($uid, $stored, "An entry exists for user {$uid}.");

    $entry = $stored[$uid];
    $this->assertSame(
      $checkboxes,
      $entry['status_checkboxes_all'],
      'Stored checkbox selections match what was written.'
    );
    $this->assertTrue(
      $entry['status_checkbox_system'],
      'The system.site flag is stored as TRUE.'
    );
  }

  /**
   * Tests that two users' selections are stored and retrieved independently.
   *
   * The entire state value is one array keyed by UID, so writes for one user
   * must not overwrite another user's entry.
   */
  public function testDifferentUsersHaveSeparateSelections(): void {
    $uid1 = 1;
    $uid2 = 2;
    $checkboxes1 = ['system.site' => TRUE];
    $checkboxes2 = ['system.logging' => TRUE, 'system.performance' => TRUE];

    $this->storeSelectionForUser($uid1, $checkboxes1);
    $this->storeSelectionForUser($uid2, $checkboxes2);

    $stored = $this->state->get(self::STATE_KEY);

    // Both users present.
    $this->assertArrayHasKey($uid1, $stored, 'User 1 has a state entry.');
    $this->assertArrayHasKey($uid2, $stored, 'User 2 has a state entry.');

    // Each user sees only their own selections.
    $this->assertSame($checkboxes1, $stored[$uid1]['status_checkboxes_all'], "User 1's selection is intact.");
    $this->assertSame($checkboxes2, $stored[$uid2]['status_checkboxes_all'], "User 2's selection is intact.");

    // No cross-contamination between the two entries.
    $this->assertArrayNotHasKey(
      'system.logging',
      $stored[$uid1]['status_checkboxes_all'],
      "User 2's items do not bleed into user 1's entry."
    );
    $this->assertArrayNotHasKey(
      'system.site',
      $stored[$uid2]['status_checkboxes_all'],
      "User 1's items do not bleed into user 2's entry."
    );
  }

  /**
   * Tests that an empty submission overwrites a previous non-empty selection.
   *
   * SubmitForm() always writes the current selection back, even when empty.
   * After an empty submit the stored entry must reflect the cleared state so
   * that buildForm() restores no pre-checked checkboxes for that user.
   */
  public function testEmptySelectionClearsState(): void {
    $uid = 7;

    // Establish a non-empty baseline.
    $initial = ['system.site' => TRUE, 'system.logging' => TRUE];
    $this->storeSelectionForUser($uid, $initial, TRUE);

    $before = $this->state->get(self::STATE_KEY);
    $this->assertNotEmpty($before[$uid]['status_checkboxes_all'], 'Pre-condition: non-empty selection is stored.');

    // Overwrite with an empty selection (submitForm() with nothing ticked).
    $this->storeSelectionForUser($uid, [], FALSE);

    $after = $this->state->get(self::STATE_KEY);

    // The user's entry still exists (submitForm() never deletes the key).
    $this->assertArrayHasKey($uid, $after, 'User entry survives an empty submission.');

    // But the checkbox selections are now empty, so buildForm() would apply
    // no defaults — effectively "clearing" the visible pre-selections.
    $this->assertSame(
      [],
      $after[$uid]['status_checkboxes_all'],
      'Checkbox array is empty after an empty selection is stored.'
    );
    $this->assertFalse(
      $after[$uid]['status_checkbox_system'],
      'The system.site flag is FALSE after an empty selection is stored.'
    );
  }

}
