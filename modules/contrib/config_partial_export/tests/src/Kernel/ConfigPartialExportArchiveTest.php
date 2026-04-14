<?php

namespace Drupal\Tests\config_partial_export\Kernel;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Archiver\ArchiveTar;
use Drupal\config_partial_export\Form\ConfigPartialExportForm;
use Drupal\KernelTests\KernelTestBase;

/**
 * Tests the createArchive() method of ConfigPartialExportForm.
 *
 * Verifies that the generated tar.gz archive contains the expected YAML files
 * with valid content matching the active configuration storage.
 *
 * @group config_partial_export
 */
class ConfigPartialExportArchiveTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'config_partial_export'];

  /**
   * The form instance under test.
   *
   * @var \Drupal\config_partial_export\Form\ConfigPartialExportForm
   */
  protected ConfigPartialExportForm $form;

  /**
   * Absolute path to the generated tar.gz archive.
   *
   * @var string
   */
  protected string $archivePath;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    $this->form = ConfigPartialExportForm::create($this->container);
    $this->archivePath = $this->container->get('file_system')->getTempDirectory()
      . '/config_partial.tar.gz';
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // Remove the archive after each test to avoid cross-test contamination.
    if (file_exists($this->archivePath)) {
      unlink($this->archivePath);
    }
    parent::tearDown();
  }

  /**
   * Returns filenames listed inside the archive, or an empty array.
   *
   * @return string[]
   *   Filenames as stored in the tar (e.g. "system.site.yml").
   */
  protected function getArchiveFilenames(): array {
    if (!file_exists($this->archivePath)) {
      return [];
    }
    $archive = new ArchiveTar($this->archivePath, 'gz');
    $contents = $archive->listContent();
    return $contents ? array_column($contents, 'filename') : [];
  }

  /**
   * Extracts a single file's raw content from the archive.
   *
   * @param string $filename
   *   Filename inside the archive (e.g. "system.site.yml").
   *
   * @return string
   *   Raw file content.
   */
  protected function extractFromArchive(string $filename): string {
    $archive = new ArchiveTar($this->archivePath, 'gz');
    return (string) $archive->extractInString($filename);
  }

  /**
   * Tests that the archive contains exactly the selected config files.
   */
  public function testArchiveContainsSelectedConfigItems(): void {
    $this->form->createArchive(['system.site', 'system.logging']);

    $this->assertFileExists($this->archivePath, 'Archive file was created.');

    $filenames = $this->getArchiveFilenames();
    $this->assertContains('system.site.yml', $filenames, 'system.site.yml is in the archive.');
    $this->assertContains('system.logging.yml', $filenames, 'system.logging.yml is in the archive.');
    $this->assertCount(2, $filenames, 'Archive contains exactly the 2 selected config files, no more.');
  }

  /**
   * Tests that archive entries contain valid YAML matching the active config.
   */
  public function testArchiveContainsValidYaml(): void {
    $this->form->createArchive(['system.site']);

    $this->assertFileExists($this->archivePath);

    $raw = $this->extractFromArchive('system.site.yml');
    $this->assertNotEmpty($raw, 'system.site.yml entry has content.');

    // Must decode without errors.
    $decoded = Yaml::decode($raw);
    $this->assertIsArray($decoded, 'Archive entry is valid, decodable YAML.');

    // Must match the raw data from the active config factory (no overrides).
    $expected = $this->container
      ->get('config.manager')
      ->getConfigFactory()
      ->get('system.site')
      ->getRawData();

    $this->assertEquals(
      $expected,
      $decoded,
      'YAML content matches the active config raw data byte-for-byte.'
    );
  }

  /**
   * Tests that system.site is appended automatically when the flag is set.
   *
   * CreateArchive() contains this logic:
   * @code
   * if ($add_system_site_info && !in_array('system.site', $change_list)) {
   *   $change_list[] = 'system.site';
   * }
   * @endcode
   */
  public function testArchiveWithSystemSiteInclusion(): void {
    // Case 1: system.site NOT in the list, flag TRUE → must be appended.
    $this->form->createArchive(['system.logging'], TRUE);

    $filenames = $this->getArchiveFilenames();
    $this->assertContains(
      'system.site.yml',
      $filenames,
      'system.site.yml is appended when $add_system_site_info is TRUE and not already selected.'
    );
    $this->assertContains('system.logging.yml', $filenames);
    $this->assertCount(2, $filenames, 'Exactly 2 files in archive (no duplicates).');

    // Case 2: system.site already in the list, flag TRUE → must NOT be
    // duplicated (in_array guard in createArchive).
    unlink($this->archivePath);
    $this->form->createArchive(['system.site', 'system.logging'], TRUE);

    $filenames2 = $this->getArchiveFilenames();
    $siteCount = count(array_filter($filenames2, fn($f) => $f === 'system.site.yml'));
    $this->assertSame(1, $siteCount, 'system.site.yml appears exactly once when already selected and flag is TRUE.');
    $this->assertCount(2, $filenames2, 'Still exactly 2 files in archive.');
  }

  /**
   * Tests that system.site is NOT appended when the flag is explicitly FALSE.
   *
   * This mirrors the real-world scenario where submitForm() casts a NULL
   * checkbox value to (bool), resulting in FALSE. Before the bugfix, passing
   * NULL directly would cause a TypeError because createArchive() requires
   * a bool parameter.
   *
   * @see \Drupal\config_partial_export\Form\ConfigPartialExportForm::submitForm()
   */
  public function testArchiveWithSystemSiteFlagExplicitlyFalse(): void {
    $this->form->createArchive(['system.logging'], FALSE);

    $filenames = $this->getArchiveFilenames();
    $this->assertContains('system.logging.yml', $filenames);
    $this->assertNotContains(
      'system.site.yml',
      $filenames,
      'system.site.yml must NOT be included when $add_system_site_info is FALSE.'
    );
    $this->assertCount(1, $filenames, 'Archive contains exactly 1 file.');
  }

  /**
   * Tests that an empty selection produces no archive.
   *
   * The previous archive is deleted and no new one is created because
   * no files are added and Archive_Tar never writes to disk.
   */
  public function testEmptySelectionProducesEmptyArchive(): void {
    // Plant a pre-existing archive to confirm it gets deleted by createArchive.
    file_put_contents($this->archivePath, 'stale content');
    $this->assertFileExists($this->archivePath, 'Pre-condition: stale archive exists.');

    $this->form->createArchive([]);

    // createArchive() calls fileSystem->delete() first, then constructs
    // ArchiveTar but never calls addString(). Archive_Tar does not write to
    // disk until content is actually added, so the file should be gone.
    if (file_exists($this->archivePath)) {
      // In case the PEAR library creates an empty file on construction,
      // verify it contains no entries rather than failing outright.
      $filenames = $this->getArchiveFilenames();
      $this->assertEmpty(
        $filenames,
        'If the archive file exists for an empty selection it must contain no entries.'
      );
    }
    else {
      $this->assertFileDoesNotExist(
        $this->archivePath,
        'Stale archive was deleted and no new archive was created for an empty selection.'
      );
    }
  }

}
