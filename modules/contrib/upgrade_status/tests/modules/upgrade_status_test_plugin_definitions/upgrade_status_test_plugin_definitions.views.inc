<?php

/**
 * @file
 * Provides Views data for upgrade status module testing.
 */

/**
 * Implements hook_views_data().
 */
function upgrade_status_test_plugin_definitions_views_data(): array {
  $data['upgrade_status_test']['first'] = [
    'title' => t('First'),
    'field' => [
      'id' => 'standard',
      'access callback' => 'upgrade_status_test_plugin_definitions_access',
      'access arguments' => ['administer site configuration'],
    ],
  ];
  // The 'access callback' key in a comment is not reported.
  $data['upgrade_status_test']['second']['filter']['access callback'] = 'upgrade_status_test_plugin_definitions_access';
  $data['upgrade_status_test']['second']['help'] = 'access callback';
  $data['upgrade_status_test']['second']['title'] = $data['upgrade_status_test']['first']['field']['access callback'];
  return $data;
}
