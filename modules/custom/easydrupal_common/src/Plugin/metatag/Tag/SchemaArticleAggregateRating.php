<?php

namespace Drupal\easydrupal_common\Plugin\metatag\Tag;

use \Drupal\schema_metatag\Plugin\metatag\Tag\SchemaNameBase;

/**
 * Provides a plugin for the 'schema.org AggregateRating' meta tag.
 *
 * - 'id' should be a globally unique id.
 * - 'name' should match the Schema.org element name.
 * - 'group' should match the id of the group that defines the Schema.org type.
 *
 * @MetatagTag(
 *   id = "schema_article_aggregate_rating",
 *   label = @Translation("AggregateRating"),
 *   description = @Translation("Attach to JSON the aggregate rating of the article provided by the Voting API module."),
 *   name = "aggregateRating",
 *   group = "schema_article",
 *   weight = 11,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class SchemaArticleAggregateRating extends SchemaNameBase {

  /**
   * Generate a form element for this meta tag.
   */
  public function form(array $element = []) {
    $form = [];

    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('votingapi')) {
      $form = [
        '#type' => 'checkbox',
        '#title' => $this->label(),
        '#description' => $this->description(),
        '#default_value' => $this->value(),
      ];
    }

    return $form;
  }

}
