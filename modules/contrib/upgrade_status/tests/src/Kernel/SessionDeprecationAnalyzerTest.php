<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding custom keys of $_SESSION.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class SessionDeprecationAnalyzerTest extends KernelTestBase {

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
  private const MODULE = 'upgrade_status_test_session';

  /**
   * Tests which uses of $_SESSION are reported.
   */
  public function testSessionKeys(): void {
    $extension = $this->container->get('extension.list.module')->get(self::MODULE);
    $file = $extension->getPath() . '/' . self::MODULE . '.module';
    $class_file = $extension->getPath() . '/src/SessionStorage.php';

    $deprecations = $this->container->get('upgrade_status.session_deprecation_analyzer')->analyze($extension);

    $deprecated = ' Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Use the get(), set() and remove() methods of $request->getSession() instead. See https://www.drupal.org/node/3518527.';
    $key = "\$_SESSION['upgrade_status_test'] is used directly." . $deprecated;
    $ids = "\$_SESSION['upgrade_status_test_ids'] is used directly." . $deprecated;
    $dynamic = 'A custom key of $_SESSION is used directly.' . $deprecated;
    $this->assertEqualsCanonicalizing(
      [
        "$file:12 $key",
        "$file:13 " . '$_SESSION["upgrade_status_test_ids"] is used directly.' . $deprecated,
        "$file:14 $key",
        "$file:15 $key",
        "$file:16 $dynamic",
        "$file:17 $dynamic",
        "$file:18 $ids",
        "$file:19 $ids",
        "$file:19 $key",
        "$class_file:16 $dynamic",
      ],
      $this->formatMessages($deprecations),
    );
    foreach ($deprecations as $deprecation) {
      $this->assertSame('SessionDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
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
