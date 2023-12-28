<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\source\d6\MenuTest.
 */

namespace Drupal\migrate_drupal\Tests\source\d6;

use Drupal\migrate\Tests\MigrateSqlSourceTestCase;

/**
 * Tests the Drupal 6 menu source.
 *
 * @group migrate_drupal
 * @group Drupal
 */
class MenuTest extends MigrateSqlSourceTestCase {

  // The plugin system is not working during unit testing so the source plugin
  // class needs to be manually specified.
  const PLUGIN_CLASS = 'Drupal\migrate_drupal\Plugin\migrate\source\d6\Menu';

  // The fake Migration configuration entity.
  protected $migrationConfiguration = array(
    // The ID of the entity, can be any string.
    'id' => 'test',
    // Leave it empty for now.
    'idlist' => array(),
    // This needs to be the identifier of the actual key: cid for comment, nid
    // for node and so on.
    'source' => array(
      'plugin' => 'd6_menu',
    ),
  );

  // We need to set up the database contents; it's easier to do that below.

  protected $expectedResults = array(
    array(
      'menu_name' => 'menu-name-1',
      'title' => 'menu custom value 1',
      'description' => 'menu custom description value 1',
    ),
    array(
      'menu_name' => 'menu-name-2',
      'title' => 'menu custom value 2',
      'description' => 'menu custom description value 2',
    ),
  );

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'D6 menu source functionality',
      'description' => 'Tests D6 menu source plugin.',
      'group' => 'Migrate Drupal',
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    // This array stores the database.
    foreach ($this->expectedResults as $k => $row) {
      $this->databaseContents['menu_custom'][$k] = $row;
    }
    parent::setUp();
  }

}

namespace Drupal\migrate_drupal\Tests\source\d6;

use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\migrate_drupal\Plugin\migrate\source\d6\Menu;

class TestMenu extends Menu {
  public function setDatabase(Connection $database) {
    $this->database = $database;
  }
  public function setModuleHandler(ModuleHandlerInterface $module_handler) {
    $this->moduleHandler = $module_handler;
  }
}
