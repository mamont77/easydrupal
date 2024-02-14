<?php
// phpcs:ignoreFile

/**
 * This file was generated via php core/scripts/generate-proxy-class.php 'Drupal\menu_trail_by_path\MenuTrailByPathActiveTrail' "modules/contrib/menu_trail_by_path/src".
 */

namespace Drupal\menu_trail_by_path\ProxyClass {

    /**
     * Provides a proxy class for \Drupal\menu_trail_by_path\MenuTrailByPathActiveTrail.
     *
     * @see \Drupal\Component\ProxyBuilder
     */
    class MenuTrailByPathActiveTrail implements \Drupal\Core\Menu\MenuActiveTrailInterface, \Drupal\Core\DestructableInterface, \Drupal\Core\Cache\CacheCollectorInterface
    {

        use \Drupal\Core\DependencyInjection\DependencySerializationTrait;

        /**
         * The id of the original proxied service.
         *
         * @var string
         */
        protected $drupalProxyOriginalServiceId;

        /**
         * The real proxied service, after it was lazy loaded.
         *
         * @var \Drupal\menu_trail_by_path\MenuTrailByPathActiveTrail
         */
        protected $service;

        /**
         * The service container.
         *
         * @var \Symfony\Component\DependencyInjection\ContainerInterface
         */
        protected $container;

        /**
         * Constructs a ProxyClass Drupal proxy object.
         *
         * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
         *   The container.
         * @param string $drupal_proxy_original_service_id
         *   The service ID of the original service.
         */
        public function __construct(\Symfony\Component\DependencyInjection\ContainerInterface $container, $drupal_proxy_original_service_id)
        {
            $this->container = $container;
            $this->drupalProxyOriginalServiceId = $drupal_proxy_original_service_id;
        }

        /**
         * Lazy loads the real service from the container.
         *
         * @return object
         *   Returns the constructed real service.
         */
        protected function lazyLoadItself()
        {
            if (!isset($this->service)) {
                $this->service = $this->container->get($this->drupalProxyOriginalServiceId);
            }

            return $this->service;
        }

        /**
         * {@inheritdoc}
         */
        public function getActiveTrailLink($menu_name)
        {
            return $this->lazyLoadItself()->getActiveTrailLink($menu_name);
        }

        /**
         * {@inheritdoc}
         */
        public function getActiveTrailIds($menu_name)
        {
            return $this->lazyLoadItself()->getActiveTrailIds($menu_name);
        }

        /**
         * {@inheritdoc}
         */
        public function getActiveLink($menu_name = NULL)
        {
            return $this->lazyLoadItself()->getActiveLink($menu_name);
        }

        /**
         * {@inheritdoc}
         */
        public function has($key)
        {
            return $this->lazyLoadItself()->has($key);
        }

        /**
         * {@inheritdoc}
         */
        public function get($key)
        {
            return $this->lazyLoadItself()->get($key);
        }

        /**
         * {@inheritdoc}
         */
        public function set($key, $value)
        {
            return $this->lazyLoadItself()->set($key, $value);
        }

        /**
         * {@inheritdoc}
         */
        public function delete($key)
        {
            return $this->lazyLoadItself()->delete($key);
        }

        /**
         * {@inheritdoc}
         */
        public function reset()
        {
            return $this->lazyLoadItself()->reset();
        }

        /**
         * {@inheritdoc}
         */
        public function clear()
        {
            return $this->lazyLoadItself()->clear();
        }

        /**
         * {@inheritdoc}
         */
        public function destruct()
        {
            return $this->lazyLoadItself()->destruct();
        }

    }

}
