<?php

namespace Drupal\twig_field_value_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\StringFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Denies access to the entire field.
 */
#[FieldFormatter(
  id: 'string_hidden_field',
  label: new TranslatableMarkup('Hidden field'),
  description: new TranslatableMarkup('Denies access to this field.'),
  field_types: ['string'],
)]
class StringHiddenFieldFormatter extends StringFormatter {

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {
    $elements = parent::view($items, $langcode);
    $elements['#access'] = FALSE;
    return $elements;
  }

}
