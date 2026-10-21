<?php

namespace Drupal\advupdate\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * Hook implementations for advupdate.
 */
class AdvupdateHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_help().
   */
  #[Hook('help')]
  public function help($route_name, RouteMatchInterface $route_match): string {
    $output = '';
    switch ($route_name) {
      // Main module help for the advupdate module.
      case 'help.page.advupdate':
        $output = '<h3>' . $this->t('About') . '</h3>';
        $output .= '<p>' . $this->t('The module modifies the Drupal "Updates available" email to include the information normally shown at /admin/reports/updates/update, with links to the module updates and their release notes.') . '</p>';
      default:
    }
    return $output;
  }

  /**
   * Implements hook_form_FORM_ID_alter().
   */
  #[Hook('form_update_settings_alter')]
  public function formUpdateSettingsAlter(&$form, FormStateInterface $form_state, $form_id): void {
    $advupdate_config = \Drupal::config('advupdate.settings');
    $form['update_notification_extend_email_report'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Expand the report using "Update Manager Advanced" module'),
      '#default_value' => $advupdate_config->get('notification.extend_email_report'),
      '#description' => $this->t('An email will include more detailed information with a list of modules.'),
    ];
    $form['#submit'][] = 'advupdate_form_update_settings_submit_handler';
  }

  /**
   * Implements hook_mail_alter().
   */
  #[Hook('mail_alter')]
  public function mailAlter(&$message): void {
    if ($message['id'] == 'update_status_notify'
      && \Drupal::config('advupdate.settings')
        ->get('notification.extend_email_report') == 1) {
      if ($additional_data = _advupdate_mail_additional_data()) {
        $message['body'][] = "";
        $message['body'][] = $additional_data;
        $message['body'][] = $this->t('This emails also may contain additional information added by the "Update Manager Advanced" module (:url).', [
          ':url' => Url::fromUri('https://www.drupal.org/project/advupdate', [
            'absolute' => TRUE,
          ])->toString(),
        ]);
      }
    }
  }

}
