<?php

/**
 * @file
 * Hooks defined by the Upgrade Status deprecated hook test module.
 */

/**
 * A deprecated hook.
 *
 * @deprecated in drupal:11.0.0 and is removed from drupal:13.0.0. Use
 *   hook_deprecated_hook_new() instead.
 *
 * @see https://www.drupal.org/node/3489765
 */
function hook_deprecated_hook_old(): void {
}

/**
 * A deprecated hook with a variable part in its name.
 *
 * @deprecated in drupal:11.1.0 and is removed from drupal:13.0.0. There is no replacement.
 * @see https://www.drupal.org/node/3489765
 */
function hook_deprecated_hook_TYPE_alter(): void {
}

/**
 * A hook that is not deprecated.
 */
function hook_deprecated_hook_new(): void {
}
