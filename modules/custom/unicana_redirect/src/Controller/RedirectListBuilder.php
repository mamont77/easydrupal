<?php

namespace Drupal\unicana_redirect\Controller;

use Drupal\Core\Config\Entity\ConfigEntityListBuilder;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of redirect entities.
 *
 * @package Drupal\unicana_redirect\Controller
 *
 * @ingroup unicana_redirect
 */
class RedirectListBuilder extends ConfigEntityListBuilder {

  /**
   * Builds the header row for the entity listing.
   *
   * @return array
   *   A render array structure of header strings.
   *
   * @see Drupal\Core\Entity\EntityListController::render()
   */
  public function buildHeader() {
    $header['label'] = $this->t('Redirect');
//    $header['machine_name'] = $this->t('Machine Name');
    $header['replacement_pattern'] = $this->t('Affiliate Extension');
    $header['type_of_the_pattern'] = $this->t('Type');
    return $header + parent::buildHeader();
  }

  /**
   * Builds a row for an entity in the entity listing.
   *
   * @param EntityInterface $entity
   *   The entity for which to build the row.
   *
   * @return array
   *   A render array of the table row for displaying the entity.
   *
   * @see Drupal\Core\Entity\EntityListController::render()
   */
  public function buildRow(EntityInterface $entity) {
    $row['label'] = $entity->label();
//    $row['machine_name'] = $entity->id();
    $row['replacement_pattern'] = $entity->replacement_pattern;
    $types_map = array(
      $this->t('Before'),
      $this->t('Inside'),
      $this->t('After'),
    );
    $row['type_of_the_pattern'] = $types_map[$entity->type_of_the_pattern];

    return $row + parent::buildRow($entity);
  }

  /**
   * Adds some descriptive text to our entity list.
   *
   * Typically, there's no need to override render(). You may wish to do so,
   * however, if you want to add markup before or after the table.
   *
   * @return array
   *   Renderable array.
   */
//  public function render() {
//    $build[] = parent::render();
//    return $build;
//  }

}
