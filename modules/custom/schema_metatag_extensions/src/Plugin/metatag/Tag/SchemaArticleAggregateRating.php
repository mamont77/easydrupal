<?php

namespace Drupal\schema_metatag_extensions\Plugin\metatag\Tag;

use Drupal\schema_metatag\Plugin\metatag\Tag\SchemaNameBase;

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
   * Basic info about votingapi.
   *
   * @var bool
   */
  protected $votingapiEnabled;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $moduleHandler = \Drupal::service('module_handler');
    if ($moduleHandler->moduleExists('votingapi')) {
      $this->votingapiEnabled = TRUE;
    }
  }

  /**
   * Generate a form element for this meta tag.
   */
  public function form(array $element = []) {
    $form = [];

    if ($this->votingapiEnabled) {
      $form = [
        '#type' => 'checkbox',
        '#title' => $this->label(),
        '#description' => $this->description(),
        '#default_value' => $this->value(),
      ];
    }

    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function output() {
    $element = parent::output();

    if (!empty($element['#attributes']['content'])) {
      // Load the current node.
      $node = \Drupal::routeMatch()->getParameter('node');

      // Get rating from votingapi.
      // Get rating from votingapi.
      $voting_service = \Drupal::service('plugin.manager.votingapi.resultfunction');
      $results = $voting_service->getResults('node', $node->id());

      if (!empty($results)) {
        $element['#attributes']['content'] = [
          "@type" => "AggregateRating",
          "ratingValue" => $results['vote']['vote_average'],
          "bestRating" => 5,
          "worstRating" => 1,
          "ratingCount" => $results['vote']['vote_count'],
        ];
      }
      else {
        return FALSE;
      }
    }

    return $element;
  }

}
