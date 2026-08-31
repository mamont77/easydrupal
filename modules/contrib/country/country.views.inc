<?php

/**
 * @file
 * Provides views filter and sort options.
 */

use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\country\Hook\CountryViewsHooks;
use Drupal\field\FieldStorageConfigInterface;

/**
 * Implements hook_field_views_data_alter().
 *
 * Views integration for country field.
 *
 * @see views_field_default_views_data()
 */
#[LegacyHook]
function country_field_views_data_alter(array &$data, FieldStorageConfigInterface $field_storage) {
  \Drupal::service(CountryViewsHooks::class)->fieldViewsDataAlter($data, $field_storage);
}
