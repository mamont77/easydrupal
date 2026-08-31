<?php

namespace Drupal\country\Hook;

use Drupal\field\FieldStorageConfigInterface;
use Drupal\Core\Hook\Attribute\Hook;

/**
 * Hook implementations for country.
 */
class CountryViewsHooks {

  /**
   * Implements hook_field_views_data_alter().
   *
   * Views integration for country field.
   *
   * @see views_field_default_views_data()
   */
  #[Hook('field_views_data_alter')]
  public static function fieldViewsDataAlter(array &$data, FieldStorageConfigInterface $field_storage) {
    if ($field_storage->getType() == 'country') {
      foreach ($data as $table_name => $table_data) {
        foreach ($table_data as $field_name => $field_data) {
          if (isset($field_data['filter']) && $field_name != 'delta') {
            $data[$table_name][$field_name]['filter']['id'] = 'country_item';
          }
          if (isset($field_data['sort']) && $field_name != 'delta') {
            $data[$table_name][$field_name]['sort']['id'] = 'country_item';
          }
        }
      }
    }
  }

}
