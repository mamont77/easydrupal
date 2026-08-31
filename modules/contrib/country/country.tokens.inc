<?php

/**
 * @file
 * Provides Token integration for country.
 */

use Drupal\Core\Hook\Attribute\LegacyHook;
use Drupal\country\Hook\CountryTokensHooks;
use Drupal\Core\Render\BubbleableMetadata;

/**
 * Implements hook_token_info().
 */
#[LegacyHook]
function country_token_info() {
  return \Drupal::service(CountryTokensHooks::class)->tokenInfo();
}

/**
 * Implements hook_tokens().
 */
#[LegacyHook]
function country_tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
  return \Drupal::service(CountryTokensHooks::class)->tokens($type, $tokens, $data, $options, $bubbleable_metadata);
}
