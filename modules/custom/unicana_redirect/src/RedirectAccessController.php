<?php

namespace Drupal\unicana_redirect;

use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines an access controller for the redirect entity.
 *
 * We set this class to be the access controller in Redirect's entity annotation.
 *
 * @see \Drupal\unicana_redirect\Entity\Redirect
 *
 * @ingroup unicana_redirect
 */
class RedirectAccessController extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    // The $opereration parameter tells you what sort of operation access is
    // being checked for.
    if ($operation == 'view') {
      return TRUE;
    }
    // Other than the view operation, we're going to be insanely lax about
    // access. Don't try this at home!
    return parent::checkAccess($entity, $operation, $account);
  }

}
