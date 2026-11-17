<?php // lint < 7

// phpcs:ignoreFile

/**
 * @file
 * This file has a syntax error on purpose. Many *.api.php files have errors,
 * because PHP never runs them. These must not affect finding deprecated hooks.
 */

/**
 * A deprecated hook before the syntax error.
 *
 * @deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. There is no replacement.
 * @see https://www.drupal.org/node/3489765
 */
function hook_broken_api_before(): void {
}

function { this is not valid PHP

/**
 * A deprecated hook after the syntax error.
 *
 * @deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. There is no replacement.
 * @see https://www.drupal.org/node/3489765
 */
function hook_broken_api_after(): void {
}
