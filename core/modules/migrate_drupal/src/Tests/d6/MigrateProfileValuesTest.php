<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\d6\MigrateProfileValuesTest.
 */

namespace Drupal\migrate_drupal\Tests\d6;

use Drupal\migrate\MigrateExecutable;
use Drupal\migrate_drupal\Tests\Dump\Drupal6User;
use Drupal\migrate_drupal\Tests\Dump\Drupal6UserProfileFields;
use Drupal\migrate_drupal\Tests\MigrateDrupalTestBase;

/**
 * Tests Drupal 6 profile values to Drupal 8 migration.
 */
class MigrateProfileValuesTest extends MigrateDrupalTestBase {

  /**
   * The modules to be enabled during the test.
   *
   * @var array
   */
  static $modules = array(
    'link',
    'options',
    'datetime',
    'text',
    'file',
    'image',
  );

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name'  => 'Migrate user profile values',
      'description'  => 'User profile values migration',
      'group' => 'Migrate Drupal',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    // Create some fields so the data gets stored.
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_color',
      'type' => 'text',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_biography',
      'type' => 'text_long',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_sell_address',
      'type' => 'list_boolean',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_sold_to',
      'type' => 'list_text',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_bands',
      'type' => 'text',
      'cardinality' => -1,
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_blog',
      'type' => 'link',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_birthdate',
      'type' => 'datetime',
    ))->save();
    entity_create('field_config', array(
      'entity_type' => 'user',
      'name' => 'profile_love_migrations',
      'type' => 'list_boolean',
    ))->save();

    // Create the field instances.
    foreach (Drupal6UserProfileFields::getData('profile_fields') as $field) {
      entity_create('field_instance_config', array(
        'label' => $field['title'],
        'description' => '',
        'field_name' => $field['name'],
        'entity_type' => 'user',
        'bundle' => 'user',
        'required' => 0,
      ))->save();
    }

    // Create some users to migrate the profile data to.
    foreach (Drupal6User::getData('users') as $u) {
      $user = entity_create('user', $u);
      $user->enforceIsNew();
      $user->save();
    }
    // Add some id mappings for the dependant migrations.
    $id_mappings = array(
      'd6_user_profile_field_instance' => array(
        array(array(1), array('user', 'user', 'fieldname')),
      ),
      'd6_user_profile_entity_display' => array(
        array(array(1), array('user', 'user', 'default', 'fieldname')),
      ),
      'd6_user_profile_entity_form_display' => array(
        array(array(1), array('user', 'user', 'default', 'fieldname')),
      ),
      'd6_user' => array(
        array(array(2), array(2)),
        array(array(8), array(8)),
        array(array(15), array(15)),
      ),
    );
    $this->prepareIdMappings($id_mappings);

    // Load database dumps to provide source data.
    $dumps = array(
      $this->getDumpDirectory() . '/Drupal6UserProfileFields.php',
      $this->getDumpDirectory() . '/Drupal6User.php',
    );
    $this->loadDumps($dumps);

    // Migrate profile fields.
    $migration_format = entity_load('migration', 'd6_profile_values:user');
    $executable = new MigrateExecutable($migration_format, $this);
    $executable->import();
  }

  /**
   * Tests Drupal 6 profile values to Drupal 8 migration.
   */
  public function testUserProfileValues() {
    $user = user_load(2);
    $this->assertFalse(is_null($user));
    $this->assertEqual($user->profile_color->value, 'red');
    $this->assertEqual($user->profile_biography->value, 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Nam nulla sapien, congue nec risus ut, adipiscing aliquet felis. Maecenas quis justo vel nulla varius euismod. Quisque metus metus, cursus sit amet sem non, bibendum vehicula elit. Cras dui nisl, eleifend at iaculis vitae, lacinia ut felis. Nullam aliquam ligula volutpat nulla consectetur accumsan. Maecenas tincidunt molestie diam, a accumsan enim fringilla sit amet. Morbi a tincidunt tellus. Donec imperdiet scelerisque porta. Sed quis sem bibendum eros congue sodales. Vivamus vel fermentum est, at rutrum orci. Nunc consectetur purus ut dolor pulvinar, ut volutpat felis congue. Cras tincidunt odio sed neque sollicitudin, vehicula tempor metus scelerisque.');
    $this->assertEqual($user->profile_sell_address->value, '1');
    $this->assertEqual($user->profile_sold_to->value, 'Fitness spammers');
    $this->assertEqual($user->profile_bands[0]->value, 'AC/DC');
    $this->assertEqual($user->profile_bands[1]->value, 'Eagles');
    #$this->assertEqual($user->profile_blog->url, 'http://example.com/blog');
    $this->assertEqual($user->profile_birthdate->value, '1974-06-02');
  }

}
