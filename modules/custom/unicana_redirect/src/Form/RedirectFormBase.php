<?php

namespace Drupal\unicana_redirect\Form;

use Drupal\Core\Entity\EntityForm;
use Drupal\Core\Entity\Query\QueryFactory;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;

use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Class RedirectFormBase.
 *
 * @package Drupal\unicana_redirect\Form
 *
 * @ingroup unicana_redirect
 */
class RedirectFormBase extends EntityForm {

  /**
   * @var \Drupal\Core\Entity\Query\QueryFactory
   */
  protected $entityQueryFactory;

  /**
   * Construct the RedirectFormBase.
   *
   * @param \Drupal\Core\Entity\Query\QueryFactory $query_factory
   *   An entity query factory for the redirect entity type.
   */
  public function __construct(QueryFactory $query_factory) {
    $this->entityQueryFactory = $query_factory;
  }

  /**
   * Factory method for RedirectFormBase.
   */
  public static function create(ContainerInterface $container) {
    return new static($container->get('entity.query'));
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   *
   * Builds the entity add/edit form.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   *
   * @return array
   *   An associative array containing the redirect add/edit form.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Get anything we need from the base class.
    $form = parent::buildForm($form, $form_state);

    // Drupal provides the entity to us as a class variable. If this is an
    // existing entity, it will be populated with existing values as class
    // variables. If this is a new entity, it will be a new object with the
    // class of our entity. Drupal knows which class to call from the
    // annotation on our Redirect class.
    $redirect = $this->entity;

    // Build the form.
    $form['label'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Domain'),
      '#maxlength' => 255,
      '#default_value' => $redirect->label(),
      '#required' => TRUE,
      '#description' => $this->t('Must be first level of a domain. Without protocol (HTTP or SHTTP) and WWW. Examples: my-domain-name.com, my-domain-name.com.'),
    );
    $form['id'] = array(
      '#type' => 'machine_name',
      '#title' => $this->t('Machine name'),
      '#default_value' => $redirect->id(),
      '#machine_name' => array(
        'exists' => array($this, 'exists'),
        'replace_pattern' => '([^a-z0-9_]+)|(^custom$)',
        'error' => 'The machine-readable name must be unique, and can only contain lowercase letters, numbers, and underscores.',
      ),
      '#disabled' => !$redirect->isNew(),
    );
    $form['replacement_pattern'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Affiliate Extension'),
      '#maxlength' => 255,
      '#default_value' => $redirect->replacement_pattern,
      '#required' => TRUE,
      '#description' => $this->t('Replacement pattern. Example: http://example.com/?url= (before), affiliate-partner=234234231 (after).'),
    );
    $form['type_of_the_pattern'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Type of the pattern'),
      '#options' => array(
        $this->t('Before'),
        $this->t('Inside'),
        $this->t('After'),
      ),
      '#default_value' => $redirect->type_of_the_pattern,
      '#required' => TRUE,
    );

    // Return the form.
    return $form;
  }

  /**
   * Checks for an existing redirect.
   *
   * @param string|int $entity_id
   *   The entity ID.
   * @param array $element
   *   The form element.
   * @param FormStateInterface $form_state
   *   The form state.
   *
   * @return bool
   *   TRUE if this format already exists, FALSE otherwise.
   */
  public function exists($entity_id, array $element, FormStateInterface $form_state) {
    // Use the query factory to build a new redirect entity query.
    $query = $this->entityQueryFactory->get('redirect');

    // Query the entity ID to see if its in use.
    $result = $query->condition('id', $element['#field_prefix'] . $entity_id)
      ->execute();

    // We don't need to return the ID, only if it exists or not.
    return (bool) $result;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::actions().
   *
   * To set the submit button text, we need to override actions().
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   *
   * @return array
   *   An array of supported actions for the current entity form.
   */
  protected function actions(array $form, FormStateInterface $form_state) {
    // Get the basic actions from the base class.
    $actions = parent::actions($form, $form_state);

    // Change the submit button text.
    $actions['submit']['#value'] = $this->t('Save');

    // Return the result.
    return $actions;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::validate().
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function validate(array $form, FormStateInterface $form_state) {
    parent::validate($form, $form_state);

    // Add code here to validate your config entity's form elements.
    // Nothing to do here.
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::save().
   *
   * Saves the entity. This is called after submit() has built the entity from
   * the form values. Do not override submit() as save() is the preferred
   * method for entity form controllers.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   An associative array containing the current state of the form.
   */
  public function save(array $form, FormStateInterface $form_state) {
    // EntityForm provides us with the entity we're working on.
    $redirect = $this->getEntity();

    // Drupal already populated the form values in the entity object. Each
    // form field was saved as a public variable in the entity class. PHP
    // allows Drupal to do this even if the method is not defined ahead of
    // time.
    $status = $redirect->save();

    // Grab the URL of the new entity. We'll use it in the message.
    $url = $redirect->toUrl();

    // Create an edit link.
    $edit_link = Link::fromTextAndUrl($this->t('Edit'), $url)->toString();

    if ($status == SAVED_UPDATED) {
      // If we edited an existing entity...
      drupal_set_message($this->t('Redirect %label has been updated.', array('%label' => $redirect->label())));
      $this->logger('unicana_redirect')
        ->notice('Redirect %label has been updated.', [
          '%label' => $redirect->label(),
          'link' => $edit_link,
        ]);
    }
    else {
      // If we created a new entity...
      drupal_set_message($this->t('Redirect %label has been added.', array('%label' => $redirect->label())));
      $this->logger('unicana_redirect')
        ->notice('Redirect %label has been added.', [
          '%label' => $redirect->label(),
          'link' => $edit_link,
        ]);
    }
    drupal_set_message($this->t('Don\'t forget <a href=":clear_cache">clear the cache</a> for the changes to take effect.',
      [
        ':clear_cache' => Url::fromRoute('system.performance_settings')
          ->toString(),
      ]));

    // Redirect the user back to the listing route after the save operation.
    $form_state->setRedirect('entity.redirect.list');
  }

}
