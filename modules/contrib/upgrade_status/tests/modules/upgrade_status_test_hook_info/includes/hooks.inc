<?php

/**
 * @file
 * This file has hooks and other functions, and is not in a hook group.
 */

use Drupal\Core\Hook\Attribute\LegacyHook;

/**
 * Implements hook_cron().
 */
function upgrade_status_test_hook_info_cron(): void {
}

/**
 * Implements hook_form_FORM_ID_alter().
 */
function upgrade_status_test_hook_info_form_user_login_form_alter(array &$form): void {
}

/**
 * Implements hook_preprocess_HOOK().
 */
function upgrade_status_test_hook_info_preprocess_node(array &$variables): void {
}

/**
 * Implements hook_ENTITY_TYPE_insert().
 */
function upgrade_status_test_hook_info_user_insert(): void {
}

/**
 * Implements hook_cache_flush().
 *
 * The object-oriented twin of this function runs on Drupal 11.1 and later.
 */
#[LegacyHook]
function upgrade_status_test_hook_info_cache_flush(): void {
}

/**
 * Implements hook_install().
 *
 * Core calls this hook directly.
 */
function upgrade_status_test_hook_info_install(): void {
}

/**
 * This name ends like hook_ENTITY_TYPE_update(), but row is not an entity type.
 */
function upgrade_status_test_hook_info_row_update(): void {
}

/**
 * This function has a name that does not start with a module name.
 */
function _hook_info_form_alter(): void {
}
