<?php

namespace Drupal\schema_article\Plugin\metatag\Tag;

use Drupal\schema_metatag\Plugin\metatag\Tag\SchemaTrueFalseBase;

/**
 * Provides a plugin for the 'schema_article_is_accessible_for_free' meta tag.
 *
 * - 'id' should be a globally unique id.
 * - 'name' should match the Schema.org element name.
 * - 'group' should match the id of the group that defines the Schema.org type.
 *
 * @MetatagTag(
 *   id = "schema_article_is_accessible_for_free",
 *   label = @Translation("isAccessibleForFree"),
 *   description = @Translation("Is this article accessible for free? If False, also define hasPart and list the classes of the parts of the page that are not free."),
 *   name = "isAccessibleForFree",
 *   group = "schema_article",
 *   weight = 4,
 *   type = "string",
 *   secure = FALSE,
 *   multiple = FALSE
 * )
 */
class SchemaArticleIsAccessibleForFree extends SchemaTrueFalseBase {

}
