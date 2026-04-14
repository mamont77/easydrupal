<?php

namespace Drupal\Tests\config_partial_export\Kernel;

use Drupal\Core\Config\StorageComparer;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests StorageComparer integration for the config_partial_export module.
 *
 * Verifies that StorageComparer correctly detects configuration changes between
 * the active storage and the snapshot storage, as used by
 * ConfigPartialExportForm::buildForm().
 *
 * @group config_partial_export
 */
class ConfigPartialExportStorageComparerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config_partial_export'];

  /**
   * The active configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * The snapshot configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $snapshotStorage;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->activeStorage = $this->container->get('config.storage');
    $this->snapshotStorage = $this->container->get('config.storage.snapshot');
  }

  /**
   * Copies all active config to the snapshot storage.
   *
   * Simulates the "take snapshot" operation so comparisons start from a clean
   * baseline where active and snapshot are identical.
   */
  protected function syncSnapshotFromActive(): void {
    // Clear the snapshot first to avoid stale entries.
    foreach ($this->snapshotStorage->listAll() as $name) {
      $this->snapshotStorage->delete($name);
    }
    foreach ($this->activeStorage->listAll() as $name) {
      $data = $this->activeStorage->read($name);
      if ($data !== FALSE) {
        $this->snapshotStorage->write($name, $data);
      }
    }
  }

  /**
   * Tests that no changes are reported when active and snapshot are in sync.
   */
  public function testNoChangesWhenConfigMatchesSnapshot(): void {
    $this->syncSnapshotFromActive();

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    $this->assertFalse(
      $comparer->hasChanges(),
      'StorageComparer reports no changes when active and snapshot are identical.'
    );
  }

  /**
   * Tests that a config modification is detected in the changelist.
   */
  public function testChangesDetectedAfterConfigModification(): void {
    $this->syncSnapshotFromActive();

    // Modify system.site name in active storage directly.
    $site_data = $this->activeStorage->read('system.site');
    $this->assertIsArray($site_data, 'system.site config exists in active storage.');
    $site_data['name'] = 'Modified Site Name';
    $this->activeStorage->write('system.site', $site_data);

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    $this->assertTrue(
      $comparer->hasChanges(),
      'StorageComparer detects changes after system.site name was modified.'
    );

    // Collect all changed config names across collections.
    $changed_configs = [];
    foreach ($comparer->getAllCollectionNames() as $collection) {
      foreach ($comparer->getChangelist(NULL, $collection) as $config_names) {
        foreach ($config_names as $config_name) {
          $changed_configs[] = $config_name;
        }
      }
    }

    $this->assertContains(
      'system.site',
      $changed_configs,
      'system.site appears in the changelist after modification.'
    );
  }

  /**
   * Tests changelist structure matches ConfigPartialExportForm.
   *
   * ConfigPartialExportForm::buildForm() builds $change_list as:
   * @code
   * $change_list[$config_name]['name'] = $config_name;
   * @endcode
   * This test replicates that logic to verify the output is form-compatible.
   */
  public function testChangelistStructureIsFormCompatible(): void {
    $this->syncSnapshotFromActive();

    $site_data = $this->activeStorage->read('system.site');
    $site_data['name'] = 'Form Compatibility Test';
    $this->activeStorage->write('system.site', $site_data);

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    // Replicate ConfigPartialExportForm::buildForm() changelist assembly.
    $change_list = [];
    if ($comparer->hasChanges()) {
      foreach ($comparer->getAllCollectionNames() as $collection) {
        foreach ($comparer->getChangelist(NULL, $collection) as $config_names) {
          if (empty($config_names)) {
            continue;
          }
          foreach ($config_names as $config_name) {
            $change_list[$config_name]['name'] = $config_name;
          }
        }
      }
    }

    $this->assertNotEmpty($change_list, 'Change list is not empty after modification.');
    $this->assertArrayHasKey('system.site', $change_list, 'system.site is present in the assembled change list.');
    $this->assertSame(
      'system.site',
      $change_list['system.site']['name'],
      "Change list entry has the expected 'name' key structure."
    );
  }

  /**
   * Tests that a new config key appears as a creation.
   */
  public function testNewConfigAppearsAsCreation(): void {
    $this->syncSnapshotFromActive();

    // Write a new (non-existent) config item to active storage only.
    $this->activeStorage->write('config_partial_export.test_new_item', [
      'test_key' => 'test_value',
    ]);

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    $this->assertTrue($comparer->hasChanges(), 'Changes detected after adding a new config item.');

    $create_list = $comparer->getChangelist('create');
    $this->assertContains(
      'config_partial_export.test_new_item',
      $create_list,
      'New config item appears under the "create" changelist.'
    );
  }

  /**
   * Tests that a config item deleted from active appears as a deletion.
   *
   * StorageComparer uses active as source and snapshot as target.
   * An item present in snapshot but absent from active is reported as 'delete'.
   */
  public function testDeletedConfigAppearsAsDeletion(): void {
    // Write a test item to active first, then sync so both storages are equal.
    $this->activeStorage->write('config_partial_export.test_delete_item', [
      'test_key' => 'delete_me',
    ]);
    $this->syncSnapshotFromActive();

    // Now remove the item from active — it remains in snapshot only.
    $this->activeStorage->delete('config_partial_export.test_delete_item');

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    $this->assertTrue($comparer->hasChanges(), 'Changes detected after deleting a config item from active.');

    $delete_list = $comparer->getChangelist('delete');
    $this->assertContains(
      'config_partial_export.test_delete_item',
      $delete_list,
      'Deleted config item appears under the "delete" changelist.'
    );

    // Must not appear in create or update.
    $this->assertNotContains(
      'config_partial_export.test_delete_item',
      $comparer->getChangelist('create'),
      'Deleted item must not appear in the "create" changelist.'
    );
    $this->assertNotContains(
      'config_partial_export.test_delete_item',
      $comparer->getChangelist('update'),
      'Deleted item must not appear in the "update" changelist.'
    );
  }

  /**
   * Tests that a UUID-matched config rename is detected as a rename.
   *
   * StorageComparer::addChangelistRename() promotes a create+delete pair to a
   * rename when the source (active) and target (snapshot) items share the same
   * UUID. The resulting changelist entry uses the format "old_name::new_name",
   * where old_name is the snapshot name and new_name is the active name.
   *
   * Note: rename detection only works in the DEFAULT_COLLECTION (not
   * sub-collections), and only when the config data contains a 'uuid' key.
   */
  public function testRenamedConfigAppearsAsRename(): void {
    $shared_uuid = '11111111-2222-3333-4444-555555555555';
    $old_name = 'config_partial_export.test_rename_old';
    $new_name = 'config_partial_export.test_rename_new';

    // Write the "old" name to snapshot only (simulates the pre-rename state).
    $this->snapshotStorage->write($old_name, [
      'uuid' => $shared_uuid,
      'label' => 'Original label',
    ]);

    // Write "new" name to active with same UUID (post-rename).
    $this->activeStorage->write($new_name, [
      'uuid' => $shared_uuid,
      'label' => 'Original label',
    ]);

    $comparer = new StorageComparer($this->activeStorage, $this->snapshotStorage);
    $comparer->createChangelist();

    $this->assertTrue($comparer->hasChanges(), 'Changes detected for a renamed config item.');

    $rename_list = $comparer->getChangelist('rename');
    // The expected entry format is "old_name::new_name".
    $expected_rename = $old_name . '::' . $new_name;
    $this->assertContains(
      $expected_rename,
      $rename_list,
      "Rename entry '{$expected_rename}' appears in the \"rename\" changelist."
    );

    // Promoted renames must be removed from create and delete.
    $this->assertNotContains(
      $new_name,
      $comparer->getChangelist('create'),
      'Renamed item must not remain in the "create" changelist.'
    );
    $this->assertNotContains(
      $old_name,
      $comparer->getChangelist('delete'),
      'Renamed item must not remain in the "delete" changelist.'
    );

    // extractRenameNames() must correctly parse the entry back.
    $parsed = $comparer->extractRenameNames($expected_rename);
    $this->assertSame($old_name, $parsed['old_name'], 'extractRenameNames() returns the correct old name.');
    $this->assertSame($new_name, $parsed['new_name'], 'extractRenameNames() returns the correct new name.');
  }

}
