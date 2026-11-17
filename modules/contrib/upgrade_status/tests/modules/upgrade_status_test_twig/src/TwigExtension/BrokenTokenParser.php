<?php

namespace Drupal\upgrade_status_test_twig\TwigExtension;

use Twig\Node\Node;
use Twig\Token;
use Twig\TokenParser\AbstractTokenParser;

/**
 * Parses the upgrade_status_test_broken tag by throwing an exception.
 */
class BrokenTokenParser extends AbstractTokenParser {

  /**
   * {@inheritdoc}
   */
  public function parse(Token $token): Node {
    throw new \LogicException('The upgrade_status_test_broken tag is broken.');
  }

  /**
   * {@inheritdoc}
   */
  public function getTag(): string {
    return 'upgrade_status_test_broken';
  }

}
