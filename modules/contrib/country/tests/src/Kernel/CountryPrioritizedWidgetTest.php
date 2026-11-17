<?php

declare(strict_types=1);

// AI generated.
namespace Drupal\Tests\country\Kernel;

use Drupal\Core\Field\FieldStorageDefinitionInterface;
use Drupal\Core\Form\FormState;
use Drupal\entity_test\Entity\EntityTest;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Drupal\country\Plugin\Field\FieldWidget\CountryPrioritizedWidget;

/**
 * Tests the select list with priorities widget.
 *
 * @group country
 */
#[Group('country')]
#[CoversClass(CountryPrioritizedWidget::class)]
#[RunTestsInSeparateProcesses]
class CountryPrioritizedWidgetTest extends CountryKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installConfig(['field', 'system']);
  }

  /**
   * Tests the options of the widget.
   *
   * @param array $settings
   *   The widget settings.
   * @param int $cardinality
   *   The field cardinality.
   * @param array $expected_start
   *   The expected first options. Optgroups list their first two countries.
   * @param bool $priority_listed_twice
   *   Whether the priority countries are also in the other countries.
   */
  #[DataProvider('providerOptions')]
  public function testOptions(array $settings, int $cardinality, array $expected_start, bool $priority_listed_twice): void {
    $options = $this->getWidgetOptions($settings, $cardinality);

    $start = [];
    foreach (array_slice($options, 0, count($expected_start), TRUE) as $key => $value) {
      $start[$key] = is_array($value) ? array_slice(array_keys($value), 0, 2) : $key;
    }
    $this->assertSame($expected_start, $start);

    // The other countries are the last optgroup, or the remaining options.
    $other = end($options);
    $other = is_array($other) ? $other : array_slice($options, count($expected_start), NULL, TRUE);
    $this->assertSame($priority_listed_twice, isset($other['NL']));
    $this->assertArrayHasKey('FR', $other);
  }

  /**
   * Data provider for testOptions().
   */
  public static function providerOptions(): array {
    $settings = [
      'priority_countries' => ['DE' => 'DE', 'NL' => 'NL'],
      'priority_group_title' => 'Most chosen',
      'show_priority_separator' => TRUE,
      'priority_separator_text' => 'Other countries',
    ];
    $unlimited = FieldStorageDefinitionInterface::CARDINALITY_UNLIMITED;
    return [
      'group and separator' => [
        $settings,
        1,
        ['_none' => '_none', 'Most chosen' => ['DE', 'NL'], 'Other countries' => ['AF', 'AX']],
        TRUE,
      ],
      'group without separator' => [
        ['show_priority_separator' => FALSE] + $settings,
        1,
        ['_none' => '_none', 'Most chosen' => ['DE', 'NL']],
        TRUE,
      ],
      'separator without group' => [
        ['priority_group_title' => ''] + $settings,
        1,
        ['_none' => '_none', 'DE' => 'DE', 'NL' => 'NL', 'Other countries' => ['AF', 'AX']],
        TRUE,
      ],
      'no group and no separator' => [
        ['priority_group_title' => '', 'show_priority_separator' => FALSE] + $settings,
        1,
        ['_none' => '_none', 'DE' => 'DE', 'NL' => 'NL'],
        FALSE,
      ],
      'multiple values' => [
        $settings,
        $unlimited,
        ['_none' => '_none', 'Most chosen' => ['DE', 'NL'], 'Other countries' => ['AF', 'AX']],
        FALSE,
      ],
      'multiple values, no group and no separator' => [
        ['priority_group_title' => '', 'show_priority_separator' => FALSE] + $settings,
        $unlimited,
        ['_none' => '_none', 'DE' => 'DE', 'NL' => 'NL', 'AF' => 'AF'],
        FALSE,
      ],
      'separator text equal to the group title' => [
        ['priority_separator_text' => 'Most chosen'] + $settings,
        $unlimited,
        ['_none' => '_none', 'Most chosen' => ['DE', 'NL'], 'AF' => 'AF'],
        FALSE,
      ],
    ];
  }

  /**
   * Tests that the options are unchanged without priority countries.
   */
  public function testWithoutPriorityCountries(): void {
    $options = $this->getWidgetOptions([], 1);

    $this->assertSame('_none', array_key_first($options));
    $this->assertSame('AF', array_keys($options)[1]);
    $this->assertNotContains(TRUE, array_map('is_array', $options));
  }

  /**
   * Tests that the separator text must differ from the group title.
   */
  public function testSeparatorTextValidation(): void {
    $parents = ['fields', 'field_country', 'settings_edit_form', 'settings'];
    $element = ['#parents' => [...$parents, 'priority_separator_text']];
    $settings = [
      'priority_group_title' => 'Countries',
      'show_priority_separator' => TRUE,
      'priority_separator_text' => 'Countries',
    ];

    $form_state = (new FormState())->setValue($parents, $settings);
    CountryPrioritizedWidget::validateSeparatorText($element, $form_state);
    $this->assertCount(1, $form_state->getErrors());

    $settings['priority_separator_text'] = 'Other countries';
    $form_state = (new FormState())->setValue($parents, $settings);
    CountryPrioritizedWidget::validateSeparatorText($element, $form_state);
    $this->assertCount(0, $form_state->getErrors());
  }

  /**
   * Returns the options of the widget.
   *
   * @param array $settings
   *   The widget settings.
   * @param int $cardinality
   *   The field cardinality.
   *
   * @return array
   *   The options of the select element.
   */
  protected function getWidgetOptions(array $settings, int $cardinality): array {
    $this->fieldStorage->setCardinality($cardinality)->save();
    \Drupal::service('entity_display.repository')
      ->getFormDisplay($this->entityType, $this->bundle)
      ->setComponent('field_country', [
        'type' => 'country_prioritized',
        'settings' => $settings,
      ])
      ->save();

    $form = \Drupal::service('entity.form_builder')->getForm(EntityTest::create());
    return $form['field_country']['widget']['#options'];
  }

}
