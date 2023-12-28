<?php

/**
 * @file
 * Contains \Drupal\image\Plugin\field\widget\ImageWidget.
 */

namespace Drupal\image\Plugin\field\widget;

use Drupal\Component\Annotation\Plugin;
use Drupal\Core\Annotation\Translation;
use Drupal\field\Plugin\Type\Widget\WidgetBase;
use Drupal\file\Plugin\field\widget\FileWidget;
use Drupal\Core\Entity\EntityInterface;

/**
 * Plugin implementation of the 'image_image' widget.
 *
 * @Plugin(
 *   id = "image_image",
 *   module = "image",
 *   label = @Translation("Image"),
 *   field_types = {
 *     "image"
 *   },
 *   settings = {
 *     "progress_indicator" = "throbber",
 *     "preview_image_style" = "thumbnail",
 *   },
 *   default_value = FALSE
 * )
 */
class ImageWidget extends FileWidget {

  /**
   * Overrides \Drupal\file\Plugin\field\widget\FileWidget::settingsForm().
   */
  public function settingsForm(array $form, array &$form_state) {
    $element = parent::settingsForm($form, $form_state);

    $element['preview_image_style'] = array(
      '#title' => t('Preview image style'),
      '#type' => 'select',
      '#options' => image_style_options(FALSE),
      '#empty_option' => '<' . t('no preview') . '>',
      '#default_value' => $this->getSetting('preview_image_style'),
      '#description' => t('The preview image will be shown while editing the content.'),
      '#weight' => 15,
    );

    return $element;
  }

  /**
   * {@inheritdoc}
   *
   * Special handling for draggable multiple widgets and 'add more' button.
   */
  protected function formMultipleElements(EntityInterface $entity, array $items, $langcode, array &$form, array &$form_state) {
    $elements = parent::formMultipleElements($entity, $items, $langcode, $form, $form_state);

    $cardinality = $this->fieldDefinition->getFieldCardinality();
    if ($cardinality == 1) {
      // If there's only one field, return it as delta 0.
      if (empty($elements[0]['#default_value']['fids'])) {
        $elements[0]['#description'] = theme('file_upload_help', array('description' => $this->fieldDefinition->getFieldDescription(), 'upload_validators' => $elements[0]['#upload_validators'], 'cardinality' => $cardinality));
      }
    }
    else {
      $elements['#file_upload_description'] = theme('file_upload_help', array('upload_validators' => $elements[0]['#upload_validators'], 'cardinality' => $cardinality));
    }

    return $elements;
  }

  /**
   * {@inheritdoc}
   */
  public function formElement(array $items, $delta, array $element, $langcode, array &$form, array &$form_state) {
    $element = parent::formElement($items, $delta, $element, $langcode, $form, $form_state);

    $field_settings = $this->getFieldSettings();

    // Add upload resolution validation.
    if ($field_settings['max_resolution'] || $field_settings['min_resolution']) {
      $element['#upload_validators']['file_validate_image_resolution'] = array($field_settings['max_resolution'], $field_settings['min_resolution']);
    }

    // If not using custom extension validation, ensure this is an image.
    $supported_extensions = array('png', 'gif', 'jpg', 'jpeg');
    $extensions = isset($element['#upload_validators']['file_validate_extensions'][0]) ? $element['#upload_validators']['file_validate_extensions'][0] : implode(' ', $supported_extensions);
    $extensions = array_intersect(explode(' ', $extensions), $supported_extensions);
    $element['#upload_validators']['file_validate_extensions'][0] = implode(' ', $extensions);

    // Add all extra functionality provided by the image widget.
    $element['#process'][] = 'image_field_widget_process';
    // Add properties needed by image_field_widget_process().
    $element['#preview_image_style'] = $this->getSetting('preview_image_style');
    $element['#title_field'] = $field_settings['title_field'];
    $element['#alt_field'] = $field_settings['alt_field'];
    $element['#alt_field_required'] = $field_settings['alt_field_required'];

    return $element;
  }

}
