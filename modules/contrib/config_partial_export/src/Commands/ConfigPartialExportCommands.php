<?php

namespace Drupal\config_partial_export\Commands;

use Drupal\Core\Config\FileStorage;
use Drupal\Core\Config\ConfigManagerInterface;
use Drupal\Core\Config\StorageComparer;
use Drupal\Core\Config\StorageException;
use Drupal\Core\Config\StorageInterface;
use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;

/**
 * Defines Drush commands for the partial config export.
 */
class ConfigPartialExportCommands extends DrushCommands {

  /**
   * Config manager service.
   *
   * @var \Drupal\Core\Config\ConfigManagerInterface
   */
  protected ConfigManagerInterface $configManager;

  /**
   * Config storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected StorageInterface $configStorage;

  /**
   * File storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected StorageInterface $configStorageSync;

  /**
   * @param ConfigManagerInterface $configManager
   *   Drupal config manager.
   * @param StorageInterface $configStorage
   *   Config storage.
   * @param StorageInterface $configStorageSync
   *   File storage.
   */
  public function __construct(
    ConfigManagerInterface $configManager,
    StorageInterface $configStorage,
    StorageInterface $configStorageSync
  ) {
    parent::__construct();
    $this->configManager = $configManager;
    $this->configStorage = $configStorage;
    $this->configStorageSync = $configStorageSync;
  }

  /**
   * Command description here.
   *
   * @param $config
   *   Configuration keys, comma separated.
   *
   * @param array $options
   *   An associative array of options whose values come from cli, aliases, config, etc.
   * @option changelist
   *   Shows the list of changed active config.
   *
   * @usage drush config-partial-export webform.webform.*
   *   Export all webform config.
   * @usage drush cpex webform.webform.*
   *   Export all webform config.
   *
   * @command config-partial-export
   * @aliases cpex
   */
  public function configPartialExport(string $config = '', array $options = ['changelist' => '', 'show-destinations' => ['description']]) {
    if (isset($options['changelist']) && $options['changelist']) {
      $changes = $this->getChangedConfigList();
      if (!empty($changes)) {
        $this->output()->writeln(dt("Your configuration has changed:"));
        foreach ($changes as $key => $values) {
          $this->output()->writeln($key);
          foreach ($values as $value) {
            $this->output()->writeln('- ' . $value);
          }
        }
        return;
      }
      else {
        return $this->output()->writeln(dt('No changed config.'));
      }
    }
    $destination_dir = Settings::get('config_sync_directory');
    $destination_storage = new FileStorage($destination_dir);

    $config_keys = explode(",", $config);
    foreach ($config_keys as $config_key) {
      // Look for a wildcard character.
      if (str_contains($config_key, '*')) {
        $wildcard_keys = $this->getMatchingConfigs($config_key, $this->configStorage);
        foreach ($wildcard_keys as $wildcard_key) {
          $this->writeConfig($wildcard_key, $this->configStorage, $destination_storage, $destination_dir);
        }
      }
      else {
        $this->writeConfig($config_key, $this->configStorage, $destination_storage, $destination_dir);
      }
    }

    return TRUE;
  }



  /**
   * Writes a YAML configuration file to the specified directory.
   *
   * @param string $key
   *   Configuration key.
   * @param \Drupal\Core\Config\StorageInterface $source_storage
   *   The source storage.
   * @param \Drupal\Core\Config\StorageInterface $destination_storage
   *   The source storage.
   *
   * @return bool
   *   Whether or not the configuration was moved from source to destination.
   */
  function writeConfig(string $key, StorageInterface $source_storage, StorageInterface $destination_storage, $destination_dir): bool {
    $data = $source_storage->read($key);
    // New config.
    if (empty($data)) {
      $data = $this->configManager->getConfigFactory()->get($key)->getRawData();
    }
    try {
      $result = $destination_storage->write($key, $data);
      $this->logger()->info(dt('Writing !name to !target.', [
        '!name' => $key,
        '!target' => $destination_dir,
      ]));
    }
    catch (StorageException $exception) {
      $this->logger->error(dt('Some error occurred during config creation. Message %message', [
        '%message' => $exception->getMessage(),
      ]));
      $result = FALSE;
    }
    return $result;
  }

  /**
   * Checking if a configuration matches a wildcard.
   *
   * @param string $input
   *   The string that contains the wildcard.
   * @param \Drupal\Core\Config\StorageInterface $storage
   *   The source storage.
   *
   * @return array
   *   The list of keys.
   */
  protected function getMatchingConfigs(string $input, StorageInterface $storage): array {
    // Get the strings around the wildcard.
    $split = explode('*', $input);
    $matching_keys = [];

    // Load the possible keys that start with the first prefix.
    $possible_keys = $storage->listAll($split[0]);

    // Check each key if they match the strings.
    foreach ($possible_keys as $config_key) {
      $match = TRUE;
      $counter = strlen($split[0]);

      for ($i = 1; $i < count($split); $i++) {
        if (!empty($split[$i])) {
          // Check if the partial exists after the last check.
          $pos = strpos($config_key, $split[$i], $counter);
          // If no "match" was found for this partial, it should fail.
          if ($pos !== FALSE) {
            // Increment the counter by the position found and length of the match.
            $counter += ($pos + strlen($split[$i]));
          }
          else {
            $match = FALSE;
          }
        }
      }
      if ($match) {
        $matching_keys[] = $config_key;
      }
    }
    return $matching_keys;
  }

  /**
   * Gets the list of changed configurations.
   *
   * @return array
   *   List of changed config files.
   */
  protected function getChangedConfigList(): array {
    $storage_comparer = new StorageComparer($this->configStorageSync, $this->configStorage);
    $source_list = $this->configStorageSync->listAll();
    $change_list = $storage_comparer->createChangelist();
    if (empty($source_list) || !$change_list->hasChanges()) {
      $this->output()->writeln(dt('There are no configuration changes.'));
      return [];
    }
    $diff = $change_list->getChangelist();
    if (!empty($diff)) {
      foreach ($diff as $action => $config_names) {
        if (empty($config_names)) {
          unset($diff[$action]);
          continue;
        }
        sort($diff[$action]);
      }
    }

    return $diff;
  }

}
