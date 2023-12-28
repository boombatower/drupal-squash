<?php

/**
 * @file
 * Contains Drupal\content_translation\Tests\ContentTranslationSettingsTest.
 */

namespace Drupal\content_translation\Tests;

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Core\Language\Language;
use Drupal\field\Entity\FieldInstanceConfig;
use Drupal\simpletest\WebTestBase;

/**
 * Tests the Entity Test Translation UI.
 */
class ContentTranslationSettingsTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('language', 'content_translation', 'node', 'comment', 'field_ui', 'entity_test');

  public static function getInfo() {
    return array(
      'name' => 'Content Translation settings',
      'description' => 'Tests the content translation settings UI.',
      'group' => 'Content Translation UI',
    );
  }

  function setUp() {
    parent::setUp();

    // Set up two content types to test field instances shared between different
    // bundles.
    $this->drupalCreateContentType(array('type' => 'article'));
    $this->drupalCreateContentType(array('type' => 'page'));
    $this->container->get('comment.manager')->addDefaultField('node', 'article', 'comment_article', CommentItemInterface::OPEN, 'comment_article');
    $this->container->get('comment.manager')->addDefaultField('node', 'page', 'comment_page');

    $admin_user = $this->drupalCreateUser(array('access administration pages', 'administer languages', 'administer content translation', 'administer content types', 'administer node fields', 'administer comment fields', 'administer comments', 'administer comment types'));
    $this->drupalLogin($admin_user);
  }

  /**
   * Tests that the settings UI works as expected.
   */
  function testSettingsUI() {
    // Check for the content_translation_menu_link_defaults_alter() changes.
    $this->drupalGet('admin/config');
    $this->assertLink('Content language and translation');
    $this->assertText('Configure language and translation support for content.');
    // Test that the translation settings are ignored if the bundle is marked
    // translatable but the entity type is not.
    $edit = array('settings[comment][comment_article][translatable]' => TRUE);
    $this->assertSettings('comment', NULL, FALSE, $edit);

    // Test that the translation settings are ignored if only a field is marked
    // as translatable and not the related entity type and bundle.
    $edit = array('settings[comment][comment_article][fields][comment_body]' => TRUE);
    $this->assertSettings('comment', NULL, FALSE, $edit);

    // Test that the translation settings are not stored if an entity type and
    // bundle are marked as translatable but no field is.
    $edit = array(
      'entity_types[comment]' => TRUE,
      'settings[comment][comment_article][translatable]' => TRUE,
    );
    $this->assertSettings('comment', 'comment_article', FALSE, $edit);
    $xpath_err = '//div[contains(@class, "error")]';
    $this->assertTrue($this->xpath($xpath_err), 'Enabling translation only for entity bundles generates a form error.');

    // Test that the translation settings are not stored if a non-configurable
    // language is set as default and the language selector is hidden.
    $edit = array(
      'entity_types[comment]' => TRUE,
      'settings[comment][comment_article][settings][language][langcode]' => Language::LANGCODE_NOT_SPECIFIED,
      'settings[comment][comment_article][settings][language][language_show]' => FALSE,
      'settings[comment][comment_article][translatable]' => TRUE,
      'settings[comment][comment_article][fields][comment_body]' => TRUE,
    );
    $this->assertSettings('comment', 'comment_article', FALSE, $edit);
    $this->assertTrue($this->xpath($xpath_err), 'Enabling translation with a fixed non-configurable language generates a form error.');

    // Test that a field shared among different bundles can be enabled without
    // needing to make all the related bundles translatable.
    $edit = array(
      'entity_types[comment]' => TRUE,
      'settings[comment][comment_article][settings][language][langcode]' => 'current_interface',
      'settings[comment][comment_article][settings][language][language_show]' => TRUE,
      'settings[comment][comment_article][translatable]' => TRUE,
      'settings[comment][comment_article][fields][comment_body]' => TRUE,
    );
    $this->assertSettings('comment', 'comment_article', TRUE, $edit);
    $definition = $this->entityManager()->getFieldDefinitions('comment', 'comment_article')['comment_body'];
    $this->assertTrue($definition->isTranslatable(), 'Article comment body is translatable.');
    $definition = $this->entityManager()->getFieldDefinitions('comment', 'comment')['comment_body'];
    $this->assertFalse($definition->isTranslatable(), 'Page comment body is not translatable.');
    $this->assertNull(content_translation_get_config('comment', 'comment_article', 'fields'), 'Configurable fields are not saved to content_translation.settings.');

    // Test that translation can be enabled for base fields.
    $edit = array(
      'entity_types[entity_test_mul]' => TRUE,
      'settings[entity_test_mul][entity_test_mul][translatable]' => TRUE,
      'settings[entity_test_mul][entity_test_mul][fields][name]' => TRUE,
      'settings[entity_test_mul][entity_test_mul][fields][user_id]' => FALSE,
    );
    $this->assertSettings('entity_test_mul', 'entity_test_mul', TRUE, $edit);
    $settings = content_translation_get_config('entity_test_mul', 'entity_test_mul', 'fields');
    $this->assertTrue($settings['name'] && !$settings['user_id'], 'Base fields are saved to content_translation.settings.');
    $definitions = $this->entityManager()->getFieldDefinitions('entity_test_mul', 'entity_test_mul');
    $this->assertTrue($definitions['name']->isTranslatable() && !$definitions['user_id']->isTranslatable(), 'Bundle field definitions were correctly altered.');

    // Test that language settings are correctly stored.
    $language_configuration = language_get_default_configuration('comment', 'comment_article');
    $this->assertEqual($language_configuration['langcode'], 'current_interface', 'The default language for article comments is set to the current interface language.');
    $this->assertTrue($language_configuration['language_show'], 'The language selector for article comments is shown.');

    // Verify language widget appears on comment type form.
    $this->drupalGet('admin/structure/comment/manage/comment_article');
    $this->assertField('language_configuration[content_translation]');
    $this->assertFieldChecked('edit-language-configuration-content-translation');

    // Verify that translation may be enabled for the article content type.
    $edit = array(
      'language_configuration[content_translation]' => TRUE,
    );
    // Make sure the checkbox is available and not checked by default.
    $this->drupalGet('admin/structure/types/manage/article');
    $this->assertField('language_configuration[content_translation]');
    $this->assertNoFieldChecked('edit-language-configuration-content-translation');
    $this->drupalPostForm('admin/structure/types/manage/article', $edit, t('Save content type'));
    $this->drupalGet('admin/structure/types/manage/article');
    $this->assertFieldChecked('edit-language-configuration-content-translation');

    // Test that the title field of nodes is available in the settings form.
    $edit = array(
      'entity_types[node]' => TRUE,
      'settings[node][article][settings][language][langcode]' => 'current_interface',
      'settings[node][article][settings][language][language_show]' => TRUE,
      'settings[node][article][translatable]' => TRUE,
      'settings[node][article][fields][title]' => TRUE
    );
    $this->assertSettings('node', NULL, TRUE, $edit);

    foreach (array(TRUE, FALSE) as $translatable) {
      // Test that configurable field translatability is correctly switched.
      $edit = array('settings[node][article][fields][body]' => $translatable);
      $this->assertSettings('node', 'article', TRUE, $edit);
      $instance = FieldInstanceConfig::loadByName('node', 'article', 'body');
      $definitions = \Drupal::entityManager()->getFieldDefinitions('node', 'article');
      $this->assertEqual($definitions['body']->isTranslatable(), $translatable, 'Field translatability correctly switched.');
      $this->assertEqual($instance->isTranslatable(), $definitions['body']->isTranslatable(), 'Configurable field translatability correctly switched.');

      // Test that also the Field UI form behaves correctly.
      $translatable = !$translatable;
      $edit = array('instance[translatable]' => $translatable);
      $this->drupalPostForm('admin/structure/types/manage/article/fields/node.article.body', $edit, t('Save settings'));
      \Drupal::entityManager()->clearCachedFieldDefinitions();
      $instance = FieldInstanceConfig::loadByName('node', 'article', 'body');
      $definitions = \Drupal::entityManager()->getFieldDefinitions('node', 'article');
      $this->assertEqual($definitions['body']->isTranslatable(), $translatable, 'Field translatability correctly switched.');
      $this->assertEqual($instance->isTranslatable(), $definitions['body']->isTranslatable(), 'Configurable field translatability correctly switched.');
    }
  }

  /**
   * Asserts that translatability has the expected value for the given bundle.
   *
   * @param string $entity_type
   *   The entity type for which to check translatibility.
   * @param string $bundle
   *   The bundle for which to check translatibility.
   * @param boolean $enabled
   *   TRUE if translatibility should be enabled, FALSE otherwise.
   * @param array $edit
   *   An array of values to submit to the content translation settings page.
   *
   * @return boolean
   *   TRUE if the assertion succeeded, FALSE otherwise.
   */
  protected function assertSettings($entity_type, $bundle, $enabled, $edit) {
    $this->drupalPostForm('admin/config/regional/content-language', $edit, t('Save'));
    $args = array('@entity_type' => $entity_type, '@bundle' => $bundle, '@enabled' => $enabled ? 'enabled' : 'disabled');
    $message = format_string('Translation for entity @entity_type (@bundle) is @enabled.', $args);
    \Drupal::entityManager()->clearCachedDefinitions();
    return $this->assertEqual(content_translation_enabled($entity_type, $bundle), $enabled, $message);
  }

  /**
   * Tests that field instance setting depends on bundle translatability.
   */
  function testFieldTranslatableSettingsUI() {

    // At least one field needs to be translatable to enable article for
    // translation. Create an extra field to be used for this purpose.
    $field = array(
      'name' => 'article_text',
      'entity_type' => 'node',
      'type' => 'text',
    );
    entity_create('field_config', $field)->save();
    $instance = array(
      'field_name' => 'article_text',
      'entity_type' => 'node',
      'bundle' => 'article',
    );
    entity_create('field_instance_config', $instance)->save();

    // Tests that field instance doesn't have translatable setting if bundle
    // is not translatable.
    $path = 'admin/structure/types/manage/article/fields/node.article.body';
    $this->drupalGet($path);
    $this->assertText('To configure translation for this field, enable language support for this type.', 'No translatable setting for field.');

    // Tests that field instance has translatable setting if bundle is
    // translatable. Note: this field instance is not translatable when
    // enable bundle translatability.
    $edit = array(
      'entity_types[node]' => TRUE,
      'settings[node][article][settings][language][language_show]' => TRUE,
      'settings[node][article][translatable]' => TRUE,
      'settings[node][article][fields][article_text]' => TRUE,
    );
    $this->assertSettings('node', 'article', TRUE, $edit);
    $this->drupalGet($path);
    $this->assertNoText('To enable translation of this field, enable language support for this type.', 'No translatable setting for field.');
  }

  /**
   * Returns the entity manager.
   *
   * @return \Drupal\Core\Entity\EntityManagerInterface
   *   The entity manager;
   */
  protected function entityManager() {
    return $this->container->get('entity.manager');
  }

}
