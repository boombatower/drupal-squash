<?php

/**
 * @file
 * Contains \Drupal\node\NodeTranslationHandler.
 */

namespace Drupal\node;

use Drupal\Core\Entity\EntityInterface;
use Drupal\content_translation\ContentTranslationHandler;

/**
 * Defines the translation handler for nodes.
 */
class NodeTranslationHandler extends ContentTranslationHandler {

  /**
   * {@inheritdoc}
   */
  public function entityFormAlter(array &$form, array &$form_state, EntityInterface $entity) {
    parent::entityFormAlter($form, $form_state, $entity);

    // Move the translation fieldset to a vertical tab.
    if (isset($form['content_translation'])) {
      $form['content_translation'] += array(
        '#group' => 'advanced',
        '#attributes' => array(
          'class' => array('node-translation-options'),
        ),
      );

      $form['content_translation']['#weight'] = 100;

      // We do not need to show these values on node forms: they inherit the
      // basic node property values.
      $form['content_translation']['status']['#access'] = FALSE;
      $form['content_translation']['name']['#access'] = FALSE;
      $form['content_translation']['created']['#access'] = FALSE;
    }

    $form_controller = content_translation_form_controller($form_state);
    $form_langcode = $form_controller->getFormLangcode($form_state);
    $translations = $entity->getTranslationLanguages();
    $status_translatable = NULL;
    // Change the submit button labels if there was a status field they affect
    // in which case their publishing / unpublishing may or may not apply
    // to all translations.
    if (!$entity->isNew() && (!isset($translations[$form_langcode]) || count($translations) > 1)) {
      foreach ($entity->getFieldDefinitions() as $property_name => $definition) {
        if ($property_name == 'status') {
          $status_translatable = $definition->isTranslatable();
        }
      }
      if (isset($status_translatable)) {
        foreach (array('publish', 'unpublish', 'submit') as $button) {
          if (isset($form['actions'][$button])) {
            $form['actions'][$button]['#value'] .= ' ' . ($status_translatable ? t('(this translation)') : t('(all translations)'));
          }
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function entityFormTitle(EntityInterface $entity) {
    $type_name = node_get_type_label($entity);
    return t('<em>Edit @type</em> @title', array('@type' => $type_name, '@title' => $entity->label()));
  }

  /**
   * {@inheritdoc}
   */
  public function entityFormEntityBuild($entity_type, EntityInterface $entity, array $form, array &$form_state) {
    if (isset($form_state['values']['content_translation'])) {
      $form_controller = content_translation_form_controller($form_state);
      $translation = &$form_state['values']['content_translation'];
      $translation['status'] = $form_controller->getEntity()->isPublished();
      // $form['content_translation']['name'] is the equivalent field
      // for translation author uid.
      $translation['name'] = $form_state['values']['uid'];
      $translation['created'] = $form_state['values']['created'];
    }
    parent::entityFormEntityBuild($entity_type, $entity, $form, $form_state);
  }

}
