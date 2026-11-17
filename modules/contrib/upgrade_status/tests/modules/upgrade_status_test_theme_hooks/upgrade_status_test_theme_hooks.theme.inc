<?php

/**
 * @file
 * Preprocess functions in an include file.
 */

/**
 * Prepares variables, but no theme hook sets this as its initial preprocess.
 */
function template_preprocess_in_include(array &$variables): void {
}

/**
 * Prepares variables for a theme hook in a class with an initial preprocess.
 */
function template_preprocess_in_class_registered(array &$variables): void {
}

/**
 * Prepares variables. A string with this name is used in another file.
 */
function template_preprocess_used_by_name(array &$variables): void {
}

/**
 * Returns the name of a preprocess function.
 */
function upgrade_status_test_theme_hooks_function_name(): string {
  return 'template_preprocess_used_by_name';
}
