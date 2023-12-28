<?php

/**
 * @file
 * Definition of Drupal\field\Tests\TranslationTest.
 */

namespace Drupal\field\Tests;

use Drupal\Core\Language\Language;

/**
 * Unit test class for the multilanguage fields logic.
 *
 * The following tests will check the multilanguage logic of _field_invoke() and
 * that only the correct values are returned by field_available_languages().
 */
class TranslationTest extends FieldUnitTestBase {

  /**
   * Modules to enable.
   *
   * node is required because the tests alter node entity info.
   *
   * @var array
   */
  public static $modules = array('language', 'node');

  /**
   * The name of the field to use in this test.
   *
   * @var string
   */
  protected $field_name;

  /**
   * The name of the entity type to use in this test.
   *
   * @var string
   */
  protected $entity_type = 'test_entity';


  /**
   * An array defining the field to use in this test.
   *
   * @var array
   */
  protected $field_definition;

  /**
   * An array defining the field instance to use in this test.
   *
   * @var array
   */
  protected $instance_definition;

  /**
   * The field to use in this test.
   *
   * @var \Drupal\field\Plugin\Core\Entity\Field
   */
  protected $field;

  /**
   * The field instance to use in this test.
   *
   * @var \Drupal\field\Plugin\Core\Entity\FieldInstance
   */
  protected $instance;

  public static function getInfo() {
    return array(
      'name' => 'Field translations tests',
      'description' => 'Test multilanguage fields logic.',
      'group' => 'Field API',
    );
  }

  function setUp() {
    parent::setUp();
    $this->installSchema('language', array('language'));
    $this->installSchema('node', array('node_type'));

    $this->field_name = drupal_strtolower($this->randomName() . '_field_name');

    $this->field_definition = array(
      'field_name' => $this->field_name,
      'type' => 'test_field',
      'cardinality' => 4,
      'translatable' => TRUE,
    );
    entity_create('field_entity', $this->field_definition)->save();
    $this->field = field_read_field($this->field_name);

    $this->instance_definition = array(
      'field_name' => $this->field_name,
      'entity_type' => $this->entity_type,
      'bundle' => 'test_bundle',
    );
    entity_create('field_instance', $this->instance_definition)->save();
    $this->instance = field_read_instance('test_entity', $this->field_name, 'test_bundle');

    for ($i = 0; $i < 3; ++$i) {
      $language = new Language(array(
        'langcode' => 'l' . $i,
        'name' => $this->randomString(),
      ));
      language_save($language);
    }
  }

  /**
   * Ensures that only valid values are returned by field_available_languages().
   */
  function testFieldAvailableLanguages() {
    // Test 'translatable' fieldable info.
    field_test_entity_info_translatable('test_entity', FALSE);
    $field = clone($this->field);
    $field['field_name'] .= '_untranslatable';

    // Enable field translations for the entity.
    field_test_entity_info_translatable('test_entity', TRUE);

    // Test hook_field_languages() invocation on a translatable field.
    \Drupal::state()->set('field_test.field_available_languages_alter', TRUE);
    $langcodes = field_content_languages();
    $available_langcodes = field_available_languages($this->entity_type, $this->field);
    foreach ($available_langcodes as $delta => $langcode) {
      if ($langcode != 'xx' && $langcode != 'en') {
        $this->assertTrue(in_array($langcode, $langcodes), format_string('%language is an enabled language.', array('%language' => $langcode)));
      }
    }
    $this->assertTrue(in_array('xx', $available_langcodes), format_string('%language was made available.', array('%language' => 'xx')));
    $this->assertFalse(in_array('en', $available_langcodes), format_string('%language was made unavailable.', array('%language' => 'en')));

    // Test field_available_languages() behavior for untranslatable fields.
    $this->field['translatable'] = FALSE;
    $this->field->save();
    $available_langcodes = field_available_languages($this->entity_type, $this->field);
    $this->assertTrue(count($available_langcodes) == 1 && $available_langcodes[0] === Language::LANGCODE_NOT_SPECIFIED, 'For untranslatable fields only Language::LANGCODE_NOT_SPECIFIED is available.');
  }

  /**
   * Test the multilanguage logic of _field_invoke().
   */
  function testFieldInvoke() {
    // Enable field translations for the entity.
    field_test_entity_info_translatable('test_entity', TRUE);

    $entity = field_test_create_entity(0, 0, $this->instance['bundle']);

    // Populate some extra languages to check if _field_invoke() correctly uses
    // the result of field_available_languages().
    $values = array();
    $extra_langcodes = mt_rand(1, 4);
    $langcodes = $available_langcodes = field_available_languages($this->entity_type, $this->field);
    for ($i = 0; $i < $extra_langcodes; ++$i) {
      $langcodes[] = $this->randomName(2);
    }

    // For each given language provide some random values.
    foreach ($langcodes as $langcode) {
      for ($delta = 0; $delta < $this->field['cardinality']; $delta++) {
        $values[$langcode][$delta]['value'] = mt_rand(1, 127);
      }
    }
    $entity->{$this->field_name} = $values;

    $results = _field_invoke('test_op', $entity);
    foreach ($results as $langcode => $result) {
      $hash = hash('sha256', serialize(array($entity, $this->field_name, $langcode, $values[$langcode])));
      // Check whether the parameters passed to _field_invoke() were correctly
      // forwarded to the callback function.
      $this->assertEqual($hash, $result, format_string('The result for %language is correctly stored.', array('%language' => $langcode)));
    }

    $this->assertEqual(count($results), count($available_langcodes), 'No unavailable language has been processed.');
  }

  /**
   * Test the multilanguage logic of _field_invoke_multiple().
   */
  function testFieldInvokeMultiple() {
    // Enable field translations for the entity.
    field_test_entity_info_translatable('test_entity', TRUE);

    $values = array();
    $options = array();
    $entities = array();
    $entity_type = 'test_entity';
    $entity_count = 5;
    $available_langcodes = field_available_languages($this->entity_type, $this->field);

    for ($id = 1; $id <= $entity_count; ++$id) {
      $entity = field_test_create_entity($id, $id, $this->instance['bundle']);
      $langcodes = $available_langcodes;

      // Populate some extra languages to check whether _field_invoke()
      // correctly uses the result of field_available_languages().
      $extra_langcodes = mt_rand(1, 4);
      for ($i = 0; $i < $extra_langcodes; ++$i) {
        $langcodes[] = $this->randomName(2);
      }

      // For each given language provide some random values.
      $language_count = count($langcodes);
      for ($i = 0; $i < $language_count; ++$i) {
        $langcode = $langcodes[$i];
        // Avoid to populate at least one field translation to check that
        // per-entity language suggestions work even when available field values
        // are different for each language.
        if ($i !== $id) {
          for ($delta = 0; $delta < $this->field['cardinality']; $delta++) {
            $values[$id][$langcode][$delta]['value'] = mt_rand(1, 127);
          }
        }
        // Ensure that a language for which there is no field translation is
        // used as display language to prepare per-entity language suggestions.
        elseif (!isset($display_langcode)) {
          $display_langcode = $langcode;
        }
      }

      $entity->{$this->field_name} = $values[$id];
      $entities[$id] = $entity;

      // Store per-entity language suggestions.
      $options['langcode'][$id] = field_language($entity, NULL, $display_langcode);
    }

    $grouped_results = _field_invoke_multiple('test_op_multiple', $entity_type, $entities);
    foreach ($grouped_results as $id => $results) {
      foreach ($results as $langcode => $result) {
        if (isset($values[$id][$langcode])) {
          $hash = hash('sha256', serialize(array($entity_type, $entities[$id], $this->field_name, $langcode, $values[$id][$langcode])));
          // Check whether the parameters passed to _field_invoke_multiple()
          // were correctly forwarded to the callback function.
          $this->assertEqual($hash, $result, format_string('The result for entity %id/%language is correctly stored.', array('%id' => $id, '%language' => $langcode)));
        }
      }
      $this->assertEqual(count($results), count($available_langcodes), format_string('No unavailable language has been processed for entity %id.', array('%id' => $id)));
    }

    $null = NULL;
    $grouped_results = _field_invoke_multiple('test_op_multiple', $entity_type, $entities, $null, $null, $options);
    foreach ($grouped_results as $id => $results) {
      foreach ($results as $langcode => $result) {
        $this->assertTrue(isset($options['langcode'][$id]), format_string('The result language code %langcode for entity %id was correctly suggested (display language: %display_langcode).', array('%id' => $id, '%langcode' => $langcode, '%display_langcode' => $display_langcode)));
      }
    }
  }

  /**
   * Test translatable fields storage/retrieval.
   */
  function testTranslatableFieldSaveLoad() {
    // Enable field translations for nodes.
    field_test_entity_info_translatable('node', TRUE);
    $entity_info = entity_get_info('node');
    $this->assertTrue(count($entity_info['translatable']), 'Nodes are translatable.');

    // Prepare the field translations.
    field_test_entity_info_translatable('test_entity', TRUE);
    $eid = $evid = 1;
    $entity_type = 'test_entity';
    $entity = field_test_create_entity($eid, $evid, $this->instance['bundle']);
    $field_translations = array();
    $available_langcodes = field_available_languages($entity_type, $this->field);
    $this->assertTrue(count($available_langcodes) > 1, 'Field is translatable.');
    foreach ($available_langcodes as $langcode) {
      $field_translations[$langcode] = $this->_generateTestFieldValues($this->field['cardinality']);
    }

    // Save and reload the field translations.
    $entity->{$this->field_name} = $field_translations;
    field_attach_insert($entity);
    unset($entity->{$this->field_name});
    field_attach_load($entity_type, array($eid => $entity));

    // Check if the correct values were saved/loaded.
    foreach ($field_translations as $langcode => $items) {
      $result = TRUE;
      foreach ($items as $delta => $item) {
        $result = $result && $item['value'] == $entity->{$this->field_name}[$langcode][$delta]['value'];
      }
      $this->assertTrue($result, format_string('%language translation correctly handled.', array('%language' => $langcode)));
    }

    // Test default values.
    $field_name_default = drupal_strtolower($this->randomName() . '_field_name');
    $field_definition = $this->field_definition;
    $field_definition['field_name'] = $field_name_default;
    entity_create('field_entity', $field_definition)->save();

    $instance_definition = $this->instance_definition;
    $instance_definition['field_name'] = $field_name_default;
    $instance_definition['default_value'] = array(array('value' => rand(1, 127)));
    $instance = entity_create('field_instance', $instance_definition);
    $instance->save();

    $translation_langcodes = array_slice($available_langcodes, 0, 2);
    asort($translation_langcodes);
    $translation_langcodes = array_values($translation_langcodes);

    $eid++;
    $evid++;
    $values = array('eid' => $eid, 'evid' => $evid, 'fttype' => $instance['bundle'], 'langcode' => $translation_langcodes[0]);
    foreach ($translation_langcodes as $langcode) {
      $values[$this->field_name][$langcode] = $this->_generateTestFieldValues($this->field['cardinality']);
    }
    $entity = entity_create($entity_type, $values);

    ksort($entity->{$field_name_default});
    $field_langcodes = array_keys($entity->{$field_name_default});
    $this->assertEqual($translation_langcodes, $field_langcodes, 'Missing translations did not get a default value.');

    foreach ($entity->{$field_name_default} as $langcode => $items) {
      $this->assertEqual($items, $instance['default_value'], format_string('Default value correctly populated for language %language.', array('%language' => $langcode)));
    }

    // Check that explicit empty values are not overridden with default values.
    foreach (array(NULL, array()) as $empty_items) {
      $eid++;
      $evid++;
      $values = array('eid' => $eid, 'evid' => $evid, 'fttype' => $instance['bundle'], 'langcode' => $translation_langcodes[0]);
      foreach ($translation_langcodes as $langcode) {
        $values[$this->field_name][$langcode] = $this->_generateTestFieldValues($this->field['cardinality']);
        $values[$field_name_default][$langcode] = $empty_items;
      }
      $entity = entity_create($entity_type, $values);

      foreach ($entity->{$field_name_default} as $langcode => $items) {
        $this->assertEqual($items, $empty_items, format_string('Empty value correctly populated for language %language.', array('%language' => $langcode)));
      }
    }
  }

  /**
   * Tests display language logic for translatable fields.
   */
  function testFieldDisplayLanguage() {
    $field_name = drupal_strtolower($this->randomName() . '_field_name');
    $entity_type = 'test_entity';

    // We need an additional field here to properly test display language
    // suggestions.
    $field = array(
      'field_name' => $field_name,
      'type' => 'test_field',
      'cardinality' => 2,
      'translatable' => TRUE,
    );
    entity_create('field_entity', $field)->save();

    $instance = array(
      'field_name' => $field['field_name'],
      'entity_type' => $entity_type,
      'bundle' => 'test_bundle',
    );
    entity_create('field_instance', $instance)->save();

    $entity = field_test_create_entity(1, 1, $this->instance['bundle']);
    $instances = field_info_instances($entity_type, $this->instance['bundle']);

    $enabled_langcodes = field_content_languages();
    $langcodes = array();
    // This array is used to store, for each field name, which one of the locked
    // languages will be used for display.
    $locked_languages = array();

    // Generate field translations for languages different from the first
    // enabled.
    foreach ($instances as $instance) {
      $field_name = $instance['field_name'];
      $field = field_info_field($field_name);
      do {
        // Index 0 is reserved for the requested language, this way we ensure
        // that no field is actually populated with it.
        $langcode = $enabled_langcodes[mt_rand(1, count($enabled_langcodes) - 1)];
      }
      while (isset($langcodes[$langcode]));
      $langcodes[$langcode] = TRUE;
      $entity->{$field_name}[$langcode] = $this->_generateTestFieldValues($field['cardinality']);
      // If the langcode is one of the locked languages, then that one
      // will also be used for display. Otherwise, the default one should be
      // used, which is Language::LANGCODE_NOT_SPECIFIED.
      if (language_is_locked($langcode)) {
        $locked_languages[$field_name] = $langcode;
      }
      else {
        $locked_languages[$field_name] = Language::LANGCODE_NOT_SPECIFIED;
      }
    }

    // Test multiple-fields display languages for untranslatable entities.
    field_test_entity_info_translatable($entity_type, FALSE);
    drupal_static_reset('field_language');
    $requested_langcode = $enabled_langcodes[0];
    $display_langcodes = field_language($entity, NULL, $requested_langcode);
    foreach ($instances as $instance) {
      $field_name = $instance['field_name'];
      $this->assertTrue($display_langcodes[$field_name] == $locked_languages[$field_name], format_string('The display language for field %field_name is %language.', array('%field_name' => $field_name, '%language' => $locked_languages[$field_name])));
    }

    // Test multiple-fields display languages for translatable entities.
    field_test_entity_info_translatable($entity_type, TRUE);
    drupal_static_reset('field_language');
    $display_langcodes = field_language($entity, NULL, $requested_langcode);
    foreach ($instances as $instance) {
      $field_name = $instance['field_name'];
      $langcode = $display_langcodes[$field_name];
      // As the requested language was not assinged to any field, if the
      // returned language is defined for the current field, core fallback rules
      // were successfully applied.
      $this->assertTrue(isset($entity->{$field_name}[$langcode]) && $langcode != $requested_langcode, format_string('The display language for the field %field_name is %language.', array('%field_name' => $field_name, '%language' => $langcode)));
    }

    // Test single-field display language.
    drupal_static_reset('field_language');
    $langcode = field_language($entity, $this->field_name, $requested_langcode);
    $this->assertTrue(isset($entity->{$this->field_name}[$langcode]) && $langcode != $requested_langcode, format_string('The display language for the (single) field %field_name is %language.', array('%field_name' => $field_name, '%language' => $langcode)));

    // Test field_language() basic behavior without language fallback.
    \Drupal::state()->set('field_test.language_fallback', FALSE);
    $entity->{$this->field_name}[$requested_langcode] = mt_rand(1, 127);
    drupal_static_reset('field_language');
    $display_langcode = field_language($entity, $this->field_name, $requested_langcode);
    $this->assertEqual($display_langcode, $requested_langcode, 'Display language behave correctly when language fallback is disabled');
  }

}
