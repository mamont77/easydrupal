<?php

/**
 * @file
 * A file in a tests directory that is not in a module.
 */

/**
 * Has the name of a hook implementation, but core never finds it.
 */
function upgrade_status_test_hook_order_test_module_implements_alter(array &$implementations, string $hook): void {
}
