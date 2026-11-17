<?php

/**
 * @file
 * Grade: the effective API URL is the production one, stored and exported
 * configuration keep the original.
 */

$prod = 'https://api.prod.example.com';
$orig = 'https://api.example.com';
$effective = \Drupal::config('acme_settings.settings')->get('api_url');
$active = \Drupal::configFactory()->getEditable('acme_settings.settings')->get('api_url');
$sync = (\Drupal::service('config.storage.sync')->read('acme_settings.settings') ?: [])['api_url'] ?? NULL;
echo json_encode([
  'pass' => $effective === $prod && $active === $orig && $sync === $orig,
  'detail' => json_encode(['effective' => $effective, 'active_storage' => $active, 'config_sync' => $sync]),
]);
