<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding uses of removed APIs that core only reports at runtime.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class RemovedApiDeprecationTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
  ];

  /**
   * The name of the test module.
   */
  private const MODULE = 'upgrade_status_test_removed_api';

  /**
   * Tests which integer fetch modes are reported.
   *
   * @covers \Drupal\upgrade_status\FetchModeDeprecationAnalyzer::analyze
   */
  public function testFetchModes(): void {
    $extension = $this->container->get('extension.list.module')->get(self::MODULE);
    $file = $extension->getPath() . '/' . self::MODULE . '.module';

    $deprecations = $this->container->get('upgrade_status.fetch_mode_deprecation_analyzer')->analyze($extension);

    $message = '\PDO::%s is passed as a fetch mode. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use \Drupal\Core\Database\Statement\FetchAs::%s instead. See https://www.drupal.org/node/3488338.';
    $this->assertEqualsCanonicalizing(
      [
        "$file:15 " . sprintf($message, 'FETCH_ASSOC', 'Associative'),
        "$file:16 " . sprintf($message, 'FETCH_OBJ', 'Object'),
        "$file:17 " . sprintf($message, 'FETCH_NUM', 'List'),
        "$file:20 " . sprintf($message, 'FETCH_COLUMN', 'Column'),
        "$file:23 " . sprintf($message, 'FETCH_CLASS', 'ClassObject'),
      ],
      $this->formatMessages($deprecations),
    );
    foreach ($deprecations as $deprecation) {
      $this->assertSame('FetchModeDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
  }

  /**
   * Tests which uses of deprecated services are reported.
   *
   * @covers \Drupal\upgrade_status\ServiceDeprecationAnalyzer::analyze
   */
  public function testServices(): void {
    $extension = $this->container->get('extension.list.module')->get(self::MODULE);
    $file = $extension->getPath() . '/tests/modules/' . self::MODULE . '_sub/' . self::MODULE . '_sub.services.yml';

    $deprecations = $this->container->get('upgrade_status.service_deprecation_analyzer')->analyze($extension);

    $service = 'The ' . self::MODULE . '.deprecated service is used in a services file. Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0. Use ' . self::MODULE . '.replacement instead. See https://www.drupal.org/project/upgrade_status';
    $alias = 'The ' . self::MODULE . '.deprecated_alias service is used in a services file. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use ' . self::MODULE . '.replacement instead.';
    $this->assertEqualsCanonicalizing(
      [
        "$file:4 $service",
        "$file:4 $alias",
        "$file:6 $service",
        "$file:9 $service",
        "$file:11 $alias",
        "$file:14 $service",
      ],
      $this->formatMessages($deprecations),
    );
    foreach ($deprecations as $deprecation) {
      $this->assertSame('ServiceDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
  }

  /**
   * Tests which Symfony requirements in composer.json are reported.
   *
   * @covers \Drupal\upgrade_status\ExtensionMetadataDeprecationAnalyzer::analyze
   */
  public function testSymfonyRequirements(): void {
    $extension = $this->container->get('extension.list.module')->get(self::MODULE);
    $file = $extension->getPath() . '/composer.json';

    $deprecations = $this->container->get('upgrade_status.extension_metadata_deprecation_analyzer')->analyze($extension);

    // Only the Symfony version that the next major version of Drupal requires
    // is known. symfony/polyfill-php84 has a version of its own, and core
    // doesn't require symfony/uid, so they are not reported.
    $expected = match ((int) \Drupal::VERSION) {
      10 => [
        "$file:11 The symfony/yaml requirement (^6.4) is not compatible with Symfony 7, which the next major version of Drupal requires. Update it to allow Symfony 7.",
      ],
      11 => [
        "$file:8 The symfony/mailer requirement (^6.4 || ^7.1) is not compatible with Symfony 8, which the next major version of Drupal requires. Update it to allow Symfony 8.",
        "$file:11 The symfony/yaml requirement (^6.4) is not compatible with Symfony 8, which the next major version of Drupal requires. Update it to allow Symfony 8.",
      ],
      default => [],
    };
    $this->assertEqualsCanonicalizing($expected, $this->formatMessages($deprecations));
  }

  /**
   * Formats deprecation messages with their file and line.
   *
   * @param \Drupal\upgrade_status\DeprecationMessage[] $deprecations
   *   The deprecation messages.
   *
   * @return string[]
   *   The messages, each starting with its file and line.
   */
  private function formatMessages(array $deprecations): array {
    return array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations);
  }

}
