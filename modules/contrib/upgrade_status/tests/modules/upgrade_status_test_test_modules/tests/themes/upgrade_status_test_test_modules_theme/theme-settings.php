<?php

/**
 * @file
 * Theme settings of the test theme.
 */

declare(strict_types=1);

use Drupal\Core\Form\FormStateInterface;

/**
 * Implements hook_form_system_theme_settings_alter().
 */
function upgrade_status_test_test_modules_theme_form_system_theme_settings_alter(array &$form, FormStateInterface $form_state): void {
  $form['upgrade_status_test'] = ['#type' => 'checkbox', '#title' => 'Test'];
}
