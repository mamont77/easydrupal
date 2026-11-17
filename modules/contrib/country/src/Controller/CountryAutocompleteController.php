<?php

namespace Drupal\country\Controller;

use Drupal\Component\Utility\Html;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\country\CountryFieldManager;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Returns autocomplete responses for countries.
 */
class CountryAutocompleteController implements ContainerInjectionInterface {

  /**
   * The country field manager.
   *
   * @var \Drupal\country\CountryFieldManager
   */
  protected $countryFieldManager;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The entity field manager.
   *
   * @var \Drupal\Core\Entity\EntityFieldManagerInterface
   */
  protected $entityFieldManager;

  /**
   * Constructs a new CountryAutocompleteController.
   *
   * @param \Drupal\country\CountryFieldManager $country_field_manager
   *   The country field manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Entity\EntityFieldManagerInterface $entity_field_manager
   *   The entity field manager.
   */
  public function __construct(CountryFieldManager $country_field_manager, EntityTypeManagerInterface $entity_type_manager, EntityFieldManagerInterface $entity_field_manager) {
    $this->countryFieldManager = $country_field_manager;
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('country.field.manager'),
      $container->get('entity_type.manager'),
      $container->get('entity_field.manager')
    );
  }

  /**
   * Checks access to the country autocomplete route.
   *
   * Access is granted when the route points to an existing country field and
   * the user may view or edit that field in at least one matching bundle.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user requesting the autocomplete.
   * @param string $entity_type
   *   The type of entity that owns the field.
   * @param string $bundle
   *   The name of the bundle that owns the field, or 'global'.
   * @param string $field_name
   *   The name of the field.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, $entity_type, $bundle, $field_name): AccessResultInterface {
    $result = AccessResult::neutral()->addCacheTags(['entity_field_info']);

    $field_map = $this->entityFieldManager->getFieldMapByFieldType('country');
    $bundles = $field_map[$entity_type][$field_name]['bundles'] ?? [];
    if ($bundle !== 'global') {
      $bundles = array_intersect($bundles, [$bundle]);
    }
    if (!$bundles) {
      return $result;
    }

    $access_control_handler = $this->entityTypeManager->getAccessControlHandler($entity_type);
    foreach ($bundles as $field_bundle) {
      $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type, $field_bundle)[$field_name];
      $result = $result
        ->orIf($access_control_handler->fieldAccess('edit', $field_definition, $account, NULL, TRUE))
        ->orIf($access_control_handler->fieldAccess('view', $field_definition, $account, NULL, TRUE));
    }
    return $result;
  }

  /**
   * Returns response for the country name autocompletion.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The current request object containing the search string.
   * @param string $entity_type
   *   The type of entity that owns the field.
   * @param string $bundle
   *   The name of the bundle that owns the field.
   * @param string $field_name
   *   The name of the field.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   A JSON response containing the autocomplete suggestions for countries.
   */
  public function autocomplete(Request $request, $entity_type, $bundle, $field_name) {
    $matches = [];
    $string = $request->query->get('q');
    if ($string) {
      // Check if the bundle is global - in that case we need all countries.
      if ($bundle == 'global') {
        $countries = $this->countryFieldManager->getList();
      }
      else {
        /** @var \Drupal\Core\Field\FieldDefinitionInterface $field_definition */
        $field_definition = $this->entityFieldManager->getFieldDefinitions($entity_type, $bundle)[$field_name];
        $countries = $this->countryFieldManager->getSelectableCountries($field_definition);
      }
      foreach ($countries as $country) {
        if (strpos(mb_strtolower($country), mb_strtolower($string)) !== FALSE) {
          // Escape the label to prevent unsafe markup.
          $label = Html::escape((string) $country);
          $matches[] = ['value' => $label, 'label' => $label];
        }
      }
    }
    return new JsonResponse($matches);
  }

}
