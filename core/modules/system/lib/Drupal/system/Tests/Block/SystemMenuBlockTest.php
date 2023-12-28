<?php
/**
 * Contains \Drupal\system\Tests\Block\SystemMenuBlockTest
 */

namespace Drupal\system\Tests\Block;

use Drupal\simpletest\DrupalUnitTestBase;

/**
 * Tests \Drupal\system\Plugin\Block\SystemMenuBlock
 *
 * @todo Expand test coverage to all SystemMenuBlock functionality, including
 *   block_menu_delete().
 *
 * @see \Drupal\system\Plugin\Derivative\SystemMenuBlock
 * @see \Drupal\system\Plugin\Block\SystemMenuBlock
 */
class SystemMenuBlockTest extends DrupalUnitTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('system', 'menu_link', 'block');

  public static function getInfo() {
    return array(
      'name' => 'System menu block test',
      'description' => 'Tests \Drupal\system\Plugin\Block\SystemMenuBlock.',
      'group' => 'System blocks',
    );
  }

  /**
   * Tests calculation of a system menu block's configuration dependencies.
   */
  public function testSystemMenuBlockConfigDependencies() {
    // Add a new custom menu.
    $menu_name = $this->randomName(16);
    $label = $this->randomName(16);

    $menu = entity_create('menu', array(
      'id' => $menu_name,
      'label' => $label,
      'description' => 'Description text',
    ));
    $menu->save();

    $block = entity_create('block', array(
      'plugin' => 'system_menu_block:'. $menu->id(),
      'region' => 'footer',
      'id' => 'machinename',
      'theme' => 'stark',
    ));

    $dependencies = $block->calculateDependencies();
    $expected = array(
      'entity' => array(
        'system.menu.' . $menu->id()
      ),
      'module' => array(
        'system'
      ),
      'theme' => array(
        'stark'
      ),
    );
    $this->assertIdentical($expected, $dependencies);
  }
}
