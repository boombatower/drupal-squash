<?php

/**
 * @file
 * Contains \Drupal\shortcut\ShortcutFormController.
 */

namespace Drupal\shortcut;

use Drupal\Core\Entity\ContentEntityFormController;
use Drupal\Core\Language\Language;

/**
 * Form controller for the shortcut entity forms.
 */
class ShortcutFormController extends ContentEntityFormController {

  /**
   * The entity being used by this form.
   *
   * @var \Drupal\shortcut\ShortcutInterface
   */
  protected $entity;

  /**
   * {@inheritdoc}
   */
  public function form(array $form, array &$form_state) {
    $form = parent::form($form, $form_state);

    $form['title'] = array(
      '#type' => 'textfield',
      '#title' => t('Name'),
      '#default_value' => $this->entity->getTitle(),
      '#size' => 40,
      '#maxlength' => 255,
      '#required' => TRUE,
      '#weight' => -10,
    );

    $form['path'] = array(
      '#type' => 'textfield',
      '#title' => t('Path'),
      '#size' => 40,
      '#maxlength' => 255,
      '#field_prefix' => $this->url('<front>', array(), array('absolute' => TRUE)),
      '#default_value' => $this->entity->path->value,
    );

    $form['langcode'] = array(
      '#title' => t('Language'),
      '#type' => 'language_select',
      '#default_value' => $this->entity->getUntranslated()->language()->id,
      '#languages' => Language::STATE_ALL,
    );

    $form['shortcut_set'] = array(
      '#type' => 'value',
      '#value' => $this->entity->bundle(),
    );
    $form['route_name'] = array(
      '#type' => 'value',
      '#value' => $this->entity->getRouteName(),
    );
    $form['route_parameters'] = array(
      '#type' => 'value',
      '#value' => $this->entity->getRouteParams(),
    );

    return $form;
  }

  /**
   * Overrides EntityFormController::buildEntity().
   */
  public function buildEntity(array $form, array &$form_state) {
    $entity = parent::buildEntity($form, $form_state);

    // Set the computed 'path' value so it can used in the preSave() method to
    // derive the route name and parameters.
    $entity->path->value = $form_state['values']['path'];

    return $entity;
  }

  /**
   * {@inheritdoc}
   */
  public function validate(array $form, array &$form_state) {
    if (!shortcut_valid_link($form_state['values']['path'])) {
      $this->setFormError('path', $form_state, $this->t('The shortcut must correspond to a valid path on the site.'));
    }

    parent::validate($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function save(array $form, array &$form_state) {
    $entity = $this->entity;
    $entity->save();

    if ($entity->isNew()) {
      $message = $this->t('The shortcut %link has been updated.', array('%link' => $entity->getTitle()));
    }
    else {
      $message = $this->t('Added a shortcut for %title.', array('%title' => $entity->getTitle()));
    }
    drupal_set_message($message);

    $form_state['redirect_route'] = array(
      'route_name' => 'shortcut.set_customize',
      'route_parameters' => array('shortcut_set' => $entity->bundle()),
    );
  }

}
