<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\d6\MigrateContactCategoryTest.
 */

namespace Drupal\migrate_drupal\Tests\d6;

use Drupal\contact\Entity\ContactForm;
use Drupal\migrate\MigrateExecutable;
use Drupal\migrate\MigrateMessage;
use Drupal\migrate_drupal\Tests\d6\MigrateDrupal6TestBase;

/**
 * Migrate contact categories to contact.form.*.yml.
 *
 * @group migrate_drupal
 */
class MigrateContactCategoryTest extends MigrateDrupal6TestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('contact');

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $migration = entity_load('migration', 'd6_contact_category');
    $dumps = array(
      $this->getDumpDirectory() . '/Contact.php',
    );
    $this->prepare($migration, $dumps);
    $executable = new MigrateExecutable($migration, new MigrateMessage());
    $executable->import();
  }

  /**
   * The Drupal 6 contact categories to Drupal 8 migration.
   */
  public function testContactCategory() {
    /** @var \Drupal\contact\Entity\ContactForm $contact_form */
    $contact_form = ContactForm::load('website_feedback');
    $this->assertIdentical($contact_form->label(), 'Website feedback');
    $this->assertIdentical($contact_form->getRecipients(), array('admin@example.com'));
    $this->assertIdentical($contact_form->getReply(), '');
    $this->assertIdentical($contact_form->getWeight(), 0);

    $contact_form = ContactForm::load('some_other_category');
    $this->assertIdentical($contact_form->label(), 'Some other category');
    $this->assertIdentical($contact_form->getRecipients(), array('test@example.com'));
    $this->assertIdentical($contact_form->getReply(), 'Thanks for contacting us, we will reply ASAP!');
    $this->assertIdentical($contact_form->getWeight(), 1);

    $contact_form = ContactForm::load('a_category_much_longer_than_thir');
    $this->assertIdentical($contact_form->label(), 'A category much longer than thirty two characters');
    $this->assertIdentical($contact_form->getRecipients(), array('fortyninechars@example.com'));
    $this->assertIdentical($contact_form->getReply(), '');
    $this->assertIdentical($contact_form->getWeight(), 2);
  }

}
