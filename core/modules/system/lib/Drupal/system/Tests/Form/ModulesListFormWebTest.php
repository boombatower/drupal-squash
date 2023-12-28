<?php

/**
 * @file
 * Contains \Drupal\system\Form\ModulesListFormWebTest.
 */

namespace Drupal\system\Tests\Form;

use Drupal\simpletest\WebTestBase;

/**
 * Tests \Drupal\system\Form\ModulesListForm.
 */
class ModulesListFormWebTest extends WebTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = array('system_test');

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'description' => 'Tests \Drupal\system\Form\ModulesListForm.',
      'name' => '\Drupal\system\Form\ModulesListForm web test',
      'group' => 'Module',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    \Drupal::state()->set('system_test.module_hidden', FALSE);
  }

  /**
   * Tests the module list form.
   */
  public function testModuleListForm() {
    $this->drupalLogin($this->drupalCreateUser(array('administer modules')));
    $this->drupalGet('admin/modules');
    $this->assertResponse('200');

    // Check that system_test's configure link was rendered correctly.
    $this->assertLinkByHref('configure/bar');
  }
}
