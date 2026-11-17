<?php

declare(strict_types=1);

namespace Drupal\acme_settings\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Settings for the Acme API client.
 */
final class SettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'acme_settings_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['acme_settings.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('acme_settings.settings');
    $form['api_url'] = [
      '#type' => 'url',
      '#title' => $this->t('API URL'),
      '#default_value' => $config->get('api_url'),
    ];
    $form['retries'] = [
      '#type' => 'number',
      '#title' => $this->t('Retries'),
      '#min' => 0,
      '#default_value' => $config->get('retries'),
    ];
    $form['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enabled'),
      '#default_value' => $config->get('enabled'),
    ];
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('acme_settings.settings')
      ->set('api_url', $form_state->getValue('api_url'))
      ->set('retries', (int) $form_state->getValue('retries'))
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->save();
    parent::submitForm($form, $form_state);
  }

}
