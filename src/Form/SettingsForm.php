<?php

namespace Drupal\votingapi_widgets\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Class SettingsForm.
 *
 * @package Drupal\votingapi\Form
 *
 * @ingroup votingapi
 */
class SettingsForm extends ConfigFormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'votingapi_widgets_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return ['votingapi_widgets.settings'];
  }

  /**
   * Defines the settings form for Vote entities.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   Form definition array.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {

    $config = $this->config('votingapi_widgets.settings');

    $form['libraries'] = [
      '#type' => 'item',
      '#title' => $this->t('Libraries'),
      '#description' => $this->t('Some libraries may be included with themes or other modules. You can prevent duplicates of them from being included with Voting API Widgets.'),
    ];
    $form['libraries']['exclude_fontawesome'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Don't include the Font Awesome Library."),
      '#description' => $this->t('The Font Awesome library is required by the <em>Useful</em> vote plugin and required by a few styles.'),
      '#default_value' => $config->get('libraries.exclude_fontawesome'),
    ];
    $form['libraries']['exclude_glyphicons'] = [
      '#type' => 'checkbox',
      '#title' => $this->t("Don't include the Glyphicons Library."),
      '#description' => $this->t('The Glyphicons library is only required for the <em>Bootstrap stars</em> style.'),
      '#default_value' => $config->get('libraries.exclude_glyphicons'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('votingapi_widgets.settings');
    $config->set('libraries.exclude_fontawesome', $form_state->getValue('exclude_fontawesome'))
      ->set('libraries.exclude_glyphicons', $form_state->getValue('exclude_glyphicons'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
