<?php

/**
 * @file
 * Contains \Drupal\migrate_drupal\Tests\Dump\History.
 *
 * THIS IS A GENERATED FILE. DO NOT EDIT.
 *
 * @see cores/scripts/dump-database-d6.sh
 * @see https://www.drupal.org/sandbox/benjy/2405029
 */

namespace Drupal\migrate_drupal\Tests\Table;

use Drupal\migrate_drupal\Tests\Dump\Drupal6DumpBase;

/**
 * Generated file to represent the history table.
 */
class History extends Drupal6DumpBase {

  public function load() {
    $this->createTable("history", array(
      'primary key' => array(
        'uid',
        'nid',
      ),
      'fields' => array(
        'uid' => array(
          'type' => 'int',
          'not null' => TRUE,
          'length' => '11',
          'default' => '0',
        ),
        'nid' => array(
          'type' => 'int',
          'not null' => TRUE,
          'length' => '11',
          'default' => '0',
        ),
        'timestamp' => array(
          'type' => 'int',
          'not null' => TRUE,
          'length' => '11',
          'default' => '0',
        ),
      ),
    ));
    $this->database->insert("history")->fields(array(
      'uid',
      'nid',
      'timestamp',
    ))
    ->execute();
  }

}
