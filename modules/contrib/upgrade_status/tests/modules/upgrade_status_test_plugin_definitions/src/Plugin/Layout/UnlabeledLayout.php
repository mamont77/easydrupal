<?php

namespace Drupal\upgrade_status_test_plugin_definitions\Plugin\Layout;

use Drupal\Core\Layout\Attribute\Layout;
use Drupal\Core\Layout\LayoutDefault;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * A layout without a label, but with a region label.
 */
#[Layout(
  id: 'upgrade_status_test_unlabeled_attribute',
  regions: [
    'main' => [
      'label' => new TranslatableMarkup('Main'),
    ],
  ],
)]
class UnlabeledLayout extends LayoutDefault {

}
