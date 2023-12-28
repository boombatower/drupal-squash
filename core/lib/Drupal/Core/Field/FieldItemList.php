<?php

/**
 * @file
 * Contains \Drupal\Core\Field\FieldItemList.
 */

namespace Drupal\Core\Field;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\TypedDataInterface;
use Drupal\Core\TypedData\Plugin\DataType\ItemList;
use Drupal\Core\Language\Language;

/**
 * Represents an entity field; that is, a list of field item objects.
 *
 * An entity field is a list of field items, each containing a set of
 * properties. Note that even single-valued entity fields are represented as
 * list of field items, however for easy access to the contained item the entity
 * field delegates __get() and __set() calls directly to the first item.
 */
class FieldItemList extends ItemList implements FieldItemListInterface {

  /**
   * Numerically indexed array of field items, implementing the
   * FieldItemInterface.
   *
   * @var array
   */
  protected $list = array();

  /**
   * The langcode of the field values held in the object.
   *
   * @var string
   */
  protected $langcode = Language::LANGCODE_NOT_SPECIFIED;

  /**
   * {@inheritdoc}
   */
  public function __construct(DataDefinitionInterface $definition, $name = NULL, TypedDataInterface $parent = NULL) {
    parent::__construct($definition, $name, $parent);
    // Always initialize one empty item as most times a value for at least one
    // item will be present. That way prototypes created by
    // \Drupal\Core\TypedData\TypedDataManager::getPropertyInstance() will
    // already have this field item ready for use after cloning.
    $this->list[0] = $this->createItem(0);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntity() {
    return $this->getParent();
  }

  /**
   * {@inheritdoc}
   */
  public function setLangcode($langcode) {
    $this->langcode = $langcode;
  }

  /**
   * {@inheritdoc}
   */
  public function getLangcode() {
    return $this->langcode;
  }

  /**
   * {@inheritdoc}
   */
  public function getFieldDefinition() {
    return $this->definition;
  }

  /**
   * {@inheritdoc}
   */
  public function getSettings() {
    return $this->definition->getSettings();
  }

  /**
   * {@inheritdoc}
   */
  public function getSetting($setting_name) {
    return $this->definition->getSetting($setting_name);
  }

  /**
   * {@inheritdoc}
   */
  public function filterEmptyItems() {
    if (isset($this->list)) {
      $this->list = array_values(array_filter($this->list, function($item) {
        return !$item->isEmpty();
      }));
    }
  }

  /**
   * {@inheritdoc}
   * @todo Revisit the need when all entity types are converted to NG entities.
   */
  public function getValue($include_computed = FALSE) {
    if (isset($this->list)) {
      $values = array();
      foreach ($this->list as $delta => $item) {
        $values[$delta] = $item->getValue($include_computed);
      }
      return $values;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($values, $notify = TRUE) {
    if (!isset($values) || $values === array()) {
      $this->list = $values;
    }
    else {
      // Support passing in only the value of the first item.
      if (!is_array($values) || !is_numeric(current(array_keys($values)))) {
        $values = array(0 => $values);
      }

      // Clear the values of properties for which no value has been passed.
      if (isset($this->list)) {
        $this->list = array_intersect_key($this->list, $values);
      }

      // Set the values.
      foreach ($values as $delta => $value) {
        if (!is_numeric($delta)) {
          throw new \InvalidArgumentException('Unable to set a value with a non-numeric delta in a list.');
        }
        elseif (!isset($this->list[$delta])) {
          $this->list[$delta] = $this->createItem($delta, $value);
        }
        else {
          $this->list[$delta]->setValue($value, FALSE);
        }
      }
    }
    // Notify the parent of any changes.
    if ($notify && isset($this->parent)) {
      $this->parent->onChange($this->name);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function __get($property_name) {
    return $this->first()->__get($property_name);
  }

  /**
   * {@inheritdoc}
   */
  public function __set($property_name, $value) {
    $this->first()->__set($property_name, $value);
  }

  /**
   * {@inheritdoc}
   */
  public function __isset($property_name) {
    return $this->first()->__isset($property_name);
  }

  /**
   * {@inheritdoc}
   */
  public function __unset($property_name) {
    return $this->first()->__unset($property_name);
  }

  /**
   * {@inheritdoc}
   */
  public function access($operation = 'view', AccountInterface $account = NULL) {
    $access_controller = \Drupal::entityManager()->getAccessController($this->getEntity()->getEntityTypeId());
    return $access_controller->fieldAccess($operation, $this->getFieldDefinition(), $account, $this);
  }

  /**
   * {@inheritdoc}
   */
  public function defaultAccess($operation = 'view', AccountInterface $account = NULL) {
    // Grant access per default.
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public function applyDefaultValue($notify = TRUE) {
    $value = $this->getDefaultValue();

    // NULL or array() mean "no default value", but  0, '0' and the empty string
    // are valid default values.
    if (!isset($value) || (is_array($value) && empty($value))) {
      // Create one field item and apply defaults.
      $this->first()->applyDefaultValue(FALSE);
    }
    else {
      $this->setValue($value, $notify);
    }
    return $this;
  }

  /**
   * Returns the default value for the field.
   *
   * @return array
   *   The default value for the field.
   */
  protected function getDefaultValue() {
    return $this->getFieldDefinition()->getDefaultValue($this->getEntity());
  }

  /**
   * {@inheritdoc}
   */
  public function preSave() {
    // Filter out empty items.
    $this->filterEmptyItems();

    $this->delegateMethod('presave');
  }

  /**
   * {@inheritdoc}
   */
  public function insert() {
    $this->delegateMethod('insert');
  }

  /**
   * {@inheritdoc}
   */
  public function update() {
    $this->delegateMethod('update');
  }

  /**
   * {@inheritdoc}
   */
  public function delete() {
    $this->delegateMethod('delete');
  }

  /**
   * {@inheritdoc}
   */
  public function deleteRevision() {
    $this->delegateMethod('deleteRevision');
  }

  /**
   * Calls a method on each FieldItem.
   *
   * @param string $method
   *   The name of the method.
   */
  protected function delegateMethod($method) {
    if (isset($this->list)) {
      foreach ($this->list as $item) {
        $item->{$method}();
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function view($display_options = array()) {
    $view_builder = \Drupal::entityManager()->getViewBuilder($this->getEntity()->getEntityTypeId());
    return $view_builder->viewField($this, $display_options);
  }

  /**
   * {@inheritdoc}
   */
  public function getConstraints() {
    $constraints = parent::getConstraints();
    // Check that the number of values doesn't exceed the field cardinality. For
    // form submitted values, this can only happen with 'multiple value'
    // widgets.
    $cardinality = $this->getFieldDefinition()->getCardinality();
    if ($cardinality != FieldDefinitionInterface::CARDINALITY_UNLIMITED) {
      $constraints[] = \Drupal::typedDataManager()
        ->getValidationConstraintManager()
        ->create('Count', array(
          'max' => $cardinality,
          'maxMessage' => t('%name: this field cannot hold more than @count values.', array('%name' => $this->getFieldDefinition()->getLabel(), '@count' => $cardinality)),
        ));
    }

    return $constraints;
  }

  /**
   * {@inheritdoc}
   */
  public function defaultValuesForm(array &$form, array &$form_state) {
    if (empty($this->getFieldDefinition()->default_value_function)) {
      // Place the input in a separate place in the submitted values tree.
      $widget = $this->defaultValueWidget($form_state);

      $element = array('#parents' => array('default_value_input'));
      $element += $widget->form($this, $element, $form_state);

      return $element;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultValuesFormValidate(array $element, array &$form, array &$form_state) {
    // Extract the submitted value, and validate it.
    $widget = $this->defaultValueWidget($form_state);
    $widget->extractFormValues($this, $element, $form_state);
    $violations = $this->validate();

    if (count($violations)) {
      // Store reported errors in $form_state.
      $field_name = $this->getFieldDefinition()->getName();
      $field_state = field_form_get_state($element['#parents'], $field_name, $form_state);
      $field_state['constraint_violations'] = $violations;
      field_form_set_state($element['#parents'], $field_name, $form_state, $field_state);

      // Assign reported errors to the correct form element.
      $widget->flagErrors($this, $element, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function defaultValuesFormSubmit(array $element, array &$form, array &$form_state) {
    // Extract the submitted value, and return it as an array.
    $widget = $this->defaultValueWidget($form_state);
    $widget->extractFormValues($this, $element, $form_state);
    return $this->getValue();
  }

  /**
   * Returns the widget object used in default value form.
   *
   * @param array $form_state
   *   The form state of the (entire) configuration form.
   *
   * @return \Drupal\Core\Field\WidgetInterface
   *   A Widget object.
   */
  protected function defaultValueWidget(array &$form_state) {
    if (!isset($form_state['default_value_widget'])) {
      $entity = $this->getEntity();

      // Force a non-required widget.
      $this->getFieldDefinition()->required = FALSE;
      $this->getFieldDefinition()->description = '';

      // Use the widget currently configured for the 'default' form mode, or
      // fallback to the default widget for the field type.
      $entity_form_display = entity_get_form_display($entity->getEntityTypeId(), $entity->bundle(), 'default');
      $widget = $entity_form_display->getRenderer($this->getFieldDefinition()->getName());
      if (!$widget) {
        $widget = \Drupal::service('plugin.manager.field.widget')->getInstance(array('field_definition' => $this->getFieldDefinition()));
      }

      $form_state['default_value_widget'] = $widget;
    }

    return $form_state['default_value_widget'];
  }

}
