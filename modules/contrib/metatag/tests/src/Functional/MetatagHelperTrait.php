<?php

namespace Drupal\Tests\metatag\Functional;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\Entity\Term;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\TermInterface;
use Drupal\taxonomy\VocabularyInterface;
use Drupal\user\Entity\User;
use Drupal\user\UserInterface;

/**
 * Misc helper functions for the automated tests.
 */
trait MetatagHelperTrait {

  /**
   * Log in as user 1.
   *
   * @return \Drupal\user\UserInterface
   *   The full user object for user 1, after logging in.
   */
  protected function loginUser1(): UserInterface {
    // Load user 1.
    /** @var \Drupal\user\Entity\User $account */
    $account = User::load(1);

    // Reset the password.
    $password = 'foo';
    $account->setPassword($password)->save();

    // Support old and new tests.
    $account->passRaw = $password;
    $account->pass_raw = $password;

    // Login.
    $this->drupalLogin($account);

    return $account;
  }

  /**
   * Create a content type and a node.
   *
   * @param string $title
   *   A title for the node that will be returned.
   * @param string $body
   *   The text to use as the body.
   *
   * @return \Drupal\node\NodeInterface
   *   A fully formatted node object.
   */
  private function createContentTypeNode($title = 'Title test', $body = 'Body test'): NodeInterface {
    $content_type = 'metatag_test';
    $args = [
      'type' => $content_type,
      'label' => 'Test content type',
    ];
    $this->createContentType($args);

    $args = [
      'body' => [
        [
          'value' => $body,
          'format' => filter_default_format(),
        ],
      ],
      'title' => $title,
      'type' => $content_type,
    ];

    return $this->createNode($args);
  }

  /**
   * Create a vocabulary.
   *
   * @param array $values
   *   Items passed to the vocabulary. If the 'vid' item is not present it will
   *   be automatically generated. If the 'name' item is not present the 'vid'
   *   will be used.
   *
   * @return \Drupal\taxonomy\VocabularyInterface
   *   A fully formatted vocabulary object.
   */
  private function createVocabulary(array $values = []): VocabularyInterface {
    // Find a non-existent random type name.
    if (!isset($values['vid'])) {
      do {
        $id = strtolower($this->randomMachineName(8));
      } while (Vocabulary::load($id));
    }
    else {
      $id = $values['vid'];
    }
    $values += [
      'vid' => $id,
      'name' => $id,
    ];
    $vocab = Vocabulary::create($values);
    $status = $vocab->save();

    $this->assertSame($status, SAVED_NEW, (new FormattableMarkup('Created vocabulary %type.', ['%type' => $vocab->id()]))->__toString());

    return $vocab;
  }

  /**
   * Create a taxonomy term.
   *
   * @param array $values
   *   Items passed to the term. Requires the 'vid' element.
   *
   * @return \Drupal\taxonomy\Entity\Term
   *   A fully formatted term object.
   */
  private function createTerm(array $values = []): TermInterface {
    // Populate defaults array.
    $values += [
      'description' => [
        [
          'value' => $this->randomMachineName(32),
          'format' => filter_default_format(),
        ],
      ],
      'name' => $this->randomMachineName(8),
    ];
    $term = Term::create($values);
    $status = $term->save();

    $this->assertSame($status, SAVED_NEW, (new FormattableMarkup('Created term %name.', ['%name' => $term->label()]))->__toString());

    return $term;
  }

}
