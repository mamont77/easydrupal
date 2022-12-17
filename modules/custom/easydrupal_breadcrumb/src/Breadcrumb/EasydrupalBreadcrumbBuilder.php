<?php

namespace Drupal\easydrupal_breadcrumb\Breadcrumb;

use Drupal\Core\Breadcrumb\Breadcrumb;
use Drupal\Core\Breadcrumb\BreadcrumbBuilderInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Link;

/**
 * EasydrupalBreadCrumbBuilder.
 */
class EasydrupalBreadCrumbBuilder implements BreadcrumbBuilderInterface {
  /**
   * {@inheritdoc}
   */
  public function applies(RouteMatchInterface $route_match) {
    $parameters = $route_match->getParameters()->all();
    // I need my breadcrumbs for a few node types ONLY,
    // so it should be applied on node page ONLY.
    if (isset($parameters['node']) && !empty($parameters['node'])) {
      return TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function build(RouteMatchInterface $route_match): Breadcrumb {
    $breadcrumb = new Breadcrumb();
    $breadcrumb->addLink(Link::createFromRoute('Home', '<front>'));

    $node = \Drupal::routeMatch()->getParameter('node');
    $node_type = $node->bundle();

    switch ($node_type) {
      // If node type is "project"
      // I want to add as parent of breadcrumb my summary projects view.
      case 'project':
        $breadcrumb->addLink(Link::createFromRoute('Projects', 'view.portfolio.page_1'));

        break;

      // If node type is "article".
      // I want to add as parent of breadcrumb my summary articles view.
      case 'article':
        $breadcrumb->addLink(Link::createFromRoute('Articles', 'view.articles.page_1'));

        break;
    }

    // Don't forget to add cache control by a route,
    // otherwise you will surprice,
    // all breadcrumb will be the same for all pages.
    $breadcrumb->addCacheContexts(['route']);

    return $breadcrumb;
  }

}
