<?php

declare(strict_types=1);

namespace Drupal\country_block\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Country Block settings for this site.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'country_block_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['country_block.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('country_block.settings');

    $form['blocked_countries'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Blocked Countries'),
      '#description' => $this->t('Enter a list of two-letter country codes to block, one per line. A list of country codes can be found at <a href=":url" target="_blank">Wikipedia</a>.', [':url' => 'https://en.wikipedia.org/wiki/ISO_3166-1_alpha-2']),
      '#default_value' => implode("\n", $config->get('blocked_countries') ?? []),
    ];

    $form['message'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Blocked Message'),
      '#description' => $this->t('The message to show to users from blocked countries.'),
      '#default_value' => $config->get('message') ?? 'Access from your country is not permitted.',
      '#required' => TRUE,
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $blocked_countries = array_filter(array_map('trim', explode("\n", $form_state->getValue('blocked_countries'))));
    
    $this->config('country_block.settings')
      ->set('blocked_countries', array_values(array_unique($blocked_countries)))
      ->set('message', $form_state->getValue('message'))
      ->save();

    parent::submitForm($form, $form_state);
  }

}
