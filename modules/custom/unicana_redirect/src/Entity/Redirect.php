<?php

namespace Drupal\unicana_redirect\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;

/**
 * Defines the redirect entity.
 *
 * The lines below, starting with '@ConfigEntityType,' are a plugin annotation.
 * These define the entity type to the entity type manager.
 *
 * The properties in the annotation are as follows:
 *  - id: The machine name of the entity type.
 *  - label: The human-readable label of the entity type. We pass this through
 *    the "@Translation" wrapper so that the multilingual system may
 *    translate it in the user interface.
 *  - handlers: An array of entity handler classes, keyed by handler type.
 *    - access: The class that is used for access checks.
 *    - list_builder: The class that provides listings of the entity.
 *    - form: An array of entity form classes keyed by their operation.
 *  - entity_keys: Specifies the class properties in which unique keys are
 *    stored for this entity type. Unique keys are properties which you know
 *    will be unique, and which the entity manager can use as unique in database
 *    queries.
 *  - links: entity URL definitions. These are mostly used for Field UI.
 *    Arbitrary keys can set here. For example, User sets cancel-form, while
 *    Node uses delete-form.
 *
 *
 * @ingroup unicana_redirect
 *
 * @ConfigEntityType(
 *   id = "redirect",
 *   label = @Translation("Redirect"),
 *   admin_permission = "administer redirects",
 *   handlers = {
 *     "access" = "Drupal\unicana_redirect\RedirectAccessController",
 *     "list_builder" = "Drupal\unicana_redirect\Controller\RedirectListBuilder",
 *     "form" = {
 *       "add" = "Drupal\unicana_redirect\Form\RedirectAddForm",
 *       "edit" = "Drupal\unicana_redirect\Form\RedirectEditForm",
 *       "delete" = "Drupal\unicana_redirect\Form\RedirectDeleteForm"
 *     }
 *   },
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   links = {
 *     "edit-form" = "/admin/config/content/unicana-redirect/manage/{redirect}",
 *     "delete-form" = "/admin/config/content/unicana-redirect/manage/{redirect}/delete"
 *   }
 * )
 */
class Redirect extends ConfigEntityBase {

  /**
   * The redirect ID.
   *
   * @var string
   */
  public $id;

  /**
   * The redirect UUID.
   *
   * @var string
   */
  public $uuid;

  /**
   * The redirect domain.
   *
   * @var string
   */
  public $domain;

  /**
   * The replacement_pattern.
   *
   * @var string
   */
  public $replacement_pattern;

  /**
   * The type_of_the_pattern.
   *
   * @var string
   */
  public $type_of_the_pattern;

}
