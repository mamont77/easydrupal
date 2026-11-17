<?php

declare(strict_types=1);

namespace Drupal\country_test\Hook;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Session\AccountInterface;

/**
 * Hook implementations for country_test.
 */
class CountryTestHooks {

  /**
   * Implements hook_entity_field_access().
   *
   * Denies all access to field_country_hidden.
   */
  #[Hook('entity_field_access')]
  public function entityFieldAccess(string $operation, FieldDefinitionInterface $field_definition, AccountInterface $account, ?FieldItemListInterface $items = NULL): AccessResultInterface {
    return AccessResult::forbiddenIf($field_definition->getName() === 'field_country_hidden');
  }

}
