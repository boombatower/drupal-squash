<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\source\d6\ContactCategoryTest.
 */

namespace Drupal\migrate_drupal\Tests\source\d6;

use Drupal\migrate\Tests\MigrateSqlSourceTestCase;

/**
 * Tests the Drupal 6 contact category source.
 *
 * @group migrate_drupal
 * @group Drupal
 */
class ContactCategoryTest extends MigrateSqlSourceTestCase {

  const PLUGIN_CLASS = 'Drupal\migrate_drupal\Plugin\migrate\source\d6\ContactCategory';

  protected $migrationConfiguration = array(
    'id' => 'test',
    'idlist' => array(),
    'source' => array(
      'plugin' => 'd6_contact_category',
    ),
  );

  protected $expectedResults = array(
    array(
      'cid' => 1,
      'category' => 'contact category value 1',
      'recipients' => array('admin@example.com','user@example.com'),
      'reply' => 'auto reply value 1',
      'weight' => 0,
      'selected' => 0,
    ),
    array(
      'cid' => 2,
      'category' => 'contact category value 2',
      'recipients' => array('admin@example.com','user@example.com'),
      'reply' => 'auto reply value 2',
      'weight' => 0,
      'selected' => 0,
    ),
  );

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'D6 contact category source functionality',
      'description' => 'Tests D6 contact category source plugin.',
      'group' => 'Migrate Drupal',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    foreach ($this->expectedResults as $k => $row) {
      $this->databaseContents['contact'][$k] = $row;
      $this->databaseContents['contact'][$k]['recipients'] = implode(',', $row['recipients']);
    }
    parent::setUp();
  }

}

namespace Drupal\migrate_drupal\Tests\source\d6;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\migrate_drupal\Plugin\migrate\source\d6\ContactCategory;

class TestContactCategory extends ContactCategory {
  public function setDatabase(Connection $database) {
    $this->database = $database;
  }
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }
}
