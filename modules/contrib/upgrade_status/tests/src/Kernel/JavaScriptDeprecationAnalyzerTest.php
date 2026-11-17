<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding deprecated dialog features.
 *
 * @group upgrade_status
 * @coversDefaultClass \Drupal\upgrade_status\JavaScriptDeprecationAnalyzer
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class JavaScriptDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_dialog',
  ];

  /**
   * Tests which uses of deprecated dialog features are reported.
   *
   * @covers ::analyze
   */
  public function testAnalyze(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_dialog');
    $path = $extension->getPath();
    $name = 'upgrade_status_test_dialog';

    $deprecations = $this->container->get('upgrade_status.javascript_deprecation_analyzer')->analyze($extension);

    $option = 'The dialogClass dialog option is used. Deprecated in drupal:10.3.0 and is removed from drupal:12.0.0. Drupal 12 ignores it, so the dialog does not get the class. Use the ui-dialog key of the classes option instead. See https://www.drupal.org/node/3440844.';
    $event = 'A jQuery handler of a dialog event is used. Deprecated in drupal:10.3.0 and is removed from drupal:12.0.0. Drupal 12 only passes the event to the handler, without the dialog, the element and the settings. Use addEventListener(), and read event.dialog, event.target and event.settings instead. See https://www.drupal.org/node/3422670.';
    // cspell:ignore blacklist
    $attribute = 'The data-autocomplete-first-character-blacklist attribute is used. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 ignores it. Use the data-autocomplete-first-character-denylist attribute instead. See https://www.drupal.org/node/3472016.';
    $autocomplete_option = 'The firstCharacterBlacklist autocomplete option is used. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 ignores it. Use firstCharacterDenyList instead. See https://www.drupal.org/node/3472016.';
    $contextual = 'Drupal.contextual.views or Drupal.contextual.regionViews is used. Deprecated in drupal:9.4.0 and is removed from drupal:12.0.0. There is no replacement. See https://www.drupal.org/project/drupal/issues/3442530.';
    $buttons = 'The dialogButtonsChange jQuery event is triggered. Deprecated in drupal:11.2.0 and is removed from drupal:12.0.0. Drupal 12 does not update the dialog buttons for it. Dispatch a dialogButtonsChange CustomEvent on the dialog element instead. See https://www.drupal.org/node/3464202.';
    $this->assertEqualsCanonicalizing(
      [
        "$path/js/autocomplete.js:8 $attribute",
        "$path/js/autocomplete.js:9 $autocomplete_option",
        "$path/js/autocomplete.js:10 $contextual",
        "$path/js/autocomplete.js:11 $contextual",
        "$path/js/dialog.js:12 $option",
        "$path/js/dialog.js:17 $option",
        "$path/js/dialog.js:19 $option",
        "$path/js/events.js:6 $event",
        "$path/js/events.js:9 $event",
        "$path/js/events.js:11 $event",
        "$path/js/events.js:14 $event",
        "$path/js/events.js:26 $buttons",
        "$path/templates/upgrade-status-test-dialog.html.twig:5 $option",
        "$path/templates/upgrade-status-test-dialog.html.twig:6 $option",
        "$path/templates/upgrade-status-test-dialog.html.twig:9 $attribute",
        "$path/$name.module:32 $attribute",
        "$path/tests/modules/{$name}_sub/{$name}_sub.module:12 $option",
        "$path/$name.ckeditor5.yml:7 $option",
        "$path/$name.links.menu.yml:8 $option",
        "$path/$name.module:14 $option",
        "$path/$name.module:15 $option",
        "$path/$name.module:17 $option",
      ],
      array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations),
    );

    foreach ($deprecations as $deprecation) {
      $this->assertSame('JavaScriptDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
  }

}
