<?php

namespace Drupal\upgrade_status_test_plugin_definitions\Plugin\Layout;

use Drupal\Core\Layout\Attribute\Layout;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A layout with the label as the second argument.
 */
#[Layout('upgrade_status_test_labeled_attribute', new TranslatableMarkup('Labeled, (attribute)'))]
class LabeledLayout extends LayoutDefault {

}
