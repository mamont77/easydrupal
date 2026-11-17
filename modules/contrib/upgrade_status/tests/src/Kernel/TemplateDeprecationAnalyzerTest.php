<?php

namespace Drupal\Tests\upgrade_status\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\upgrade_status\DeprecationMessage;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests finding uses of deprecated templates.
 *
 * @group upgrade_status
 */
#[RunTestsInSeparateProcesses]
#[Group('upgrade_status')]
final class TemplateDeprecationAnalyzerTest extends KernelTestBase {

  /**
   * Modules to test.
   *
   * @var array
   */
  protected static $modules = [
    'upgrade_status',
    'upgrade_status_test_templates_provider',
    'user',
  ];

  /**
   * Tests the messages for a module that uses deprecated templates.
   */
  public function testTemplates(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_templates');
    $path = $extension->getPath();
    $templates = "$path/templates";
    $hook = 'Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use "item_list" instead. See https://www.drupal.org/node/3157353';
    $hook_used = "The upgrade_status_test_deprecated theme hook is used. $hook";
    $tagged = 'Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. See https://www.drupal.org/node/3157353';
    $component = 'The upgrade_status_test_templates_provider:deprecated-card component is used. The component is deprecated and can be removed. Use another component.';

    $sut = $this->container->get('upgrade_status.template_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Uses of theme hooks, variables, templates and suggestions that are not
    // deprecated are not reported. A variable is reported once per line, and
    // not when it is an attribute, a hash key or a loop variable.
    $this->assertEqualsCanonicalizing([
      "$templates/upgrade-status-test-deprecated--custom.html.twig:1 The upgrade-status-test-deprecated--custom.html.twig template overrides the upgrade_status_test_deprecated theme hook. $hook",
      "$templates/upgrade-status-test-tagged.html.twig:1 The upgrade-status-test-tagged.html.twig template overrides a deprecated template. $tagged",
      "$templates/upgrade-status-test-variables.html.twig:8 The old variable of the upgrade_status_test_variables theme hook is used. Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use 'new' instead. See https://www.drupal.org/node/3157353",
      "$templates/upgrade-status-test-variables.html.twig:9 The older variable of the upgrade_status_test_variables theme hook is used. Deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. Use 'new' instead. See https://www.drupal.org/node/3157353",
      "$templates/upgrade-status-test-variables--legacy--custom.html.twig:1 The upgrade-status-test-variables--legacy--custom.html.twig template is for the upgrade_status_test_variables__legacy__custom theme suggestion. Deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. Use another theme suggestion, or add one with hook_theme_suggestions_upgrade_status_test_variables_alter(). See https://www.drupal.org/node/3157353",
      "$templates/upgrade-status-test-variables--legacy--custom.html.twig:7 The oldest variable of the upgrade_status_test_variables theme hook is used. Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use 'new' instead. See https://www.drupal.org/node/3157353",
      "$templates/upgrade-status-test-variables--old.html.twig:1 The upgrade-status-test-variables--old.html.twig template is for the upgrade_status_test_variables__old theme suggestion. Deprecated in drupal:11.0.0 and is removed from drupal:12.0.0. Use upgrade_status_test_variables__new instead. See https://www.drupal.org/node/3157353",
      "$templates/upgrade-status-test-uses.html.twig:7 The upgrade-status-test-tagged.html.twig template is used. $tagged",
      "$templates/upgrade-status-test-uses.html.twig:8 The upgrade-status-test-deprecated.html.twig template of the upgrade_status_test_deprecated theme hook is used. $hook",
      "$templates/upgrade-status-test-uses.html.twig:9 $component",
      "$templates/upgrade-status-test-uses.html.twig:10 The @upgrade_status_test_deprecated/missing.html.twig template is from the upgrade_status_test_deprecated module, which is deprecated. See https://drupal.org/project/upgrade_status.",
      "$path/upgrade_status_test_templates.module:13 $hook_used",
      "$path/upgrade_status_test_templates.module:14 $hook_used",
      "$path/upgrade_status_test_templates.module:18 $component",
      "$path/upgrade_status_test_templates.module:26 $hook_used",
      "$path/src/Hook/TemplateHooks.php:17 $hook_used",
    ], $this->formatMessages($deprecations));
    foreach ($deprecations as $deprecation) {
      $this->assertSame('TemplateDeprecationAnalyzer', $deprecation->getAnalyzer());
    }
  }

  /**
   * Tests the variable and theme suggestions that the Views module deprecates.
   *
   * The Views module deprecates them in hook implementations for the node and
   * comment theme hooks, instead of in hook_theme().
   */
  public function testViewsDeprecations(): void {
    $this->enableModules(['field', 'filter', 'text', 'node', 'comment', 'views']);
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_templates_views');
    $templates = $extension->getPath() . '/templates';

    $sut = $this->container->get('upgrade_status.template_deprecation_analyzer');
    $deprecations = $sut->analyze($extension);

    // Core deprecated the view variable of node templates in 11.1 and removed
    // it in 12. It deprecated the view variable of comment templates and the
    // node__view__* and comment__view__* suggestions in 11.3, for removal
    // in 13. The view variable of other theme hooks, a node type named view
    // and other suggestions are not reported.
    [$major, $minor] = array_map('intval', explode('.', \Drupal::VERSION));
    $expected = [];
    if ($major === 11 && $minor >= 1) {
      $expected[] = "$templates/node--article.html.twig:8 The view variable of the node theme hook is used. Deprecated in drupal:11.1.0 and is removed from drupal:12.0.0. There is no replacement. See https://www.drupal.org/node/3459903";
    }
    if (($major === 11 && $minor >= 3) || $major === 12) {
      $suggestion = 'Deprecated in drupal:11.3.0 and is removed from drupal:13.0.0. Use another theme suggestion, or add one with hook_theme_suggestions_%s_alter(). See https://www.drupal.org/node/3541462';
      $expected[] = "$templates/comment--comment.html.twig:7 The view variable of the comment theme hook is used. Deprecated in drupal:11.3.0 and is removed from drupal:13.0.0. There is no replacement. See https://www.drupal.org/node/3541463";
      $expected[] = "$templates/node--view--frontpage--page-1.html.twig:1 The node--view--frontpage--page-1.html.twig template is for the node__view__frontpage__page_1 theme suggestion. " . sprintf($suggestion, 'node');
      $expected[] = "$templates/comment--view--comments-recent.html.twig:1 The comment--view--comments-recent.html.twig template is for the comment__view__comments_recent theme suggestion. " . sprintf($suggestion, 'comment');
    }
    $this->assertEqualsCanonicalizing($expected, $this->formatMessages($deprecations));
  }

  /**
   * Tests which uses of #item_attributes are reported.
   *
   * Only files that mention an image formatter are checked, and comments are
   * not reported.
   */
  public function testItemAttributes(): void {
    $extension = $this->container->get('extension.list.module')->get('upgrade_status_test_item_attributes');
    $file = $extension->getPath() . '/src/Plugin/Field/FieldFormatter/ItemAttributesFormatter.php';
    $message = 'The #item_attributes property of an image formatter render array is used. Deprecated in drupal:11.4.0 and is removed from drupal:12.0.0. Use #attributes instead. See https://www.drupal.org/node/3554585.';

    $deprecations = $this->container->get('upgrade_status.template_deprecation_analyzer')->analyze($extension);

    $this->assertEqualsCanonicalizing(
      ["$file:21 $message", "$file:22 $message"],
      array_map(static fn (DeprecationMessage $message): string => $message->getFile() . ':' . $message->getLine() . ' ' . $message->getMessage(), $deprecations),
    );
  }

  /**
   * Tests that a project can use the templates that it deprecates.
   */
  public function testOwnTemplates(): void {
    $extension = $this->container->get('module_handler')->getModule('upgrade_status_test_templates_provider');
    $sut = $this->container->get('upgrade_status.template_deprecation_analyzer');
    $this->assertSame([], $sut->analyze($extension));
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
