<?php

namespace Drupal\Tests\config_partial_export\Unit;

use Drupal\config_partial_export\Commands\ConfigPartialExportCommands;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Config\StorageException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Tests\UnitTestCase;
use Drush\Log\DrushLoggerManager;

/**
 * Unit tests for ConfigPartialExportCommands.
 *
 * Covers:
 *  - getMatchingConfigs() — protected, accessed via ReflectionMethod
 *  - writeConfig() — public, all I/O dependencies mocked.
 *
 * @group config_partial_export
 * @group legacy
 * @coversDefaultClass \Drupal\config_partial_export\Commands\ConfigPartialExportCommands
 */
class ConfigPartialExportCommandsTest extends UnitTestCase {

  /**
   * The system under test.
   *
   * @var \Drupal\config_partial_export\Commands\ConfigPartialExportCommands
   */
  protected ConfigPartialExportCommands $commands;

  /**
   * Mocked config manager (needed for writeConfig() fallback path).
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface|\PHPUnit\Framework\MockObject\MockObject
   */
  protected ConfigManagerInterface $configManager;

  /**
   * Mocked Drush logger, injected via setLogger() from LoggerAwareTrait.
   *
   * DrushLoggerManager (not plain LoggerInterface) is used so that
   * DrushCommands::logger() — typed as ?DrushLoggerManager — can return the
   * mock without a TypeError. The same property is accessed directly as
   * $this->logger in writeConfig()'s catch block.
   *
   * @var \Drush\Log\DrushLoggerManager|\PHPUnit\Framework\MockObject\MockObject
   */
  protected DrushLoggerManager $logger;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->configManager = $this->createMock(ConfigManagerInterface::class);
    $this->logger = $this->createMock(DrushLoggerManager::class);

    $this->commands = new ConfigPartialExportCommands(
      $this->configManager,
      $this->createMock(StorageInterface::class),
      $this->createMock(StorageInterface::class),
    );
    // setLogger() from PSR-3 LoggerAwareTrait sets $this->logger (property).
    // DrushCommands::logger() returns that same property, so both the try
    // block's $this->logger()->info() and the catch block's
    // $this->logger->error() share this single mock instance.
    $this->commands->setLogger($this->logger);
  }

  /**
   * Invokes the protected getMatchingConfigs() via reflection.
   */
  private function invokeGetMatchingConfigs(string $input, StorageInterface $storage): array {
    $ref = new \ReflectionMethod($this->commands, 'getMatchingConfigs');
    $ref->setAccessible(TRUE);
    return $ref->invoke($this->commands, $input, $storage);
  }

  /**
   * Trailing wildcard returns all keys from listAll unchanged.
   *
   * "webform.webform.*" → $split = ["webform.webform.", ""].
   * The empty trailing segment is skipped by the !empty() guard, so every
   * candidate key passes unconditionally.
   *
   * @covers ::getMatchingConfigs
   */
  public function testTrailingWildcardReturnsAllPrefixedKeys(): void {
    $keys = [
      'webform.webform.contact',
      'webform.webform.feedback',
      'webform.webform.register',
    ];
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')->with('webform.webform.')->willReturn($keys);

    $result = $this->invokeGetMatchingConfigs('webform.webform.*', $storage);

    $this->assertSame($keys, $result, 'All keys returned by listAll() are included.');
  }

  /**
   * A suffix segment filters out keys that do not contain it.
   *
   * "system.*site" → $split = ["system.", "site"].
   * strpos() searches for "site" from offset strlen("system.") = 7.
   * "system.performance" has no "site" after pos 7 → excluded.
   *
   * @covers ::getMatchingConfigs
   */
  public function testSuffixPatternFiltersKeys(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')
      ->with('system.')
      ->willReturn(['system.site', 'system.performance', 'system.sitewide']);

    $result = $this->invokeGetMatchingConfigs('system.*site', $storage);

    $this->assertContains('system.site', $result);
    $this->assertContains('system.sitewide', $result);
    $this->assertNotContains('system.performance', $result);
    $this->assertCount(2, $result);
  }

  /**
   * A middle wildcard with a literal suffix segment matches correctly.
   *
   * "module.*.sub" → $split = ["module.", ".sub"].
   * "module.baz.sub.extra" qualifies because ".sub" exists at pos 10.
   *
   * @covers ::getMatchingConfigs
   */
  public function testMiddleWildcardWithSuffixMatchesCorrectly(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')
      ->with('module.')
      ->willReturn(['module.foo.sub', 'module.bar.other', 'module.baz.sub.extra']);

    $result = $this->invokeGetMatchingConfigs('module.*.sub', $storage);

    $this->assertContains('module.foo.sub', $result, '"module.foo.sub" ends with ".sub".');
    $this->assertContains('module.baz.sub.extra', $result, '"module.baz.sub.extra" contains ".sub".');
    $this->assertNotContains('module.bar.other', $result, '"module.bar.other" has no ".sub".');
    $this->assertCount(2, $result);
  }

  /**
   * Returns empty array when no candidate key contains the suffix segment.
   *
   * @covers ::getMatchingConfigs
   */
  public function testNoMatchReturnsEmptyArray(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')
      ->willReturn(['webform.webform.contact', 'webform.webform.feedback']);

    $result = $this->invokeGetMatchingConfigs('webform.*.missing', $storage);

    $this->assertSame([], $result);
  }

  /**
   * Returns empty array when listAll() yields no candidate keys.
   *
   * @covers ::getMatchingConfigs
   */
  public function testEmptyStorageReturnsEmptyArray(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')->willReturn([]);

    $result = $this->invokeGetMatchingConfigs('webform.webform.*', $storage);

    $this->assertSame([], $result);
  }

  /**
   * Documents a counter-accumulation bug triggered by two non-empty segments.
   *
   * The algorithm tracks how far along the key it has matched using:
   * @code
   *   $counter += ($pos + strlen($split[$i]));
   * @endcode
   * The correct formula is assignment, not addition:
   * @code
   *   $counter = $pos + strlen($split[$i]);
   * @endcode
   *
   * strpos() returns an *absolute* position in the string, so the next search
   * must start at $pos + $len (absolute), not at the current $counter plus
   * those values (which double-counts the already-consumed prefix length).
   *
   * Trace for "webform.*form*.contact" vs "webform.webform.contact" (len 23):
   *   $split        = ["webform.", "form", ".contact"]
   *   initial       counter = 8  (strlen("webform."))
   *   find "form"   pos = 11, len = 4
   *   BUG:    counter += (11 + 4) → counter = 23  (past end of string!)
   *   CORRECT: counter = (11 + 4) → counter = 15
   *   find ".contact" from 23 → strpos FALSE → WRONG
   *
   * If this test starts FAILING the bug was fixed — update the assertSame()
   * to assertEquals(['webform.webform.contact'], $result).
   *
   * @covers ::getMatchingConfigs
   */
  public function testDoubleNonEmptyWildcardExhibitsCounterBug(): void {
    $storage = $this->createMock(StorageInterface::class);
    $storage->method('listAll')
      ->with('webform.')
      ->willReturn(['webform.webform.contact']);

    $result = $this->invokeGetMatchingConfigs('webform.*form*.contact', $storage);

    // Documents the CURRENT (buggy) output: the match is incorrectly missed.
    $this->assertSame(
      [],
      $result,
      'BUG: two non-empty wildcard segments trigger additive counter overflow, '
      . 'causing a false-negative match result.'
    );
  }

  /**
   * Returns TRUE and logs an info message when the write succeeds.
   *
   * @covers ::writeConfig
   */
  public function testWriteConfigSuccessReturnsTrue(): void {
    $data = ['name' => 'My Site', '_core' => ['hash' => 'abc123']];

    $source = $this->createMock(StorageInterface::class);
    $source->method('read')->with('system.site')->willReturn($data);

    $dest = $this->createMock(StorageInterface::class);
    $dest->expects($this->once())
      ->method('write')
      ->with('system.site', $data)
      ->willReturn(TRUE);

    $this->logger->expects($this->once())->method('info');

    $result = $this->commands->writeConfig('system.site', $source, $dest, '/config/sync');

    $this->assertTrue($result);
  }

  /**
   * Falls back to configFactory when source storage returns empty data.
   *
   * Empty(FALSE) === TRUE, so a FALSE return from read() triggers the fallback.
   *
   * @covers ::writeConfig
   */
  public function testWriteConfigFallsBackToConfigFactoryWhenSourceEmpty(): void {
    $fallbackData = ['name' => 'New Config', '_core' => []];

    $source = $this->createMock(StorageInterface::class);
    $source->method('read')->with('new.config.item')->willReturn(FALSE);

    $configObject = $this->createMock(ImmutableConfig::class);
    $configObject->method('getRawData')->willReturn($fallbackData);

    $factory = $this->createMock(ConfigFactoryInterface::class);
    $factory->method('get')->with('new.config.item')->willReturn($configObject);
    $this->configManager->method('getConfigFactory')->willReturn($factory);

    $dest = $this->createMock(StorageInterface::class);
    $dest->expects($this->once())
      ->method('write')
      ->with('new.config.item', $fallbackData)
      ->willReturn(TRUE);

    $this->logger->method('info');

    $result = $this->commands->writeConfig('new.config.item', $source, $dest, '/config/sync');

    $this->assertTrue($result, 'configFactory data is written when source storage is empty.');
  }

  /**
   * Returns FALSE and logs an error when write() throws StorageException.
   *
   * Logger inconsistency note: the try block calls $this->logger()->info()
   * (via the DrushCommands::logger() accessor) while the catch block calls
   * $this->logger->error() (direct property access). In the current Drush
   * version both resolve to the same object because logger() simply returns
   * $this->logger (the LoggerAwareTrait property). However, if a subclass
   * were to override logger() — e.g., for lazy initialization — the catch
   * block would bypass that override and log to the raw property, silently
   * producing inconsistent behavior.
   *
   * @covers ::writeConfig
   */
  public function testWriteConfigReturnsFalseAndLogsOnStorageException(): void {
    $source = $this->createMock(StorageInterface::class);
    $source->method('read')->willReturn(['key' => 'value']);

    $dest = $this->createMock(StorageInterface::class);
    $dest->method('write')->willThrowException(new StorageException('Disk full.'));

    $this->logger->expects($this->once())->method('error');
    $this->logger->expects($this->never())->method('info');

    $result = $this->commands->writeConfig('system.site', $source, $dest, '/config/sync');

    $this->assertFalse($result);
  }

}

// =============================================================================
// Namespace-level stub for the Drush dt() helper.
//
// PHP resolves unqualified calls by checking the current namespace first, then
// falling back to the global namespace. Because dt() is a Drush runtime
// function (not autoloaded via Composer), it is absent in a bare PHPUnit run.
// Declaring it here in \Drupal\config_partial_export\Commands intercepts every
// dt() call made from ConfigPartialExportCommands without a Drush bootstrap.
//
// NOTE: PHP forbids mixing bracketed and unbracketed namespace declarations in
// the same file, so a second plain `namespace X;` directive is used instead of
// the `namespace X { ... }` block syntax.
// =============================================================================
// phpcs:ignore
namespace Drupal\config_partial_export\Commands;

if (!function_exists(__NAMESPACE__ . '\dt')) {

  /**
   * Stub for the Drush dt() translation helper.
   */
  function dt(string $message, array $args = []): string {
    return empty($args) ? $message : strtr($message, $args);
  }

}
