<?php

namespace Drupal\twig_field_value_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\StringFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Denies access to every third field item.
 */
#[FieldFormatter(
  id: 'string_hidden_third_child',
  label: new TranslatableMarkup('Without third child'),
  description: new TranslatableMarkup('Denies access to every third field item. Only displays every first and second item as plain text.'),
  field_types: ['string'],
)]
class StringHiddenThirdChildFormatter extends StringFormatter {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);

    foreach (array_keys($elements) as $delta) {
      if ($this->isThirdChild($delta)) {
        $elements[$delta]['#access'] = FALSE;
      }
    }

    return $elements;
  }

  /**
   * Determine if this is the third item.
   *
   * @param int $key
   *   Key of indexed array.
   *
   * @return bool
   *   True for every third child.
   */
  private function isThirdChild(int $key): bool {
    return $key % 3 == 2;
  }

}
