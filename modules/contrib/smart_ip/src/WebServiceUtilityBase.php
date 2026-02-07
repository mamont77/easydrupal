<?php

/**
 * @file
 * Contains \Drupal\smart_ip\WebServiceUtilityBase.
 */

namespace Drupal\smart_ip;

/**
 * Web service utility methods class wrapper.
 *
 * @package Drupal\smart_ip
 */
abstract class WebServiceUtilityBase implements WebServiceUtilityInterface {

  /**
   * {@inheritdoc}
   * @throws \GuzzleHttp\Exception\GuzzleException
   */
  public static function sendRequest(string $url = NULL): string {
    if (!empty($url)) {
      try {
        $response = \Drupal::httpClient()->get($url, ['headers' => ['Accept' => 'application/json']]);
        $data = (string) $response->getBody();
        if (empty($data)) {
          \Drupal::logger('smart_ip')->error(t('Empty response from @url', ['@url' => $url]));
        }
        else {
          return $data;
        }
      }
      catch (\Exception $e) {
        \Drupal::logger('smart_ip')->error(t('Sending request failed: @error', ['@error' => $e->getMessage()]));
      }
    }
    return '';
  }

}
