<?php

/**
 * @file
 * Definition of Drupal\node\Tests\NodeTranslationUITest.
 */

namespace Drupal\node\Tests;

use Drupal\Core\Entity\EntityInterface;
use Drupal\content_translation\Tests\ContentTranslationUITest;

/**
 * Tests the Node Translation UI.
 */
class NodeTranslationUITest extends ContentTranslationUITest {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('block', 'language', 'content_translation', 'node', 'datetime', 'field_ui');

  /**
   * The profile to install as a basis for testing.
   *
   * @var string
   */
  protected $profile = 'standard';

  public static function getInfo() {
    return array(
      'name' => 'Node translation UI',
      'description' => 'Tests the node translation UI.',
      'group' => 'Node',
    );
  }

  function setUp() {
    $this->entityTypeId = 'node';
    $this->bundle = 'article';
    parent::setUp();

    // Ensure the help message is shown even with prefixed paths.
    $this->drupalPlaceBlock('system_help_block', array('region' => 'content'));

    // Display the language selector.
    $this->drupalLogin($this->administrator);
    $edit = array('language_configuration[language_show]' => TRUE);
    $this->drupalPostForm('admin/structure/types/manage/article', $edit, t('Save content type'));
    $this->drupalLogin($this->translator);
  }

  /**
   * Overrides \Drupal\content_translation\Tests\ContentTranslationUITest::getTranslatorPermission().
   */
  protected function getTranslatorPermissions() {
    return array_merge(parent::getTranslatorPermissions(), array('administer nodes', "edit any $this->bundle content"));
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditorPermissions() {
    return array('administer nodes', 'create article content');
  }

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return array_merge(parent::getAdministratorPermissions(), array('access administration pages', 'administer content types', 'administer node fields', 'access content overview', 'bypass node access', 'administer languages', 'administer themes', 'view the administration theme'));
  }

  /**
   * Overrides \Drupal\content_translation\Tests\ContentTranslationUITest::getNewEntityValues().
   */
  protected function getNewEntityValues($langcode) {
    return array('title' => array(array('value' => $this->randomName()))) + parent::getNewEntityValues($langcode);
  }

  /**
   * {@inheritdoc}
   */
  protected function createEntity($values, $langcode, $bundle_name = NULL) {
    $this->drupalLogin($this->editor);
    $edit = array(
      'title[0][value]' => $values['title'][0]['value'],
      "{$this->fieldName}[0][value]" => $values[$this->fieldName][0]['value'],
      'langcode' => $langcode,
    );
    $this->drupalPostForm('node/add/article', $edit, t('Save and publish'));
    $this->drupalLogin($this->translator);
    $node = $this->drupalGetNodeByTitle($values['title']);
    return $node->id();
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormSubmitAction(EntityInterface $entity, $langcode) {
    if ($entity->getTranslation($langcode)->isPublished()) {
      return t('Save and keep published') . $this->getFormSubmitSuffix($entity, $langcode);
    }
    else {
      return t('Save and keep unpublished') . $this->getFormSubmitSuffix($entity, $langcode);
    }
  }

  /**
   * Overrides \Drupal\content_translation\Tests\ContentTranslationUITest::assertPublishedStatus().
   */
  protected function doTestPublishedStatus() {
    $entity = entity_load($this->entityTypeId, $this->entityId, TRUE);
    $path = $entity->getSystemPath('edit-form');
    $languages = $this->container->get('language_manager')->getLanguages();

    $actions = array(
      t('Save and keep published'),
      t('Save and unpublish'),
    );

    foreach ($actions as $index => $action) {
      // (Un)publish the node translations and check that the translation
      // statuses are (un)published accordingly.
      foreach ($this->langcodes as $langcode) {
        $this->drupalPostForm($path, array(), $action . $this->getFormSubmitSuffix($entity, $langcode), array('language' => $languages[$langcode]));
      }
      $entity = entity_load($this->entityTypeId, $this->entityId, TRUE);
      foreach ($this->langcodes as $langcode) {
        // The node is created as unpublished thus we switch to the published
        // status first.
        $status = !$index;
        $this->assertEqual($status, $entity->translation[$langcode]['status'], 'The translation has been correctly unpublished.');
        $translation = $entity->getTranslation($langcode);
        $this->assertEqual($status, $translation->isPublished(), 'The status of the translation has been correctly saved.');
      }
    }
  }

  /**
   * Overrides \Drupal\content_translation\Tests\ContentTranslationUITest::assertAuthoringInfo().
   */
  protected function doTestAuthoringInfo() {
    $entity = entity_load($this->entityTypeId, $this->entityId, TRUE);
    $path = $entity->getSystemPath('edit-form');
    $languages = $this->container->get('language_manager')->getLanguages();
    $values = array();

    // Post different base field information for each translation.
    foreach ($this->langcodes as $langcode) {
      $user = $this->drupalCreateUser();
      $values[$langcode] = array(
        'uid' => $user->id(),
        'created' => REQUEST_TIME - mt_rand(0, 1000),
        'sticky' => (bool) mt_rand(0, 1),
        'promote' => (bool) mt_rand(0, 1),
      );
      $edit = array(
        'uid' => $user->getUsername(),
        'created[date]' => format_date($values[$langcode]['created'], 'custom', 'Y-m-d'),
        'created[time]' => format_date($values[$langcode]['created'], 'custom', 'H:i:s'),
        'sticky' => $values[$langcode]['sticky'],
        'promote' => $values[$langcode]['promote'],
      );
      $this->drupalPostForm($path, $edit, $this->getFormSubmitAction($entity, $langcode), array('language' => $languages[$langcode]));
    }

    $entity = entity_load($this->entityTypeId, $this->entityId, TRUE);
    foreach ($this->langcodes as $langcode) {
      $this->assertEqual($entity->translation[$langcode]['uid'], $values[$langcode]['uid'], 'Translation author correctly stored.');
      $this->assertEqual($entity->translation[$langcode]['created'], $values[$langcode]['created'], 'Translation date correctly stored.');
      $translation = $entity->getTranslation($langcode);
      $this->assertEqual($translation->getOwnerId(), $values[$langcode]['uid'], 'Author of translation correctly stored.');
      $this->assertEqual($translation->getCreatedTime(), $values[$langcode]['created'], 'Date of Translation correctly stored.');
      $this->assertEqual($translation->isSticky(), $values[$langcode]['sticky'], 'Sticky of Translation correctly stored.');
      $this->assertEqual($translation->isPromoted(), $values[$langcode]['promote'], 'Promoted of Translation correctly stored.');
    }
  }

  /**
   * Tests translate link on content admin page.
   */
  function testTranslateLinkContentAdminPage() {
    $this->drupalLogin($this->administrator);

    $page = $this->drupalCreateNode(array('type' => 'page'));
    $article = $this->drupalCreateNode(array('type' => 'article', 'langcode' => $this->langcodes[0]));

    // Verify translation links.
    $this->drupalGet('admin/content');
    $this->assertResponse(200);
    $this->assertLinkByHref('node/' . $article->id() . '/translations');
    $this->assertNoLinkByHref('node/' . $page->id() . '/translations');
  }

  /**
   * Tests that translation page inherits admin status of edit page.
   */
  function testTranslationLinkTheme() {
    $this->drupalLogin($this->administrator);
    $article = $this->drupalCreateNode(array('type' => 'article', 'langcode' => $this->langcodes[0]));

    // Set up Seven as the admin theme and use it for node editing.
    $this->container->get('theme_handler')->enable(array('seven'));
    $edit = array();
    $edit['admin_theme'] = 'seven';
    $edit['use_admin_theme'] = TRUE;
    $this->drupalPostForm('admin/appearance', $edit, t('Save configuration'));
    $this->drupalGet('node/' . $article->id() . '/translations');
    $this->assertRaw('"theme":"seven"', 'Translation uses admin theme if edit is admin.');

    // Turn off admin theme for editing, assert inheritance to translations.
    $edit['use_admin_theme'] = FALSE;
    $this->drupalPostForm('admin/appearance', $edit, t('Save configuration'));
    $this->drupalGet('node/' . $article->id() . '/translations');
    $this->assertNoRaw('"theme":"seven"', 'Translation uses frontend theme if edit is frontend.');
  }

  /**
   * Tests that no metadata is stored for a disabled bundle.
   */
  public function testDisabledBundle() {
    // Create a bundle that does not have translation enabled.
    $disabledBundle = $this->randomName();
    $this->drupalCreateContentType(array('type' => $disabledBundle, 'name' => $disabledBundle));

    // Create a node for each bundle.
    $enabledNode = $this->drupalCreateNode(array('type' => $this->bundle));

    // Make sure that only a single row was inserted into the
    // {content_translation} table.
    $rows = db_query('SELECT * FROM {content_translation}')->fetchAll();
    $this->assertEqual(1, count($rows));
    $this->assertEqual($enabledNode->id(), reset($rows)->entity_id);
  }

  /**
   * Tests that translations are rendered properly.
   */
  function testTranslationRendering() {
    $default_langcode = $this->langcodes[0];
    $values[$default_langcode] = $this->getNewEntityValues($default_langcode);
    $this->entityId = $this->createEntity($values[$default_langcode], $default_langcode);
    $node = \Drupal::entityManager()->getStorage($this->entityTypeId)->load($this->entityId);
    $node->setPromoted(TRUE);

    // Create translations.
    foreach (array_diff($this->langcodes, array($default_langcode)) as $langcode) {
      $values[$langcode] = $this->getNewEntityValues($langcode);
      $translation = $node->addTranslation($langcode, $values[$langcode]);
      // Publish and promote the translation to frontpage.
      $translation->setPromoted(TRUE);
      $translation->setPublished(TRUE);
    }
    $node->save();

    // Test that the frontpage view displays the correct translations.
    \Drupal::moduleHandler()->install(array('views'), TRUE);
    $this->rebuildContainer();
    $this->doTestTranslations('node', $values);

    // Enable the translation language renderer.
    $view = \Drupal::entityManager()->getStorage('view')->load('frontpage');
    $display = &$view->getDisplay('default');
    $display['display_options']['row']['options']['rendering_language'] = 'translation_language_renderer';
    $view->save();

    // Test that the frontpage view displays all translated nodes correctly by
    // checking that the title for each translation is present.
    $this->drupalGet('node');
    foreach ($this->langcodes as $langcode) {
      $this->assertText($values[$langcode]['title'][0]['value']);
    }

    // Need to check from the beginning, including the base_path, in the url
    // since the pattern for the default language might be a substring of
    // the strings for other languages.
    $base_path = base_path();

    // Check the frontpage for 'Read more' links to each translation.
    // See also assertTaxonomyPage() in NodeAccessBaseTableTest.
    $node_href = 'node/' . $node->id();
    foreach ($this->langcodes as $langcode) {
      $num_match_found = 0;
      if ($langcode == 'en') {
        // Site default language does not have langcode prefix in the URL.
        $expected_href = $base_path . $node_href;
      }
      else {
        $expected_href = $base_path . $langcode . '/' . $node_href;
      }
      $pattern = '|^' . $expected_href . '$|';
      foreach ($this->xpath("//a[text()='Read more']") as $link) {
        if (preg_match($pattern, (string) $link['href'], $matches) == TRUE) {
          $num_match_found++;
        }
      }
      $this->assertTrue($num_match_found == 1, 'There is 1 Read more link, ' . $expected_href . ', for the ' . $langcode . ' translation of a node on the frontpage. (Found ' . $num_match_found . '.)');
    }

    // Check the frontpage for 'Add new comment' links that include the
    // language.
    $comment_form_href = 'node/' . $node->id() . '#comment-form';
    foreach ($this->langcodes as $langcode) {
      $num_match_found = 0;
      if ($langcode == 'en') {
        // Site default language does not have langcode prefix in the URL.
        $expected_href = $base_path . $comment_form_href;
      }
      else {
        $expected_href = $base_path . $langcode . '/' . $comment_form_href;
      }
      $pattern = '|^' . $expected_href . '$|';
      foreach ($this->xpath("//a[text()='Add new comment']") as $link) {
        if (preg_match($pattern, (string) $link['href'], $matches) == TRUE) {
          $num_match_found++;
        }
      }
      $this->assertTrue($num_match_found == 1, 'There is 1 Add new comment link, ' . $expected_href . ', for the ' . $langcode . ' translation of a node on the frontpage. (Found ' . $num_match_found . '.)');
    }

    // Test that the node page displays the correct translations.
    $this->doTestTranslations('node/' . $node->id(), $values);
  }

  /**
   * Tests that the given path displays the correct translation values.
   *
   * @param string $path
   *   The path to be tested.
   * @param array $values
   *   The translation values to be found.
   */
  protected function doTestTranslations($path, array $values) {
    $languages = $this->container->get('language_manager')->getLanguages();
    foreach ($this->langcodes as $langcode) {
      $this->drupalGet($path, array('language' => $languages[$langcode]));
      $this->assertText($values[$langcode]['title'][0]['value'], format_string('The %langcode node translation is correctly displayed.', array('%langcode' => $langcode)));
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function getFormSubmitSuffix(EntityInterface $entity, $langcode) {
    if (!$entity->isNew() && $entity->isTranslatable()) {
      $translations = $entity->getTranslationLanguages();
      if ((count($translations) > 1 || !isset($translations[$langcode])) && ($field = $entity->getFieldDefinition('status'))) {
        return ' ' . ($field->isTranslatable() ? t('(this translation)') : t('(all translations)'));
      }
    }
    return '';
  }
}
