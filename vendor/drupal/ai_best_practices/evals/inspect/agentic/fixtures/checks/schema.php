<?php

/**
 * @file
 * Grade: acme_settings.settings validates against a schema, values untouched.
 */

use Drupal\Core\Config\Schema\SchemaCheckTrait;

$checker = new class {
  use SchemaCheckTrait;

  public function run($typed, string $name, array $data) {
    return $this->checkConfigSchema($typed, $name, $data);
  }

};
$raw = \Drupal::configFactory()->getEditable('acme_settings.settings')->getRawData();
$result = $checker->run(\Drupal::service('config.typed'), 'acme_settings.settings', $raw);
$values_ok = ($raw['api_url'] ?? NULL) === 'https://api.example.com'
  && ($raw['retries'] ?? NULL) === 3
  && ($raw['enabled'] ?? NULL) === TRUE;
$detail = $result === FALSE ? 'no schema for acme_settings.settings'
  : ($result === TRUE ? 'schema valid' : 'schema errors: ' . json_encode($result));
if (!$values_ok) {
  $detail .= '; stored values changed: ' . json_encode($raw);
}
echo json_encode(['pass' => $result === TRUE && $values_ok, 'detail' => $detail]);
