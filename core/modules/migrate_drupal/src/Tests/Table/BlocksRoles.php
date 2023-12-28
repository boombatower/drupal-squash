<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\Dump\BlocksRoles.
 *
 * THIS IS A GENERATED FILE. DO NOT EDIT.
 *
 * @see cores/scripts/dump-database-d6.sh
 * @see https://www.drupal.org/sandbox/benjy/2405029
 */

namespace Drupal\migrate_drupal\Tests\Table;

use Drupal\migrate_drupal\Tests\Dump\Drupal6DumpBase;

/**
 * Generated file to represent the blocks_roles table.
 */
class BlocksRoles extends Drupal6DumpBase {

  public function load() {
    $this->createTable("blocks_roles", array(
      'primary key' => array(
        'module',
        'delta',
        'rid',
      ),
      'fields' => array(
        'module' => array(
          'type' => 'varchar',
          'not null' => TRUE,
          'length' => '64',
        ),
        'delta' => array(
          'type' => 'varchar',
          'not null' => TRUE,
          'length' => '32',
        ),
        'rid' => array(
          'type' => 'int',
          'not null' => TRUE,
          'length' => '10',
          'unsigned' => TRUE,
        ),
      ),
    ));
    $this->database->insert("blocks_roles")->fields(array(
      'module',
      'delta',
      'rid',
    ))
    ->execute();
  }

}
