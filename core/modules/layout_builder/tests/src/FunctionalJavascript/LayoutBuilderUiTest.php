<?php

namespace Drupal\Tests\layout_builder\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;

/**
 * Tests the Layout Builder UI.
 *
 * @group layout_builder
 */
class LayoutBuilderUiTest extends WebDriverTestBase {

  /**
   * Path prefix for the field UI for the test bundle.
   *
   * @var string
   */
  const FIELD_UI_PREFIX = 'admin/structure/types/manage/bundle_with_section_field';

  public static $modules = [
    'layout_builder',
    'block',
    'node',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    // @todo The Layout Builder UI relies on local tasks; fix in
    //   https://www.drupal.org/project/drupal/issues/2917777.
    $this->drupalPlaceBlock('local_tasks_block');

    $this->createContentType(['type' => 'bundle_with_section_field']);

    $this->drupalLogin($this->drupalCreateUser([
      'configure any layout',
      'administer node display',
      'administer node fields',
    ]));

    // Enable layout builder.
    $this->drupalPostForm(
      static::FIELD_UI_PREFIX . '/display/default',
      ['layout[enabled]' => TRUE],
      'Save'
    );
  }

  /**
   * Tests that after removing sections reloading the page does not re-add them.
   */
  public function testReloadWithNoSections() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    // Remove all of the sections from the page.
    $this->drupalGet(static::FIELD_UI_PREFIX . '/display/default/layout');
    $page->clickLink('Remove section');
    $assert_session->assertWaitOnAjaxRequest();
    $page->pressButton('Remove');
    $assert_session->assertWaitOnAjaxRequest();
    // Assert that there are no sections on the page.
    $assert_session->pageTextNotContains('Remove section');
    $assert_session->pageTextNotContains('Add Block');

    // Reload the page.
    $this->drupalGet(static::FIELD_UI_PREFIX . '/display/default/layout');
    // Assert that there are no sections on the page.
    $assert_session->pageTextNotContains('Remove section');
    $assert_session->pageTextNotContains('Add Block');
  }

  /**
   * Tests the message indicating unsaved changes.
   */
  public function testUnsavedChangesMessage() {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    // Make and then discard changes.
    $this->assertModifiedLayout(static::FIELD_UI_PREFIX . '/display/default/layout');
    $page->pressButton('Discard changes');
    $page->pressButton('Confirm');
    $assert_session->pageTextNotContains('You have unsaved changes.');

    // Make and then save changes.
    $this->assertModifiedLayout(static::FIELD_UI_PREFIX . '/display/default/layout');
    $page->pressButton('Save layout');
    $assert_session->pageTextNotContains('You have unsaved changes.');
  }

  /**
   * Asserts that modifying a layout works as expected.
   *
   * @param string $path
   *   The path to a Layout Builder UI page.
   */
  protected function assertModifiedLayout($path) {
    $assert_session = $this->assertSession();
    $page = $this->getSession()->getPage();

    $this->drupalGet($path);
    $page->clickLink('Add Section');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextNotContains('You have unsaved changes.');
    $page->clickLink('One column');
    $assert_session->assertWaitOnAjaxRequest();
    $assert_session->pageTextContainsOnce('You have unsaved changes.');

    // Reload the page.
    $this->drupalGet($path);
    $assert_session->pageTextContainsOnce('You have unsaved changes.');
  }

}
