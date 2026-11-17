<?php

namespace Drupal\upgrade_status_test_item_attributes\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\FieldItemListInterface;
use Drupal\image\Plugin\Field\FieldFormatter\ImageFormatter;

/**
 * An image formatter that sets #item_attributes.
 */
class ItemAttributesFormatter extends ImageFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($elements as &$element) {
      // Code that used '#item_attributes' now sets '#attributes'.
      $element['#attributes']['class'][] = 'test';
      $element['#item_attributes'] = ($element['#item_attributes'] ?? []) + ['loading' => 'lazy'];
      $element["#item_attributes"]['data-test'] = 'test';
    }
    return $elements;
  }

}
