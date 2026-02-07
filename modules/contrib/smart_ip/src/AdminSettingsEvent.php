<?php

/**
 * @file
 * Contains \Drupal\smart_ip\AdminSettingsEvent.
 */

namespace Drupal\smart_ip;

use Drupal\Component\EventDispatcher\Event;
use Drupal\Core\Form\FormStateInterface;

/**
 * Provides Smart IP admin settings override event for event listeners.
 *
 * @package Drupal\smart_ip
 */
class AdminSettingsEvent extends Event {

  /**
   * Contains array of configuration names that will be editable.
   *
   * @var array
   */
  protected array $editableConfigNames;

  /**
   * Contains Smart IP admin settings $form.
   *
   * @var array
   */
  protected array $form;

  /**
   * Contains Smart IP admin settings $form.
   *
   * @var \Drupal\Core\Form\FormStateInterface
   */
  protected FormStateInterface $formState;

  /**
   * Get Form.
   *
   * @return array
   *   An associative array containing the structure of the form.
   */
  public function getForm(): array {
    return $this->form;
  }

  /**
   * Set Form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   */
  public function setForm(array $form) {
    $this->form = $form;
  }

  /**
   * Form State.
   *
   * @return \Drupal\Core\Form\FormStateInterface
   *   The current state of the form.
   */
  public function getFormState(): FormStateInterface {
    return $this->formState;
  }

  /**
   * Set the state of form.
   *
   * @param \Drupal\Core\Form\FormStateInterface $formState
   *   The current state of the form.
   */
  public function setFormState(FormStateInterface $formState) {
    $this->formState = $formState;
  }

  /**
   * Get the config names.
   *
   * @return array
   *   Config names.
   */
  public function getEditableConfigNames(): array {
    return $this->editableConfigNames;
  }

  /**
   * Set the config names.
   *
   * @param array $editableConfigNames
   *   Config names.
   */
  public function setEditableConfigNames(array $editableConfigNames) {
    $this->editableConfigNames = $editableConfigNames;
  }

}
