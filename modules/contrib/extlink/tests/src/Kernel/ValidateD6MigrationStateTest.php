<?php

namespace Drupal\Tests\extlink\Kernel;

use Drupal\Tests\migrate_drupal\Kernel\d6\MigrateDrupal6TestBase;
use Drupal\Tests\migrate_drupal\Traits\ValidateMigrationStateTestTrait;

/**
 * Tests that the extlink module has a declared D6 migration status.
 *
 * ValidateMigrationStateTestTrait::testMigrationState() will succeed if the
 * modules enabled in \Drupal\Tests\KernelTestBase::bootKernel() have a valid
 * migration status (i.e.: finished or not_finished); but will fail if they do
 * not have a declared migration status.
 *
 * @group extlink
 */
class ValidateD6MigrationStateTest extends MigrateDrupal6TestBase {
  use ValidateMigrationStateTestTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['extlink'];

}
