<?php
/**
 * @file
 * Contains \Drupal\rdf\Tests\Field\EmailFieldRdfaTest.
 */

namespace Drupal\rdf\Tests\Field;

use Drupal\rdf\Tests\Field\FieldRdfaTestBase;

/**
 * Tests the placement of RDFa in email field formatters.
 */
class EmailFieldRdfaTest extends FieldRdfaTestBase {

  /**
   * {@inheritdoc}
   */
  protected $fieldType = 'email';

  /**
   * {@inheritdoc}
   */
  public static $modules = array('email', 'text');

  public static function getInfo() {
    return array(
      'name'  => 'Field formatter: email',
      'description'  => 'Tests RDFa output by email field formatters.',
      'group' => 'RDF',
    );
  }

  public function setUp() {
    parent::setUp();

    $this->createTestField();

    // Add the mapping.
    $mapping = rdf_get_mapping('entity_test', 'entity_test');
    $mapping->setFieldMapping($this->fieldName, array(
      'properties' => array('schema:email'),
    ))->save();

    // Set up test values.
    $this->testValue = 'test@example.com';
    $this->entity = entity_create('entity_test', array());
    $this->entity->{$this->fieldName}->value = $this->testValue;
  }

  /**
   * Tests all email formatters.
   */
  public function testAllFormatters() {
    // Test the plain formatter.
    $this->assertFormatterRdfa('text_plain', 'http://schema.org/email', $this->testValue);
    // Test the mailto formatter.
    $this->assertFormatterRdfa('email_mailto', 'http://schema.org/email', $this->testValue);
  }
}
