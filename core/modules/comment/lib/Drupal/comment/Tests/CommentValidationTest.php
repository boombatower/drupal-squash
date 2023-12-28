<?php

/**
 * @file
 * Contains \Drupal\comment\Tests\CommentValidationTest.
 */

namespace Drupal\comment\Tests;

use Drupal\comment\CommentInterface;
use Drupal\system\Tests\Entity\EntityUnitTestBase;

/**
 * Tests comment validation constraints.
 */
class CommentValidationTest extends EntityUnitTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('comment', 'node');

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Comment Validation',
      'description' => 'Tests the comment validation constraints.',
      'group' => 'Comment',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->installSchema('node', array('node', 'node_field_data', 'node_field_revision', 'node_revision'));
  }

  /**
   * Tests the comment validation constraints.
   */
  public function testValidation() {
    // Add comment field to content.
    $this->entityManager->getStorageController('field_config')->create(array(
      'entity_type' => 'node',
      'name' => 'comment',
      'type' => 'comment',
    ))->save();
    // Add comment field instance to page content.
    $this->entityManager->getStorageController('field_instance_config')->create(array(
      'field_name' => 'comment',
      'entity_type' => 'node',
      'bundle' => 'page',
      'label' => 'Comment settings',
    ))->save();

    $node = $this->entityManager->getStorageController('node')->create(array(
      'type' => 'page',
      'title' => 'test',
    ));
    $node->save();

    $comment = $this->entityManager->getStorageController('comment')->create(array(
      'entity_id' => $node->id(),
      'entity_type' => 'node',
      'field_name' => 'comment',
      'comment_body' => $this->randomName(),
    ));

    $violations = $comment->validate();
    $this->assertEqual(count($violations), 0, 'No violations when validating a default comment.');

    $comment->set('subject', $this->randomString(65));
    $this->assertLengthViolation($comment, 'subject', 64);

    // Make the subject valid.
    $comment->set('subject', $this->randomString());
    $comment->set('name', $this->randomString(61));
    $this->assertLengthViolation($comment, 'name', 60);

    // Validate a name collision between an anonymous comment author name and an
    // existing user account name.
    $user = entity_create('user', array('name' => 'test'));
    $user->save();
    $comment->set('name', 'test');
    $violations = $comment->validate();
    $this->assertEqual(count($violations), 1, "Violation found on author name collision");
    $this->assertEqual($violations[0]->getPropertyPath(), "name");
    $this->assertEqual($violations[0]->getMessage(), t('%name belongs to a registered user.', array('%name' => 'test')));

    // Make the name valid.
    $comment->set('name', 'valid unused name');
    $comment->set('mail', 'invalid');
    $violations = $comment->validate();
    $this->assertEqual(count($violations), 1, 'Violation found when email is invalid');
    $this->assertEqual($violations[0]->getPropertyPath(), 'mail.0.value');
    $this->assertEqual($violations[0]->getMessage(), t('This value is not a valid email address.'));

    $comment->set('mail', NULL);
    $comment->set('homepage', 'http://example.com/' . $this->randomName(237));
    $this->assertLengthViolation($comment, 'homepage', 255);

    $comment->set('homepage', 'invalid');
    $violations = $comment->validate();
    $this->assertEqual(count($violations), 1, 'Violation found when homepage is invalid');
    $this->assertEqual($violations[0]->getPropertyPath(), 'homepage.0.value');

    // @todo This message should be improved in https://drupal.org/node/2012690
    $this->assertEqual($violations[0]->getMessage(), t('This value should be of the correct primitive type.'));

    $comment->set('homepage', NULL);
    $comment->set('hostname', $this->randomString(129));
    $this->assertLengthViolation($comment, 'hostname', 128);

    $comment->set('hostname', NULL);
    $comment->set('thread', $this->randomString(256));
    $this->assertLengthViolation($comment, 'thread', 255);
  }

  /**
   * Verifies that a length violation exists for the given field.
   *
   * @param \Drupal\comment\CommentInterface $comment
   *   The comment object to validate.
   * @param string $field_name
   *   The field that violates the maximum length.
   * @param int $length
   *   Number of characters that was exceeded.
   */
  protected function assertLengthViolation(CommentInterface $comment, $field_name, $length) {
    $violations = $comment->validate();
    $this->assertEqual(count($violations), 1, "Violation found when $field_name is too long.");
    $this->assertEqual($violations[0]->getPropertyPath(), "$field_name.0.value");
    $field_label = $comment->get($field_name)->getFieldDefinition()->getLabel();
    $this->assertEqual($violations[0]->getMessage(), t('%name: may not be longer than @max characters.', array('%name' => $field_label, '@max' => $length)));
  }

}
