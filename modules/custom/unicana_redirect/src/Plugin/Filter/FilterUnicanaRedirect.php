<?php
/**
 * @file
 * Contains Drupal\unicana_redirect\Plugin\Filter\FilterUnicanaRedirect
 */

namespace Drupal\unicana_redirect\Plugin\Filter;

use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Component\Utility\UrlHelper;

/**
 * Provides a filter to help celebrate good times!
 *
 * @Filter(
 *   id = "unicana_redirect",
 *   title = @Translation("Unicana Redirect Filter"),
 *   description = @Translation("Detect links on the website and add tracking
 *   codes."), type =
 *   Drupal\filter\Plugin\FilterInterface::TYPE_MARKUP_LANGUAGE,
 * )
 */
class FilterUnicanaRedirect extends FilterBase {

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {

    $redirect_mapping = array();
    $redirects = \Drupal::entityTypeManager()
      ->getStorage('redirect')
      ->loadMultiple();
    foreach ($redirects as $redirect) {
      $redirect_mapping[$redirect->label]['replacement_pattern'] = $redirect->replacement_pattern;
      $redirect_mapping[$redirect->label]['type_of_the_pattern'] = $redirect->type_of_the_pattern;
    }

    $urls = $this->linkExtractor($text);

    foreach ($urls as $url) {
      if (UrlHelper::isExternal($url)) {
        $parsed_url = parse_url($url);
        if (FALSE === $parsed_url) {
          continue;
        }

        $host_names_array = explode(".", $parsed_url['host']);
        $first_level_domain = $host_names_array[count($host_names_array) - 2]
          . "." . $host_names_array[count($host_names_array) - 1];

        if (isset($redirect_mapping[$first_level_domain])) {
          $new_url = '';
          switch ($redirect_mapping[$first_level_domain]['type_of_the_pattern']) {
            // Before.
            case 0:
              $new_url = $redirect_mapping[$first_level_domain]['replacement_pattern'] . urlencode($url);

              break;

            // Inside.
            case 1:
              //TODO: For now just replace ALL link.
              $new_url = $redirect_mapping[$first_level_domain]['replacement_pattern'];
              if (isset($parsed_url['path']) && $parsed_url['path'] != '') {
                $new_url .= $parsed_url['path'];
              }
              if (isset($parsed_url['query']) && $parsed_url['query'] != '') {
                $new_url .= '?' . $parsed_url['query'];
              }
              if (isset($parsed_url['fragment']) && $parsed_url['fragment'] != '') {
                $new_url .= '#' . $parsed_url['fragment'];
              }
              break;

            // After.
            case 2:
              $new_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];
              if (isset($parsed_url['path']) && $parsed_url['path'] != '') {
                $new_url .= $parsed_url['path'];
              }
              if (isset($parsed_url['query']) && $parsed_url['query'] != '') {
                $new_url .= '?' . $parsed_url['query'] . '&' . $redirect_mapping[$first_level_domain]['replacement_pattern'];
              }
              else {
                $new_url .= '?' . $redirect_mapping[$first_level_domain]['replacement_pattern'];
              }
              if (isset($parsed_url['fragment']) && $parsed_url['fragment'] != '') {
                $new_url .= '#' . $parsed_url['fragment'];
              }
              break;
          }
          if ($new_url !== '') {
            $text = str_replace('href="' . $url . '"', 'href="' . $new_url . '"', $text);
          }
        }
      }
    }

    $result = new FilterProcessResult($text);

    return $result;
  }

  /**
   * Find all URL's in the HTML.
   *
   * @param $html
   *
   * @return array
   */
  public function linkExtractor($html) {
    $link_array = array();
    if (preg_match_all('/<a\s+.*?href=[\"\']?([^\"\' >]*)[\"\']?[^>]*>(.*?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
      foreach ($matches as $match) {
        array_push($link_array, $match[1]);
      }
    }
    return $link_array;
  }

}
