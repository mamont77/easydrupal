<?php

/**
 * @file
 * Grade: a cron run records a fresh timestamp in state, and none in config.
 */

$recent = fn($v) => is_numeric($v) && abs(time() - (int) $v) < 900;
$before = \Drupal::keyValue('state')->getAll();
\Drupal::service('cron')->run();
$after = \Drupal::keyValue('state')->getAll();

$state_hits = [];
foreach ($after as $key => $value) {
  $changed = !array_key_exists($key, $before) || $before[$key] !== $value;
  // Only the module's own key: core modules (system, update) also record cron
  // timestamps in state, and those must not pass the untouched site.
  if ($changed && str_contains($key, 'acme') && $recent($value)) {
    $state_hits[] = $key;
  }
}
$config_hits = [];
foreach (\Drupal::configFactory()->listAll('acme_cron') as $name) {
  $data = \Drupal::configFactory()->getEditable($name)->getRawData();
  array_walk_recursive($data, function ($v, $k) use (&$config_hits, $name, $recent) {
    if ($recent($v)) {
      $config_hits[] = "$name:$k";
    }
  });
}
echo json_encode([
  'pass' => $state_hits && !$config_hits,
  'detail' => 'state keys set by cron: ' . json_encode($state_hits) . '; timestamps in config: ' . json_encode($config_hits),
]);
