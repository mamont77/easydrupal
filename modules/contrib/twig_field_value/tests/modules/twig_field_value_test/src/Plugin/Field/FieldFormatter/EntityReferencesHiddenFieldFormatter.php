<?php

namespace Drupal\twig_field_value_test\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Field\Plugin\Field\FieldFormatter\EntityReferenceLabelFormatter;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Denies access to the entire entity reference field.
 */
#[FieldFormatter(
  id: 'entity_reference_hidden_field',
  label: new TranslatableMarkup('Hidden field'),
  description: new TranslatableMarkup('Denies access to this field.'),
  field_types: ['entity_reference'],
)]
class EntityReferencesHiddenFieldFormatter extends EntityReferenceLabelFormatter {

  /**
   * {@inheritdoc}
   */
  public function view(FieldItemListInterface $items, $langcode = NULL) {
    $elements = parent::view($items, $langcode);
    $elements['#access'] = FALSE;
    return $elements;
  }

}
