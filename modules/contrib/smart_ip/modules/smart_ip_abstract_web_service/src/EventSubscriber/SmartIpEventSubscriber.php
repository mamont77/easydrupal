<?php

/**
 * @file
 * Contains \Drupal\smart_ip_abstract_web_service\EventSubscriber\SmartIpEventSubscriber.
 */

namespace Drupal\smart_ip_abstract_web_service\EventSubscriber;

use Drupal\smart_ip_abstract_web_service\WebServiceUtility;
use Drupal\smart_ip\GetLocationEvent;
use Drupal\smart_ip\AdminSettingsEvent;
use Drupal\smart_ip\DatabaseFileEvent;
use Drupal\smart_ip\SmartIpEventSubscriberBase;
use Drupal\Core\Link;
use Drupal\Core\Url;

/**
 * Core functionality of this Smart IP data source module.
 * Listens to Smart IP override events.
 *
 * @package Drupal\smart_ip_abstract_web_service\EventSubscriber
 */
class SmartIpEventSubscriber extends SmartIpEventSubscriberBase {

  /**
   * {@inheritdoc}
   */
  public static function sourceId(): string {
    return 'abstract_web_service';
  }

  /**
   * {@inheritdoc}
   */
  public static function configName(): string {
    return 'smart_ip_abstract_web_service.settings';
  }

  /**
   * {@inheritdoc}
   */
  public function processQuery(GetLocationEvent $event) {
    if ($event->getDataSource() == self::sourceId()) {
      $location = $event->getLocation();
      $ipAddress = $location->get('ipAddress');
      $record = WebServiceUtility::getGeolocation($ipAddress);
      $config = \Drupal::config(self::configName());
      $version = $config->get('version');
      if (!empty($record) && !isset($record['error'])) {
        if ($version == 1) {
          $country = isset($record['country']) ? $record['country'] : '';
          $countryCode = isset($record['country_code']) ? $record['country_code'] : '';
          $region = isset($record['region']) ? $record['region'] : '';
          $regionCode = isset($record['region_iso_code']) ? $record['region_iso_code'] : '';
          $city = isset($record['city']) ? $record['city'] : '';
          $zip = isset($record['postal_code']) ? $record['postal_code'] : '';
          $latitude = isset($record['latitude']) ? $record['latitude'] : '';
          $longitude = isset($record['longitude']) ? $record['longitude'] : '';
          $timeZone = isset($record['timezone']['name']) ? $record['timezone']['name'] : '';
          $isEuCountry = isset($record['isEuCountry']) ? $record['isEuCountry'] : '';
          $isGdprCountry = isset($record['isGdprCountry']) ? $record['isGdprCountry'] : '';
          $location->set('originalData', $record)
            ->set('country', $country)
            ->set('countryCode', mb_strtoupper($countryCode))
            ->set('region', $region)
            ->set('regionCode', $regionCode)
            ->set('city', $city)
            ->set('zip', $zip)
            ->set('latitude', $latitude)
            ->set('longitude', $longitude)
            ->set('timeZone', $timeZone)
            ->set('isEuCountry', $isEuCountry)
            ->set('isGdprCountry', $isGdprCountry);
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function formSettings(AdminSettingsEvent $event) {
    $config = \Drupal::config(self::configName());
    $form   = $event->getForm();
    $form['smart_ip_data_source_selection']['smart_ip_data_source']['#options'][self::sourceId()] = t(
      "Use @abstract web service. You will need a unique API key to use this and
      you must @register an Abstract account and @login to get and view your
      @api.", [
        '@abstract' => Link::fromTextAndUrl(t('Abstract IP Geolocation'), Url::fromUri('https://www.abstractapi.com/ip-geolocation-api'))->toString(),
        '@register'    => Link::fromTextAndUrl(t('register'), Url::fromUri('https://app.abstractapi.com/users/signup'))->toString(),
        '@login'    => Link::fromTextAndUrl(t('logged in'), Url::fromUri('https://app.abstractapi.com/users/login'))->toString(),
        '@api'      => Link::fromTextAndUrl(t('unique API'), Url::fromUri('https://app.abstractapi.com/api/ip-geolocation/tester'))->toString(),
      ]);
    $form['smart_ip_data_source_selection']['abstract_api_version'] = [
      '#type'          => 'select',
      '#title'         => t('Abstract IP Geolocation web service version'),
      '#default_value' => $config->get('version'),
      '#options'       => [1 => 1],
      '#description'   => t('Select an Abstract IP Geolocation web service version.'),
      '#states'        => [
        'visible' => [
          ':input[name="smart_ip_data_source"]' => ['value' => self::sourceId()],
        ],
      ],
    ];
    $form['smart_ip_data_source_selection']['abstract_api_key'] = [
      '#type'          => 'textfield',
      '#title'         => t('Abstract IP Geolocation web service API key'),
      '#description'   => t(
        'The use of Abstract IP Geolocation web service requires API key. Registration for the
        new API key is free, sign up @here.', [
          '@here' => Link::fromTextAndUrl(t('here'), Url::fromUri('https://app.abstractapi.com/users/signup'))->toString(),
        ]
      ),
      '#default_value' => $config->get('api_key'),
      '#states'        => [
        'visible' => [
          ':input[name="smart_ip_data_source"]' => ['value' => self::sourceId()],
        ],
      ],
    ];
    $event->setForm($form);
  }

  /**
   * {@inheritdoc}
   */
  public function validateFormSettings(AdminSettingsEvent $event) {
    /** @var \Drupal\Core\Form\FormStateInterface $formState */
    $formState  = $event->getFormState();
    if ($formState->getValue('smart_ip_data_source') == self::sourceId()) {
      if ($formState->isValueEmpty('abstract_api_key')) {
        $formState->setErrorByName('abstract_api_key', t('Please provide Abstract IP Geolocation web service API key.'));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitFormSettings(AdminSettingsEvent $event) {
    /** @var \Drupal\Core\Form\FormStateInterface $formState */
    $formState = $event->getFormState();
    if ($formState->getValue('smart_ip_data_source') == self::sourceId()) {
      $config = \Drupal::configFactory()->getEditable(self::configName());
      $config->set('version', $formState->getValue('abstract_api_version'))
        ->set('api_key', $formState->getValue('abstract_api_key'))
        ->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function manualUpdate(DatabaseFileEvent $event) {
  }

  /**
   * {@inheritdoc}
   */
  public function cronRun(DatabaseFileEvent $event) {
  }

}
