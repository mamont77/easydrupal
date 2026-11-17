<?php

namespace Drupal\upgrade_status_test_twig\TwigExtension;

use Twig\Extension\AbstractExtension;

/**
 * Provides a tag whose parser throws an exception, like a broken extension.
 */
class BrokenTag extends AbstractExtension {

  /**
   * {@inheritdoc}
   */
  public function getTokenParsers(): array {
    return [new BrokenTokenParser()];
  }

}
