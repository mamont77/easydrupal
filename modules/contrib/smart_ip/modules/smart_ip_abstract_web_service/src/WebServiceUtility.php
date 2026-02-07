<?php

/**
 * @file
 * Contains \Drupal\smart_ip_abstract_web_service\WebServiceUtility.
 */

namespace Drupal\smart_ip_abstract_web_service;

use Drupal\smart_ip_abstract_web_service\EventSubscriber\SmartIpEventSubscriber;
use Drupal\smart_ip\WebServiceUtilityBase;
use Drupal\Component\Serialization\Json;

/**
 * Utility methods class wrapper.
 *
 * @package Drupal\smart_ip_abstract_web_service
 */
class WebServiceUtility extends WebServiceUtilityBase {

  /**
   * {@inheritdoc}
   */
  public static function getUrl(string $ipAddress = NULL): string {
    if (!empty($ipAddress)) {
      $config  = \Drupal::config(SmartIpEventSubscriber::configName());
      $apiKey  = $config->get('api_key');
      $version = $config->get('version');
      if ($version == 1) {
        return AbstractWebService::V1_URL . "?api_key=$apiKey&ip_address=$ipAddress";
      }
    }
    return '';
  }

  /**
   * {@inheritdoc}
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function getGeolocation(string $ipAddress = NULL): array {
    $url  = self::getUrl($ipAddress);
    $json = self::sendRequest($url);
    $data = Json::decode($json);
    return $data ?? [];
  }

}
