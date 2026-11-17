<?php

/**
 * @file
 * A file in a tests directory that is not in a module.
 */

/**
 * Has the name of a preprocess function, but core never calls it.
 */
function template_preprocess_not_in_a_module(array &$variables): void {
}
