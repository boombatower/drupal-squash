<?php

/**
 * @file
 * Definition of Drupal\image\Tests\ImageThemeFunctionTest.
 */

namespace Drupal\image\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests image theme functions.
 */
class ImageThemeFunctionTest extends WebTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('image');

  public static function getInfo() {
    return array(
      'name' => 'Image theme functions',
      'description' => 'Tests the image theme functions.',
      'group' => 'Image',
    );
  }

  /**
   * Tests usage of the image field formatters.
   */
  function testImageFormatterTheme() {
    // Create an image.
    $files = $this->drupalGetTestFiles('image');
    $file = reset($files);
    $original_uri = file_unmanaged_copy($file->uri, 'public://', FILE_EXISTS_RENAME);

    // Create a style.
    $style = entity_create('image_style', array('name' => 'test', 'label' => 'Test'));
    $style->save();
    $url = image_style_url('test', $original_uri);

    // Test using theme_image_formatter() without an image title, alt text, or
    // link options.
    $path = $this->randomName();
    $element = array(
      '#theme' => 'image_formatter',
      '#image_style' => 'test',
      '#item' => array(
        'uri' => $original_uri,
      ),
      '#path' => array(
        'path' => $path,
      ),
    );
    $rendered_element = render($element);
    $expected_result = '<a href="' . base_path() . $path . '"><img class="image-style-test" src="' . $url . '" alt="" /></a>';
    $this->assertEqual($expected_result, $rendered_element, 'theme_image_formatter() correctly renders without title, alt, or path options.');

    // Link the image to a fragment on the page, and not a full URL.
    $fragment = $this->randomName();
    $element['#path']['path'] = '';
    $element['#path']['options'] = array(
      'external' => TRUE,
      'fragment' => $fragment,
    );
    $rendered_element = render($element);
    $expected_result = '<a href="#' . $fragment . '"><img class="image-style-test" src="' . $url . '" alt="" /></a>';
    $this->assertEqual($expected_result, $rendered_element, 'theme_image_formatter() correctly renders a link fragment.');
  }

  /**
   * Tests usage of the image style theme function.
   */
  function testImageStyleTheme() {
    // Create an image.
    $files = $this->drupalGetTestFiles('image');
    $file = reset($files);
    $original_uri = file_unmanaged_copy($file->uri, 'public://', FILE_EXISTS_RENAME);

    // Create a style.
    $style = entity_create('image_style', array('name' => 'image_test', 'label' => 'Test'));
    $style->save();
    $url = image_style_url('image_test', $original_uri);

    $path = $this->randomName();
    $element = array(
      '#theme' => 'image_style',
      '#style_name' => 'image_test',
      '#uri' => $original_uri,
    );
    $rendered_element = render($element);
    $expected_result = '<img class="image-style-image-test" src="' . $url . '" alt="" />';
    $this->assertEqual($expected_result, $rendered_element, 'theme_image_style() renders an image correctly.');
  }

}
