<?php

namespace Drupal\country\Hook;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * Hook implementations for country.
 */
class CountryTokensHooks {
  use StringTranslationTrait;

  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityFieldManagerInterface $entityFieldManager,
    private readonly ?object $tokenEntityMapper = NULL,
  ) {}

  /**
   * Implements hook_token_info().
   */
  #[Hook('token_info')]
  public function tokenInfo() {
    if ($this->tokenEntityMapper === NULL) {
      return;
    }
    $types = [];
    $tokens = [];
    foreach ($this->entityTypeManager->getDefinitions() as $entity_type_id => $entity_type) {
      if (!$entity_type->entityClassImplements(ContentEntityInterface::class)) {
        continue;
      }
      $token_type = $this->tokenEntityMapper->getTokenTypeForEntityType($entity_type_id);
      if (empty($token_type)) {
        continue;
      }
      // Build country name tokens for all country fields.
      $fields = $this->entityFieldManager->getFieldStorageDefinitions($entity_type_id);
      foreach ($fields as $field_name => $field) {
        if ($field->getType() != 'country') {
          continue;
        }
        $tokens[$token_type . '-' . $field_name]['country_original_name'] = [
          'name' => $this->t('The country name'),
          'description' => NULL,
          'module' => 'country',
        ];
      }
    }
    return [
      'types' => $types,
      'tokens' => $tokens,
    ];
  }

  /**
   * Implements hook_tokens().
   */
  #[Hook('tokens')]
  public static function tokens($type, $tokens, array $data, array $options, BubbleableMetadata $bubbleable_metadata) {
    $replacements = [];
    if (!empty($data['field_property'])) {
      foreach ($tokens as $token => $original) {
        $delta = 0;
        $parts = explode(':', $token);
        if (is_numeric($parts[0])) {
          if (count($parts) > 1) {
            $delta = $parts[0];
            $property_name = $parts[1];
          }
          else {
            continue;
          }
        }
        else {
          $property_name = $parts[0];
        }
        if ($property_name != 'country_original_name') {
          continue;
        }
        if (!isset($data[$data['field_name']][$delta])) {
          continue;
        }
        $field_item = $data[$data['field_name']][$delta];
        $country_name = "";
        if ($country_code = $field_item->value) {
          $field_definition = $field_item->getFieldDefinition();
          $countries = \Drupal::service('country.field.manager')->getSelectableCountries($field_definition);
          $country_name = $countries[$country_code];
        }
        $replacements[$original] = $country_name->render();
      }
    }
    return $replacements;
  }

}
