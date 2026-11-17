<?php

namespace Drupal\Tests\upgrade_status\Unit;

use Drupal\Core\Extension\Extension;
use Drupal\Tests\UnitTestCase;
use Drupal\upgrade_status\MessageCategorizer;
use Drupal\upgrade_status\PluginDefinitionDeprecationAnalyzer;
use Drupal\upgrade_status\ProjectCollector;
use Drupal\upgrade_status\TemplateDeprecationAnalyzer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the rewording and the categories of messages.
 *
 * @group upgrade_status
 */
#[Group('upgrade_status')]
final class MessageCategorizerTest extends UnitTestCase {

  /**
   * Tests the categories of deprecations by version and project type.
   */
  public function testVersionCategories(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $next = $major + 1;
    // Deprecated before this major version, and removed in the next one.
    $old = "Call to deprecated function old_function(). Deprecated in drupal:" . ($major - 1) . ".0.0 and is removed from drupal:$next.0.0. Use new_function() instead.";
    // Deprecated in a minor version after this site's version.
    $new = "Call to deprecated function new_function(). Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use newer_function() instead.";
    // Removed after the next major version.
    $future = "Call to deprecated function future_function(). Deprecated in drupal:" . ($major - 1) . ".0.0 and is removed from drupal:" . ($next + 1) . ".0.0. Use new_function() instead.";

    $cases = [
      // A contributed project fixes deprecations from the oldest supported
      // minor version or before.
      [$old, TRUE, 'old'],
      [$new, TRUE, 'later'],
      // A custom project fixes deprecations from this site's version or
      // before.
      [$old, FALSE, 'safe'],
      [$new, FALSE, 'later'],
      // Removals after the next major version don't need fixing yet.
      [$future, TRUE, 'ignore'],
      [$future, FALSE, 'ignore'],
      // Messages without a Drupal version can't be categorized.
      ['Call to an undefined method Drupal\Foo::bar().', TRUE, 'uncategorized'],
      ['Call to an undefined method Drupal\Foo::bar().', FALSE, 'uncategorized'],
    ];
    foreach ($cases as [$message, $contrib, $expected]) {
      [, $category] = (new MessageCategorizer())->categorize($message, $this->getExtension($contrib));
      $this->assertSame($expected, $category, ($contrib ? 'Contributed: ' : 'Custom: ') . $message);
    }
  }

  /**
   * Tests the categories of a project from the project collector.
   *
   * The project collector sets a project name for custom projects too, so the
   * project type decides which rules apply.
   */
  public function testCollectedProjectTypes(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $old = "Call to deprecated function old_function(). Deprecated in drupal:" . ($major - 1) . ".0.0 and is removed from drupal:" . ($major + 1) . ".0.0. Use new_function() instead.";
    $categorizer = new MessageCategorizer();
    foreach ([ProjectCollector::TYPE_CUSTOM => 'safe', ProjectCollector::TYPE_CONTRIB => 'old'] as $type => $expected) {
      $extension = $this->getExtension(FALSE);
      $extension->info = ['project' => 'foo', 'upgrade_status_type' => $type];
      $this->assertSame($type === ProjectCollector::TYPE_CONTRIB, ProjectCollector::isContributed($extension));
      [, $category] = $categorizer->categorize($old, $extension);
      $this->assertSame($expected, $category, $type);
    }
  }

  /**
   * Tests the rewording of messages.
   */
  public function testRewording(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $next = $major + 1;
    $cases = [
      // Whitespace is collapsed, and the deprecation text is a new sentence.
      [
        "Call to deprecated function foo():\n  in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use bar() instead.",
        "Call to deprecated function foo(). Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use bar() instead.",
      ],
      // PHPStan's message for a deprecated constant is only its @deprecated
      // text.
      [
        "in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use FOO instead.",
        "Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use FOO instead.",
      ],
      // The leading backslash of \Drupal is removed after "Use".
      [
        "Call to deprecated function foo(). Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use \\Drupal::service('bar') instead.",
        "Call to deprecated function foo(). Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use Drupal::service('bar') instead.",
      ],
    ];
    foreach ($cases as [$message, $expected]) {
      [$reworded] = (new MessageCategorizer())->categorize($message, $this->getExtension(FALSE));
      $this->assertSame($expected, $reworded);
    }
  }

  /**
   * Tests the messages that drupal-rector can fix.
   */
  public function testRector(): void {
    $categorizer = new MessageCategorizer();
    foreach ([TRUE, FALSE] as $contrib) {
      [, $category] = $categorizer->categorize('Missing call to parent::setUp() method.', $this->getExtension($contrib));
      $this->assertSame('rector', $category);
      [, $category] = $categorizer->categorize('Call to deprecated method l() of class Drupal\Core\Link.', $this->getExtension($contrib));
      $this->assertSame('rector', $category);
    }
  }

  /**
   * Tests the messages that are ignored because they are false positives.
   */
  public function testFalsePositives(): void {
    $categorizer = new MessageCategorizer();
    $extension = $this->getExtension(TRUE);

    [, $category] = $categorizer->categorize('Call to deprecated method loadRevision() of interface Drupal\Core\Entity\EntityStorageInterface. Deprecated in drupal:10.1.0 and is removed from drupal:11.0.0. Use Drupal\Core\Entity\RevisionableStorageInterface::loadRevision instead.', $extension);
    $this->assertSame('ignore', $category);

    // Guzzle 8 messages are only ignored before Drupal 11.
    $expected = ProjectCollector::getDrupalCoreMajorVersion() < 11 ? 'ignore' : 'uncategorized';
    [, $category] = $categorizer->categorize('Calling foo() is deprecated and will be removed in guzzlehttp/guzzle:8.0.', $extension);
    $this->assertSame($expected, $category);
  }

  /**
   * Tests core's runtime messages and other removal wordings.
   */
  public function testRuntimeMessages(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $next = $major + 1;
    $before = $major - 1;
    $after = $next + 1;
    // Runtime messages say "is deprecated in drupal:X" in lowercase.
    $service = "The \"foo\" service is deprecated in drupal:$before.0.0 and is removed from drupal:$next.0.0.";
    // The removal version is read whatever the wording.
    $removed_in = "Calling foo() is deprecated in drupal:$before.0.0 and removed in drupal:$next.0.0.";
    $will_be_removed = "Calling foo() is deprecated in drupal:$before.0.0 and will be removed in drupal:$after.0.0.";
    $error = "Calling foo() is deprecated in drupal:$before.0.0 and an error will be thrown from drupal:$after.0.0.";
    $unsupported = "Using foo is deprecated in drupal:$before.0.0 and will be unsupported in drupal:$after.0.0.";
    // Minor versions with two digits are read completely, so 10 is not 1.
    $two_digits = "Call to deprecated function foo(). Deprecated in drupal:$major.10.0 and is removed from drupal:$next.0.0.";
    $cases = [
      [$service, TRUE, 'old'],
      [$service, FALSE, 'safe'],
      [$removed_in, FALSE, 'safe'],
      [$will_be_removed, FALSE, 'ignore'],
      [$error, FALSE, 'ignore'],
      [$unsupported, TRUE, 'ignore'],
      [$two_digits, FALSE, 'later'],
    ];
    foreach ($cases as [$message, $contrib, $expected]) {
      [, $category] = (new MessageCategorizer())->categorize($message, $this->getExtension($contrib));
      $this->assertSame($expected, $category, ($contrib ? 'Contributed: ' : 'Custom: ') . $message);
    }

    // A deprecation that is removed after the next major version is ignored,
    // even if rector can fix it.
    [, $category] = (new MessageCategorizer())->categorize('Call to deprecated function hide(). Deprecated in drupal:11.4.0 and is removed from drupal:13.0.0. To hide form elements, use [\'#access\'] = FALSE. For render elements, use [\'#printed\'] = TRUE.', $this->getExtension(FALSE));
    $this->assertSame($next < 13 ? 'ignore' : 'rector', $category);
  }

  /**
   * Tests deprecations of other projects and dependencies of core.
   */
  public function testOtherDeprecations(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $categorizer = new MessageCategorizer();
    foreach ([TRUE, FALSE] as $contrib) {
      $extension = $this->getExtension($contrib);

      // Other projects remove deprecations in their own releases, which may
      // or may not be needed for the next major version of Drupal. These
      // have a category of their own.
      [, $category] = $categorizer->categorize('Call to method foo() of deprecated class Drupal\ai\Bar. Deprecated in ai_agents:1.2.0 and is removed from ai:2.0.0. Use Baz instead.', $extension);
      $this->assertSame('contrib', $category);

      // Symfony 7 deprecations are removed in Symfony 8, which Drupal 12
      // requires. Drupal 11 requires Symfony 7, and Drupal 13's version is
      // not known yet.
      $expected = match ($major) {
        10 => 'ignore',
        11 => $contrib ? 'later' : 'safe',
        default => 'later',
      };
      [, $category] = $categorizer->categorize('Call to deprecated method get() of class Symfony\Component\HttpFoundation\Request: since Symfony 7.4, use properties `->attributes`, `query` or `request` directly instead', $extension);
      $this->assertSame($expected, $category);
      [, $category] = $categorizer->categorize('Since symfony/http-foundation 7.4: Foo is deprecated.', $extension);
      $this->assertSame($expected, $category);

      // Twig 3 deprecations are removed in Twig 4. Drupal 11 and 12 require
      // Twig 3.
      [, $category] = $categorizer->categorize('Since twig/twig 3.12: Twig Filter "spaceless" is deprecated.', $extension);
      $this->assertSame($major < 12 ? 'ignore' : 'later', $category);

      // PHP removes deprecations in a major version. If the next major Drupal
      // version requires the PHP major version that runs this test, functions
      // and methods of PHP are not required to fix for it. Otherwise that is
      // not known, and the fix is for later.
      $required_php = ProjectCollector::MINIMUM_PHP[$major + 1] ?? NULL;
      $php = $required_php !== NULL && (int) $required_php === PHP_MAJOR_VERSION ? 'ignore' : 'later';
      [, $category] = $categorizer->categorize('Call to deprecated method setAccessible() of class ReflectionMethod.', $extension);
      $this->assertSame($php, $category);
      [, $category] = $categorizer->categorize('Call to deprecated function utf8_encode().', $extension);
      $this->assertSame($php, $category);
      // Functions and classes without a namespace that are not built into PHP
      // can't be categorized.
      [, $category] = $categorizer->categorize('Call to deprecated function upgrade_status_test_undefined_function().', $extension);
      $this->assertSame('uncategorized', $category);
      [, $category] = $categorizer->categorize('Call to deprecated method foo() of class UpgradeStatusTestGlobalClass.', $extension);
      $this->assertSame('uncategorized', $category);
    }
  }

  /**
   * Tests deprecations with a fix that also works on older Drupal versions.
   */
  public function testFixesForOlderVersions(): void {
    $next = ProjectCollector::getDrupalCoreMajorVersion() + 1;
    $oldest = ProjectCollector::getOldestSupportedMinor();
    $constant = static fn (string $class, string $name): string => (new \ReflectionClassConstant($class, $name))->getValue();
    $removed = 'and is removed from drupal:12.0.0.';

    // The messages as the analyzers create them.
    $compatible = [
      "The options of the Choice constraint in this config schema are not a named array. Deprecated in drupal:11.4.0 $removed Name each option, for example Choice: {choices: [a, b]} instead of Choice: [a, b]. See https://www.drupal.org/node/3554746.",
      $constant(PluginDefinitionDeprecationAnalyzer::class, 'ACCESS_CALLBACK'),
      sprintf($constant(PluginDefinitionDeprecationAnalyzer::class, 'LAYOUT'), 'foo_layout'),
      "The core/recipes/article_content_type core recipe is applied. Deprecated in drupal:11.4.0 $removed Core no longer uses it. Add the configuration you need to this recipe instead. See https://www.drupal.org/project/drupal/issues/3608069.",
    ];
    // The fixes of these need the Drupal version of the deprecation.
    $not_compatible = [
      '11.3' => "The status setting of the content block in block.block.foo is not used. Deprecated in drupal:11.3.0 $removed Remove the setting. See https://www.drupal.org/node/3499836.",
      '11.4' => $constant(TemplateDeprecationAnalyzer::class, 'ITEM_ATTRIBUTES'),
    ];

    $categorizer = new MessageCategorizer();
    // A site on the major version before Drupal 11 does not need these fixes
    // for its next major version.
    $ignored = $next < 12;
    foreach ($compatible as $message) {
      $this->assertStringContainsString($removed, $message);
      [, $category] = $categorizer->categorize($message, $this->getExtension(TRUE));
      $this->assertSame($ignored ? 'ignore' : 'old', $category, "Contributed: $message");
      [, $category] = $categorizer->categorize($message, $this->getExtension(FALSE));
      $this->assertSame($ignored ? 'ignore' : 'safe', $category, "Custom: $message");
    }
    foreach ($not_compatible as $version => $message) {
      $expected = version_compare($version, $oldest) <= 0 ? 'old' : 'later';
      [, $category] = $categorizer->categorize($message, $this->getExtension(TRUE));
      $this->assertSame($ignored ? 'ignore' : $expected, $category, "Contributed: $message");
    }

    // The version of the fix decides. A fix that only works on a version
    // after this site's version cannot be made yet. The messages are removed
    // in the next major version of this site, so they are not ignored.
    $major = $next - 1;
    $message = "The foo_layout layout has no label. Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Add a label to the layout definition.";
    $versions = new \ReflectionProperty($categorizer, 'fixVersions');
    $pattern = '/^The \S+ layout has no label\./';
    foreach ([TRUE, FALSE] as $contrib) {
      $versions->setValue($categorizer, [$pattern => "$major.98"]);
      [, $category] = $categorizer->categorize($message, $this->getExtension($contrib));
      $this->assertSame('later', $category, 'Fix in a later version');
      $versions->setValue($categorizer, [$pattern => ($major - 1) . '.0']);
      [, $category] = $categorizer->categorize($message, $this->getExtension($contrib));
      $this->assertSame($contrib ? 'old' : 'safe', $category, 'Fix in an older version');
    }
  }

  /**
   * Tests the messages about requirements and dependencies.
   */
  public function testRequirements(): void {
    $categorizer = new MessageCategorizer();
    $requirements = [
      'Add core_version_requirement to designate which Drupal versions is the extension compatible with. See https://drupal.org/node/3070687.',
      'Value of core_version_requirement: ^10 || ^11 is not compatible with the next major version of Drupal core. See https://drupal.org/node/3070687.',
      'The drupal/core requirement is not compatible with the next major version of Drupal. Either remove it or update it to be compatible.',
      'The PHP requirement (^8.1) is not compatible with PHP 8.5, which the next major version of Drupal requires.',
      'The symfony/process requirement (^6.4) is not compatible with Symfony 8, which the next major version of Drupal requires.',
      'This extension depends on the contact module, which is deprecated in Drupal core. See https://www.drupal.org/node/3223395.',
      'The base theme is the classy theme, which is deprecated. See https://www.drupal.org/node/3305674.',
      'The drupal/contact package is required, and it provides the contact module, which is deprecated. See https://www.drupal.org/node/3223395.',
      'The recipe installs the contact module, which is deprecated in Drupal core.',
      'The recipe imports configuration from the contact module, which is deprecated in Drupal core.',
      'The configuration depends on the contact module, which is deprecated in Drupal core.',
    ];
    foreach ([TRUE, FALSE] as $contrib) {
      foreach ($requirements as $message) {
        [, $category] = $categorizer->categorize($message, $this->getExtension($contrib));
        $this->assertSame('requirements', $category, $message);
      }
      // Other messages without a Drupal version are still to check manually,
      // also the ones about files with requirements.
      foreach ([
        'Parse error in composer.json. Having a composer.json is not a requirement in general, but if there is one, it should be valid.',
        "This extension is deprecated. Don't use it. See https://www.drupal.org/node/3223395.",
        'Call to an undefined method Drupal\Foo::bar().',
      ] as $message) {
        [, $category] = $categorizer->categorize($message, $this->getExtension($contrib));
        $this->assertSame('uncategorized', $category, $message);
      }
    }

    // Both new categories are warnings, and need a manual step.
    $contrib_message = 'Call to deprecated method foo() of class Drupal\bar\Baz. Deprecated in bar:2.1.0 and is removed from bar:3.0.0.';
    $messages = [
      ['message' => $requirements[0], 'line' => 1],
      ['message' => $contrib_message, 'line' => 2],
    ];
    $result = $categorizer->categorizeResults([
      'data' => [
        'totals' => ['errors' => 2, 'file_errors' => 2],
        'files' => ['foo.info.yml' => ['messages' => $messages]],
      ],
    ], $this->getExtension(FALSE));
    $this->assertSame(['requirements' => 1, 'contrib' => 1], $result['data']['totals']['upgrade_status_category']);
    $this->assertSame(['warning' => 2], $result['data']['totals']['upgrade_status_split']);
    $this->assertSame(ProjectCollector::NEXT_MANUAL, $result['data']['totals']['upgrade_status_next']);
  }

  /**
   * Tests the totals and the next step of categorized results.
   */
  public function testResults(): void {
    $major = ProjectCollector::getDrupalCoreMajorVersion();
    $next = $major + 1;
    $now = "Call to deprecated function old_function(). Deprecated in drupal:" . ($major - 1) . ".0.0 and is removed from drupal:$next.0.0. Use new_function() instead.";
    $later = "Call to deprecated function new_function(). Deprecated in drupal:$major.99.0 and is removed from drupal:$next.0.0. Use newer_function() instead.";
    $ignore = "Call to deprecated function future_function(). Deprecated in drupal:" . ($major - 1) . ".0.0 and is removed from drupal:" . ($next + 1) . ".0.0. Use new_function() instead.";
    $rector = 'Missing call to parent::setUp() method.';
    $unknown = 'Call to an undefined method Drupal\Foo::bar().';
    $requirement = 'Add core_version_requirement to designate which Drupal versions is the extension compatible with.';

    $cases = [
      // With only requirements to update, no problems are known in the code,
      // so the project has a next step of its own.
      'only a requirement' => [
        [$requirement],
        ProjectCollector::NEXT_REQUIREMENTS,
        ['requirements' => 1],
        ['warning' => 1],
      ],
      'a requirement and a message to ignore' => [
        [$ignore, $requirement],
        ProjectCollector::NEXT_REQUIREMENTS,
        ['ignore' => 1, 'requirements' => 1],
        ['warning' => 1],
      ],
      'a requirement, then a problem' => [
        [$requirement, $now],
        ProjectCollector::NEXT_MANUAL,
        ['requirements' => 1, 'safe' => 1],
        ['warning' => 1, 'error' => 1],
      ],
      'a problem, then a requirement' => [
        [$unknown, $requirement],
        ProjectCollector::NEXT_MANUAL,
        ['uncategorized' => 1, 'requirements' => 1],
        ['warning' => 2],
      ],
      'a requirement and rector' => [
        [$requirement, $rector],
        ProjectCollector::NEXT_RECTOR,
        ['requirements' => 1, 'rector' => 1],
        ['warning' => 1, 'error' => 1],
      ],
      'no messages' => [[], ProjectCollector::NEXT_RELAX, [], []],
      'to fix now' => [[$now], ProjectCollector::NEXT_MANUAL, ['safe' => 1], ['error' => 1]],
      'rector covers one' => [
        [$now, $rector],
        ProjectCollector::NEXT_RECTOR,
        ['safe' => 1, 'rector' => 1],
        ['error' => 2],
      ],
      'rector first' => [
        [$rector, $unknown],
        ProjectCollector::NEXT_RECTOR,
        ['rector' => 1, 'uncategorized' => 1],
        ['error' => 1, 'warning' => 1],
      ],
      'unknown' => [[$unknown], ProjectCollector::NEXT_MANUAL, ['uncategorized' => 1], ['warning' => 1]],
      // A project with only messages to fix later is also put under fix
      // manually.
      'to fix later' => [[$later], ProjectCollector::NEXT_MANUAL, ['later' => 1], ['warning' => 1]],
      // Messages to ignore are for a later major version, so a project with
      // only those has nothing to do for the next one.
      'to ignore' => [[$ignore], ProjectCollector::NEXT_RELAX, ['ignore' => 1], []],
      'to ignore and to fix' => [
        [$ignore, $now],
        ProjectCollector::NEXT_MANUAL,
        ['ignore' => 1, 'safe' => 1],
        ['error' => 1],
      ],
      'to ignore and rector' => [
        [$ignore, $rector],
        ProjectCollector::NEXT_RECTOR,
        ['ignore' => 1, 'rector' => 1],
        ['error' => 1],
      ],
    ];
    $categorizer = new MessageCategorizer();
    foreach ($cases as $label => [$messages, $next_step, $categories, $split]) {
      $rows = array_map(static fn (string $message): array => ['message' => $message, 'line' => 1], $messages);
      $result = [
        'date' => 1,
        'data' => [
          'totals' => ['errors' => count($messages), 'file_errors' => count($messages)],
          'files' => $rows ? ['foo.php' => ['messages' => $rows]] : [],
        ],
      ];
      $result = $categorizer->categorizeResults($result, $this->getExtension(FALSE));
      $totals = $result['data']['totals'];
      $this->assertSame($next_step, $totals['upgrade_status_next'], $label);
      $this->assertSame($categories, $totals['upgrade_status_category'] ?? [], $label);
      $this->assertSame($split, $totals['upgrade_status_split'] ?? [], $label);
      $this->assertSame(count($messages), $totals['errors'], $label);
      $stored = array_column($result['data']['files']['foo.php']['messages'] ?? [], 'upgrade_status_category');
      $this->assertSame(array_keys($categories), array_values(array_unique($stored)), $label);
    }

    // The stored message is the reworded one, without control characters.
    $result = $categorizer->categorizeResults([
      'data' => [
        'totals' => [],
        'files' => [
          'foo.php' => [
            'messages' => [
              [
                'message' => "Call to deprecated function foo():\n  in drupal:8.0.0 and \x1B[31mis\x07 removed.",
                'line' => 1,
              ],
            ],
          ],
        ],
      ],
    ], $this->getExtension(TRUE));
    $this->assertSame('Call to deprecated function foo(). Deprecated in drupal:8.0.0 and [31mis removed.', $result['data']['files']['foo.php']['messages'][0]['message']);
  }

  /**
   * Creates an extension.
   *
   * @param bool $contrib
   *   TRUE for a contributed project, FALSE for a custom one.
   *
   * @return \Drupal\Core\Extension\Extension
   *   The extension.
   */
  protected function getExtension(bool $contrib): Extension {
    $extension = new Extension($this->root, 'module', 'core/modules/system/system.info.yml');
    $extension->info = $contrib ? ['project' => 'foo'] : [];
    return $extension;
  }

}
