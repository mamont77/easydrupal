<?php

namespace Drupal\country\Plugin\Field\FieldWidget;

use Drupal\Core\Entity\FieldableEntityInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\country\CountryFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Plugin implementation of the 'country_prioritized' widget.
 *
 * @FieldWidget(
 *   id = "country_prioritized",
 *   label = @Translation("Select list with priorities"),
 *   field_types = {
 *     "country"
 *   },
 *   multiple_values = TRUE
 * )
 */
class CountryPrioritizedWidget extends CountryDefaultWidget {

  /**
   * The country field manager.
   *
   * @var \Drupal\country\CountryFieldManager
   */
  protected $countryFieldManager;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->setCountryFieldManager($container->get('country.field.manager'));
    return $instance;
  }

  /**
   * Sets the country field manager.
   *
   * @param \Drupal\country\CountryFieldManager $country_field_manager
   *   The country field manager.
   */
  public function setCountryFieldManager(CountryFieldManager $country_field_manager): void {
    $this->countryFieldManager = $country_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function defaultSettings() {
    return [
      'priority_countries' => [],
      'priority_group_title' => '--- Most chosen countries ---',
      'show_priority_separator' => FALSE,
      'priority_separator_text' => '--- Other countries ---',
    ] + parent::defaultSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['priority_countries'] = [
      '#type' => 'select',
      '#title' => $this->t('Priority countries'),
      '#default_value' => $this->getSetting('priority_countries'),
      '#options' => $this->countryFieldManager->getSelectableCountries($this->fieldDefinition),
      '#description' => $this->t('Countries that appear at the top of the list, in alphabetical order. On single value fields, they also stay in their alphabetical position when a group title or a separator is set.'),
      '#multiple' => TRUE,
      '#size' => 10,
      '#required' => TRUE,
    ];

    $element['priority_group_title'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Priority group title'),
      '#default_value' => $this->getSetting('priority_group_title'),
      '#description' => $this->t('Title of the group of priority countries. Leave empty to list the priority countries without a group.'),
    ];

    $element['show_priority_separator'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show separator between priority and other countries'),
      '#default_value' => $this->getSetting('show_priority_separator'),
      '#description' => $this->t('Group the other countries under the separator text.'),
    ];

    $element['priority_separator_text'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Separator text'),
      '#default_value' => $this->getSetting('priority_separator_text'),
      '#description' => $this->t('Title of the group of other countries. It must differ from the priority group title.'),
      '#element_validate' => [[static::class, 'validateSeparatorText']],
      '#states' => [
        'visible' => [
          ':input[name="fields[' . $this->fieldDefinition->getName() . '][settings_edit_form][settings][show_priority_separator]"]' => ['checked' => TRUE],
        ],
      ],
    ];

    return $element;
  }

  /**
   * Validates that the separator text differs from the priority group title.
   *
   * Both are optgroup labels, so a duplicate label would merge the groups.
   *
   * @param array $element
   *   The separator text element.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateSeparatorText(array $element, FormStateInterface $form_state): void {
    $settings = $form_state->getValue(array_slice($element['#parents'], 0, -1));
    if (!empty($settings['show_priority_separator']) && $settings['priority_separator_text'] !== '' && $settings['priority_separator_text'] === $settings['priority_group_title']) {
      $form_state->setError($element, new TranslatableMarkup('The separator text must differ from the priority group title.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function settingsSummary() {
    $summary = parent::settingsSummary();

    $countries = $this->countryFieldManager->getSelectableCountries($this->fieldDefinition);
    $priority_names = [];
    foreach ($this->getSetting('priority_countries') as $code) {
      if (isset($countries[$code])) {
        $priority_names[] = $countries[$code];
      }
    }
    if (!$priority_names) {
      return $summary;
    }

    $summary[] = $this->t('Priority countries: @countries', [
      '@countries' => implode(', ', $priority_names),
    ]);

    $priority_group_title = (string) $this->getSetting('priority_group_title');
    if ($priority_group_title !== '') {
      $summary[] = $this->t('Priority group title: "@title"', [
        '@title' => $priority_group_title,
      ]);
    }
    else {
      $summary[] = $this->t('Priority countries without group');
    }

    if ($this->getSetting('show_priority_separator')) {
      $summary[] = $this->t('Separator: "@text"', [
        '@text' => $this->getSetting('priority_separator_text'),
      ]);
    }

    return $summary;
  }

  /**
   * {@inheritdoc}
   */
  protected function getOptions(FieldableEntityInterface $entity) {
    if (!isset($this->options)) {
      $this->options = $this->prioritizeOptions(parent::getOptions($entity));
    }
    return $this->options;
  }

  /**
   * Moves the priority countries to the top of the options.
   *
   * @param array $options
   *   The options, keyed by country code, with an optional '_none' option.
   *
   * @return array
   *   The options with the priority countries first. When a group title is
   *   set, the priority countries are in an optgroup. When the separator is
   *   enabled, the other countries are in an optgroup with the separator text
   *   as label.
   */
  protected function prioritizeOptions(array $options): array {
    $priority_options = [];
    foreach ($this->getSetting('priority_countries') as $code) {
      if (isset($options[$code])) {
        $priority_options[$code] = $options[$code];
      }
    }
    if (!$priority_options) {
      return $options;
    }

    $result = [];
    if (isset($options['_none'])) {
      $result['_none'] = $options['_none'];
      unset($options['_none']);
    }

    // In a multiple select, a country listed twice could be selected twice.
    if ($this->multiple) {
      $options = array_diff_key($options, $priority_options);
    }

    $priority_group_title = (string) $this->getSetting('priority_group_title');
    if ($priority_group_title !== '') {
      $result[$priority_group_title] = $priority_options;
    }
    else {
      $result += $priority_options;
    }

    $separator_text = $this->getSetting('show_priority_separator') ? (string) $this->getSetting('priority_separator_text') : '';
    // A separator with the same label as the priority group would replace it.
    if ($separator_text !== '' && $separator_text !== $priority_group_title) {
      $result[$separator_text] = $options;
    }
    else {
      // Countries that are already in the result are skipped when neither
      // list is in an optgroup, because option keys must be unique.
      $result += $options;
    }

    return $result;
  }

}
