<?php

namespace Drupal\upgrade_status_test_plugin_definitions\Plugin\Layout;

use Drupal\Core\Layout\LayoutDefault;

/**
 * A layout annotation without a label, but with a region label.
 *
 * @Layout(
 *   id = "upgrade_status_test_unlabeled_annotation",
 *   regions = {
 *     "main" = {
 *       "label" = @Translation("Main"),
 *     }
 *   }
 * )
 */
class AnnotationLayout extends LayoutDefault {

}
