<?php

declare(strict_types=1);

namespace Drupal\country_block\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\smart_ip\SmartIpLocationInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Blocks users from specific countries based on IP address.
 *
 * This subscriber listens to kernel requests and denies access to users
 * from countries defined in the module's configuration.
 */
final readonly class CountryBlockSubscriber implements EventSubscriberInterface {

  /**
   * Constructs a new CountryBlockSubscriber.
   *
   * @param \Drupal\Core\Session\AccountInterface $currentUser
   *   The current user.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\smart_ip\SmartIpLocationInterface $locationService
   *   The smart ip location service.
   */
  public function __construct(
    private AccountInterface         $currentUser,
    private ConfigFactoryInterface   $configFactory,
    private SmartIpLocationInterface $locationService,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::REQUEST => ['checkCountryBlock'],
    ];
  }

  /**
   * Checks the user's country and blocks them if they are from a blocked
   * country.
   *
   * This method is called for every kernel request.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The dispatched event.
   *
   * @throws \Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException
   * @throws \Exception
   *   Thrown when the user's country is in the blocked list.
   */
  public function checkCountryBlock(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // Do not block users with permission to administer site configuration.
    if ($this->currentUser->hasPermission('administer site configuration')) {
      return;
    }

    $config = $this->configFactory->get('country_block.settings');
    $blockedCountries = $config->get('blocked_countries') ?? [];

    if (empty($blockedCountries)) {
      return;
    }

    $countryCode = $this->locationService->get('countryCode');

    if ($countryCode !== NULL && in_array($countryCode, $blockedCountries, TRUE)) {
      $message = $config->get('message') ?? 'Access from your country is not permitted.';
      throw new AccessDeniedHttpException($message);
    }
  }

}
