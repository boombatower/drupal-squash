<?php

/**
 * @file
 * Contains \Drupal\node\Tests\NodeTypeRenameConfigImportTest.
 */

namespace Drupal\node\Tests;

use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\Entity\ConfigEntityStorage;
use Drupal\simpletest\WebTestBase;

/**
 * Tests importing renamed node type via configuration synchronisation.
 */
class NodeTypeRenameConfigImportTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('node', 'text', 'config');

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'Import renamed node type',
      'description' => 'Tests importing renamed node type via configuration synchronisation.',
      'group' => 'Configuration',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();
    $this->web_user = $this->drupalCreateUser(array('synchronize configuration'));
    $this->drupalLogin($this->web_user);
  }

  /**
   * Tests configuration renaming.
   */
  public function testConfigurationRename() {
    $content_type = entity_create('node_type', array(
      'type' => Unicode::strtolower($this->randomName(16)),
      'name' => $this->randomName(),
    ));
    $content_type->save();
    $staged_type = $content_type->type;
    $active = $this->container->get('config.storage');
    $staging = $this->container->get('config.storage.staging');

    $config_name = $content_type->getEntityType()->getConfigPrefix() . '.' . $content_type->id();
    // Emulate a staging operation.
    $this->copyConfig($active, $staging);

    // Change the machine name of the content type.
    $content_type->type = Unicode::strtolower($this->randomName(8));
    $content_type->save();
    $active_type = $content_type->type;
    $renamed_config_name = $content_type->getEntityType()->getConfigPrefix() . '.' . $content_type->id();
    $this->assertTrue($active->exists($renamed_config_name), 'The content type has the new name in the active store.');
    $this->assertFalse($active->exists($config_name), "The content type's old name does not exist active store.");

    $this->configImporter()->reset();
    $this->assertEqual(0, count($this->configImporter()->getUnprocessedConfiguration('create')), 'There are no configuration items to create.');
    $this->assertEqual(0, count($this->configImporter()->getUnprocessedConfiguration('delete')), 'There are no configuration items to delete.');
    $this->assertEqual(0, count($this->configImporter()->getUnprocessedConfiguration('update')), 'There are no configuration items to update.');

    // We expect that changing the machine name of the content type will
    // rename five configuration entities: the node type, the body field
    // instance, two entity form displays, and the entity view display.
    // @see \Drupal\node\Entity\NodeType::postSave()
    $expected = array(
      'node.type.' . $active_type . '::node.type.' . $staged_type,
      'entity.form_display.node.' . $active_type . '.default::entity.form_display.node.' . $staged_type . '.default',
      'entity.view_display.node.' . $active_type . '.default::entity.view_display.node.' . $staged_type . '.default',
      'entity.view_display.node.' . $active_type . '.teaser::entity.view_display.node.' . $staged_type . '.teaser',
      'field.instance.node.' . $active_type . '.body::field.instance.node.' . $staged_type . '.body',
    );
    $renames = $this->configImporter()->getUnprocessedConfiguration('rename');
    $this->assertIdentical($expected, $renames);

    $this->drupalGet('admin/config/development/configuration');
    foreach ($expected as $rename) {
      $names = $this->configImporter()->getStorageComparer()->extractRenameNames($rename);
      $this->assertText(String::format('!source_name to !target_name', array('!source_name' => $names['old_name'], '!target_name' => $names['new_name'])));
      // Test that the diff link is present for each renamed item.
      $href = \Drupal::urlGenerator()->getPathFromRoute('config.diff', array('source_name' => $names['old_name'], 'target_name' => $names['new_name']));
      $this->assertLinkByHref($href);
      $hrefs[$rename] = $href;
    }

    // Ensure that the diff works for each renamed item.
    foreach ($hrefs as $rename => $href) {
      $this->drupalGet($href);
      $names = $this->configImporter()->getStorageComparer()->extractRenameNames($rename);
      $config_entity_type = \Drupal::service('config.manager')->getEntityTypeIdByName($names['old_name']);
      $entity_type = \Drupal::entityManager()->getDefinition($config_entity_type);
      $old_id = ConfigEntityStorage::getIDFromConfigName($names['old_name'], $entity_type->getConfigPrefix());
      $new_id = ConfigEntityStorage::getIDFromConfigName($names['new_name'], $entity_type->getConfigPrefix());

      // Because table columns can be on multiple lines, need to assert a regex
      // pattern rather than normal text.
      $id_key = $entity_type->getKey('id');
      $text = "$id_key: $old_id";
      $this->assertTextPattern('/\-\s+' . preg_quote($text, '/') . '/', "'-$text' found.");
      $text = "$id_key: $new_id";
      $this->assertTextPattern('/\+\s+' . preg_quote($text, '/') . '/', "'+$text' found.");
    }

    // Run the import.
    $this->drupalPostForm('admin/config/development/configuration', array(), t('Import all'));
    $this->assertText(t('There are no configuration changes.'));

    $this->assertFalse(entity_load('node_type', $active_type), 'The content no longer exists with the old name.');
    $content_type = entity_load('node_type', $staged_type);
    $this->assertIdentical($staged_type, $content_type->type);
  }

  /**
   * Asserts that a Perl regex pattern is found in the text content.
   *
   * @param string $pattern
   *   Perl regex to look for including the regex delimiters.
   * @param string $message
   *   (optional) A message to display with the assertion.
   *
   * @return bool
   *   TRUE on pass, FALSE on failure.
   */
  protected function assertTextPattern($pattern, $message = NULL) {
    // @see WebTestBase::assertTextHelper()
    if ($this->plainTextContent === FALSE) {
      $this->plainTextContent = Xss::filter($this->drupalGetContent(), array());
    }
    // @see WebTestBase::assertPattern()
    if (!$message) {
      $message = String::format('Pattern "@pattern" found', array('@pattern' => $pattern));
    }
    return $this->assert((bool) preg_match($pattern, $this->plainTextContent), $message);
  }

}
