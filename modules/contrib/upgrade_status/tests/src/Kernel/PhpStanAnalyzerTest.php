<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\Core\Logger\RfcLogLevel;
use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\PhpStanAnalyzer;
use Drupal\upgrade_status\ProjectCollector;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\ErrorHandler\BufferingLogger;

/**
 * Tests the messages of PHPStan and its extensions, and their categories.
 *
 * The categories depend on the texts of phpstan-deprecation-rules,
 * phpstan-drupal and PHP-Parser, so this test checks their full texts. Other
 * tests only check the parts that Upgrade Status adds.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class PhpStanAnalyzerTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['upgrade_status'];

  /**
   * A real temporary directory, because PHPStan runs in another process.
   */
  protected string $temporaryDirectory;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->temporaryDirectory = sys_get_temp_dir() . '/upgrade_status_test_' . $this->databasePrefix;
    $this->setSetting('file_temp_path', $this->temporaryDirectory);
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    $this->container->get('file_system')->deleteRecursive($this->temporaryDirectory);
    parent::tearDown();
  }

  /**
   * Tests the messages about deprecated APIs and other problems.
   */
  public function testMessages(): void {
    $deprecated_function = 'Call to deprecated function upgrade_status_test_contrib_error_function_9_to_10(): in drupal:9.1.0 and is removed from drupal:10.0.0. Use the replacement instead.';
    $this->assertEqualsCanonicalizing([
      "src/Controller/UpgradeStatusTestErrorController.php:16 $deprecated_function",
      'src/ExtendingClass.php:10 Class Drupal\upgrade_status_test_error\ExtendingClass extends deprecated class Drupal\upgrade_status_test_error\DeprecatedBaseClass: in drupal:9.1.0 and is removed from drupal:10.0.0. Instead, use so and so. See https://www.drupal.org/project/upgrade_status.',
      'src/UpgradeStatusTestErrorEntity.php:15 Configuration entity must define a `config_export` key. See https://www.drupal.org/node/2481909',
    ], $this->getMessages('upgrade_status_test_error'));

    // A file that can't be parsed gets PHP-Parser's message.
    $this->assertSame(['fatal.php:5 Syntax error, unexpected T_STRING on line 5'], $this->getMessages('upgrade_status_test_fatal'));

    // Deprecated constants in core's include and module files are found,
    // because they are in scanFiles. Which ones are deprecated depends on the
    // Drupal version.
    [$major, $minor] = array_map('intval', explode('.', \Drupal::VERSION));
    $expected = [];
    if ($major === 10) {
      $expected[] = 'upgrade_status_test_core_constants.module:15 in drupal:8.3.0 and is removed from drupal:11.0.0.';
    }
    if ($major === 11 && $minor >= 2) {
      $expected[] = 'upgrade_status_test_core_constants.module:15 in drupal:11.2.0 and is removed from drupal:12.0.0. Use \Drupal\Core\Extension\Requirement\RequirementSeverity::Error instead.';
    }
    if (($major === 11 && $minor >= 4) || $major === 12) {
      $expected[] = 'upgrade_status_test_core_constants.module:15 in drupal:11.4.0 and is removed from drupal:13.0.0. Instead, use \Drupal\Core\Extension\Theme::listAllRegions().';
    }
    $messages = $this->getMessages('upgrade_status_test_core_constants');
    foreach ($expected as $text) {
      $this->assertNotEmpty(array_filter($messages, static fn (string $message): bool => str_starts_with($message, $text)), $text . "\n" . implode("\n", $messages));
    }
  }

  /**
   * Tests the categories of deprecated APIs in a contributed project.
   */
  public function testCategories(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_contrib_error');
    $result = $this->getAnalyzer()->analyze($extension);
    $categorizer = $this->container->get('upgrade_status.message_categorizer');
    $categories = [];
    foreach ($result['files'] as $errors) {
      foreach ($errors['messages'] as $error) {
        [$message, $category] = $categorizer->categorize($error['message'], $extension);
        $categories[$error['line']] = [$message, $category];
      }
    }

    $expected = [
      16 => ['9_to_10', '9.1.0', '10.0.0', 'old'],
      17 => ['9_to_11', '9.1.0', '11.0.0', 'old'],
      18 => ['10_to_11', '10.6.0', '11.0.0', $major < 11 ? 'later' : 'old'],
      19 => ['10_to_12', '10.0.0', '12.0.0', $major < 11 ? 'ignore' : 'old'],
      20 => ['11_to_13', '11.3.0', '13.0.0', $major < 12 ? 'ignore' : 'old'],
    ];
    $this->assertSame(array_keys($expected), array_keys($categories));
    foreach ($expected as $line => [$function, $deprecated, $removed, $category]) {
      $this->assertSame([
        "Call to deprecated function upgrade_status_test_contrib_error_function_$function(). Deprecated in drupal:$deprecated and is removed from drupal:$removed. Use the replacement instead.",
        $category,
      ], $categories[$line]);
    }
  }

  /**
   * Tests the errors of PHPStan that are not about a file.
   */
  public function testErrorsNotAboutFiles(): void {
    $logger = new BufferingLogger();
    $this->container->get('logger.factory')->addLogger($logger);
    $analyzer = $this->getAnalyzer();
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_error');
    $file = DRUPAL_ROOT . '/' . $extension->getPath() . '/src/ExtendingClass.php';

    // For a worker process without enough memory and for an internal error,
    // the output of PHPStan is valid JSON with the errors in a list, and
    // without the messages of the files. A script in place of PHPStan prints
    // the same output.
    $memory = "Child process error: PHPStan process crashed because it reached configured PHP memory limit: 60M\nIncrease your memory limit in php.ini or run PHPStan with --memory-limit CLI option.\n while running parallel worker";
    $output = json_encode([
      'totals' => ['errors' => 3, 'file_errors' => 0],
      'files' => [],
      'errors' => [
        $memory,
        "Internal error: Something broke while analysing file $file",
        "Something else broke on line 12 while analysing file $file",
      ],
    ]);
    if (!is_dir($this->temporaryDirectory)) {
      mkdir($this->temporaryDirectory, 0777, TRUE);
    }
    $script = $this->temporaryDirectory . '/phpstan-with-errors.php';
    file_put_contents($script, '<?php echo ' . var_export($output, TRUE) . '; exit(1);');
    (new \ReflectionProperty($analyzer, 'phpstanPath'))->setValue($analyzer, $script);
    $result = $analyzer->analyze($extension);

    // Each error is a message, so the result is not the same as for a project
    // without problems. The paths of the site on the server are left out.
    $this->assertSame([
      [
        'message' => "PHPStan error, results may be missing: $memory",
        'line' => 0,
        'analyzer' => 'PHPStan',
      ],
      [
        'message' => 'PHPStan error, results may be missing: Internal error: Something broke while analysing file [Drupal root]/' . $extension->getPath() . '/src/ExtendingClass.php',
        'line' => 0,
        'analyzer' => 'PHPStan',
      ],
    ], $result['files']['PHPStan failed']['messages']);
    // An error with a line number is a message of the file, as before.
    $this->assertSame([
      [
        'message' => 'Something else broke',
        'line' => '12',
        'analyzer' => 'PHPStan',
      ],
    ], $result['files'][$file]['messages']);
    $this->assertCount(2, $result['files']);
    $this->assertSame(3, $result['totals']['file_errors']);

    // The category has no Drupal version, so the messages are to check
    // manually and count for the next major version.
    $categorizer = $this->container->get('upgrade_status.message_categorizer');
    foreach ($result['files']['PHPStan failed']['messages'] as $message) {
      $this->assertSame('uncategorized', $categorizer->categorize($message['message'], $extension)[1]);
    }

    // The errors are logged with the full paths. Drush prints a log message
    // of this level with [error], which scripts look for.
    $logged = [];
    foreach ($logger->cleanLogs() as [$level, $text, $context]) {
      if ($level === RfcLogLevel::ERROR) {
        $logged[] = strtr($text, array_filter($context, 'is_string'));
      }
    }
    $path = DRUPAL_ROOT . '/' . $extension->getPath();
    $this->assertSame([
      "PHPStan error for $path, results may be missing: $memory",
      "PHPStan error for $path, results may be missing: Internal error: Something broke while analysing file $file",
    ], $logged);
  }

  /**
   * Gets the PHPStan analyzer with its environment initialized.
   *
   * @return \Drupal\upgrade_status\PhpStanAnalyzer
   *   The PHPStan analyzer.
   */
  protected function getAnalyzer(): PhpStanAnalyzer {
    $analyzer = $this->container->get('upgrade_status.phpstan_analyzer');
    $analyzer->initEnvironment();
    return $analyzer;
  }

  /**
   * Runs PHPStan on a module.
   *
   * @param string $name
   *   The name of the module.
   *
   * @return string[]
   *   The messages as "file:line message", with the file relative to the
   *   module and the whitespace collapsed.
   */
  protected function getMessages(string $name): array {
    $extension = $this->container->get('extension.list.module')->get($name);
    $result = $this->getAnalyzer()->analyze($extension);
    $module_dir = DRUPAL_ROOT . '/' . $extension->getPath() . '/';
    $messages = [];
    foreach ($result['files'] as $file => $errors) {
      foreach ($errors['messages'] as $error) {
        $messages[] = str_replace($module_dir, '', $file) . ':' . $error['line'] . ' ' . preg_replace('!\s+!', ' ', trim($error['message']));
      }
    }
    return $messages;
  }

}
