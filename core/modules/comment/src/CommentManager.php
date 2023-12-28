<?php

/**
 * @file
 * Contains \Drupal\comment\CommentManager.
 */

namespace Drupal\comment;

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Routing\UrlGeneratorInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldInstanceConfig;

/**
 * Comment manager contains common functions to manage comment fields.
 */
class CommentManager implements CommentManagerInterface {
  use StringTranslationTrait;

  /**
   * The entity manager service.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * Whether the DRUPAL_AUTHENTICATED_RID can post comments.
   *
   * @var bool
   */
  protected $authenticatedCanPostComments;

  /**
   * The user settings config object.
   *
   * @var \Drupal\Core\Config\Config
   */
  protected $userConfig;

  /**
   * The url generator service.
   *
   * @var \Drupal\Core\Routing\UrlGeneratorInterface
   */
  protected $urlGenerator;

  /**
   * Construct the CommentManager object.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\StringTranslation\TranslationInterface $string_translation
   *   The string translation service.
   * @param \Drupal\Core\Routing\UrlGeneratorInterface $url_generator
   *   The url generator service.
   */
  public function __construct(EntityManagerInterface $entity_manager, ConfigFactoryInterface $config_factory, TranslationInterface $string_translation, UrlGeneratorInterface $url_generator) {
    $this->entityManager = $entity_manager;
    $this->userConfig = $config_factory->get('user.settings');
    $this->stringTranslation = $string_translation;
    $this->urlGenerator = $url_generator;
  }

  /**
   * {@inheritdoc}
   */
  public function getFields($entity_type_id) {
    $entity_type = $this->entityManager->getDefinition($entity_type_id);
    if (!$entity_type->isSubclassOf('\Drupal\Core\Entity\ContentEntityInterface')) {
      return array();
    }

    $map = $this->getAllFields();
    return isset($map[$entity_type_id]) ? $map[$entity_type_id] : array();
  }

  /**
   * {@inheritdoc}
   */
  public function getAllFields() {
    $map = $this->entityManager->getFieldMap();
    // Build a list of comment fields only.
    $comment_fields = array();
    foreach ($map as $entity_type => $data) {
      foreach ($data as $field_name => $field_info) {
        if ($field_info['type'] == 'comment') {
          $comment_fields[$entity_type][$field_name] = $field_info;
        }
      }
    }
    return $comment_fields;
  }

  /**
   * {@inheritdoc}
   */
  public function addDefaultField($entity_type, $bundle, $field_name = 'comment', $default_value = CommentItemInterface::OPEN, $comment_type_id = 'comment') {
    $comment_type_storage = $this->entityManager->getStorage('comment_type');
    if ($comment_type = $comment_type_storage->load($comment_type_id)) {
      if ($comment_type->getTargetEntityTypeId() !== $entity_type) {
        throw new \InvalidArgumentException(String::format('The given comment type id %id can only be used with the %entity_type entity type', array(
          '%id' => $comment_type_id,
          '%entity_type' => $entity_type,
        )));
      }
    }
    else {
      // Silently create the comment-type for the calling code.
      $comment_type_storage->create(array(
        'id' => $comment_type_id,
        'label' => Unicode::ucfirst($comment_type_id),
        'target_entity_type_id' => $entity_type,
        'description' => 'Default comment field',
      ))->save();
    }
    // Make sure the field doesn't already exist.
    if (!FieldConfig::loadByName($entity_type, $field_name)) {
      // Add a default comment field for existing node comments.
      $field = $this->entityManager->getStorage('field_config')->create(array(
        'entity_type' => $entity_type,
        'name' => $field_name,
        'type' => 'comment',
        'translatable' => '0',
        'settings' => array(
          'comment_type' => $comment_type_id,
        ),
      ));
      // Create the field.
      $field->save();
    }
    // Make sure the instance doesn't already exist.
    if (!array_key_exists($field_name, $this->entityManager->getFieldDefinitions($entity_type, $bundle))) {
      $instance = $this->entityManager->getStorage('field_instance_config')->create(array(
        'label' => 'Comment settings',
        'description' => '',
        'field_name' => $field_name,
        'entity_type' => $entity_type,
        'bundle' => $bundle,
        'required' => 1,
        'default_value' => array(
          array(
            'status' => $default_value,
            'cid' => 0,
            'last_comment_name' => '',
            'last_comment_timestamp' => 0,
            'last_comment_uid' => 0,
          ),
        ),
      ));
      $instance->save();

      // Assign widget settings for the 'default' form mode.
      entity_get_form_display($entity_type, $bundle, 'default')
        ->setComponent($field_name, array(
          'type' => 'comment_default',
          'weight' => 20,
        ))
        ->save();

      // The comment field should be hidden in all other form displays.
      foreach ($this->entityManager->getFormModes($entity_type) as $id => $form_mode) {
        $display = entity_get_form_display($entity_type, $bundle, $id);
        // Only update existing displays.
        if ($display && !$display->isNew()) {
          $display->removeComponent($field_name)->save();
        }
      }
      // Set default to display comment list.
      entity_get_display($entity_type, $bundle, 'default')
        ->setComponent($field_name, array(
          'label' => 'hidden',
          'type' => 'comment_default',
          'weight' => 20,
        ))
        ->save();
      // The comment field should be hidden in all other view displays.
      foreach ($this->entityManager->getViewModes($entity_type) as $id => $view_mode) {
        $display = entity_get_display($entity_type, $bundle, $id);
        // Only update existing displays.
        if ($display && !$display->isNew()) {
          $display->removeComponent($field_name)->save();
        }
      }

    }
    $this->addBodyField($comment_type_id);
  }

  /**
   * {@inheritdoc}
   */
  public function addBodyField($comment_type_id) {
    // Create the field if needed.
    $field = FieldConfig::loadByName('comment', 'comment_body');
    if (!$field) {
      $field = $this->entityManager->getStorage('field_config')->create(array(
        'name' => 'comment_body',
        'type' => 'text_long',
        'entity_type' => 'comment',
      ));
      $field->save();
    }
    if (!FieldInstanceConfig::loadByName('comment', $comment_type_id, 'comment_body')) {
      // Attaches the body field by default.
      $field_instance = $this->entityManager->getStorage('field_instance_config')->create(array(
        'field_name' => 'comment_body',
        'label' => 'Comment',
        'entity_type' => 'comment',
        'bundle' => $comment_type_id,
        'settings' => array('text_processing' => 1),
        'required' => TRUE,
      ));
      $field_instance->save();

      // Assign widget settings for the 'default' form mode.
      entity_get_form_display('comment', $comment_type_id, 'default')
        ->setComponent('comment_body', array(
          'type' => 'text_textarea',
        ))
        ->save();

      // Assign display settings for the 'default' view mode.
      entity_get_display('comment', $comment_type_id, 'default')
        ->setComponent('comment_body', array(
          'label' => 'hidden',
          'type' => 'text_default',
          'weight' => 0,
        ))
        ->save();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function forbiddenMessage(EntityInterface $entity, $field_name) {
    if (!isset($this->authenticatedCanPostComments)) {
      // We only output a link if we are certain that users will get the
      // permission to post comments by logging in.
      $this->authenticatedCanPostComments = $this->entityManager
        ->getStorage('user_role')
        ->load(DRUPAL_AUTHENTICATED_RID)
        ->hasPermission('post comments');
    }

    if ($this->authenticatedCanPostComments) {
      // We cannot use drupal_get_destination() because these links
      // sometimes appear on /node and taxonomy listing pages.
      if ($entity->get($field_name)->getFieldDefinition()->getSetting('form_location') == COMMENT_FORM_SEPARATE_PAGE) {
        $destination = array('destination' => 'comment/reply/' . $entity->getEntityTypeId() . '/' . $entity->id() . '/' . $field_name . '#comment-form');
      }
      else {
        $destination = array('destination' => $entity->getSystemPath() . '#comment-form');
      }

      if ($this->userConfig->get('register') != USER_REGISTER_ADMINISTRATORS_ONLY) {
        // Users can register themselves.
        return $this->t('<a href="@login">Log in</a> or <a href="@register">register</a> to post comments', array(
          '@login' => $this->urlGenerator->generateFromRoute('user.login', array(), array('query' => $destination)),
          '@register' => $this->urlGenerator->generateFromRoute('user.register', array(), array('query' => $destination)),
        ));
      }
      else {
        // Only admins can add new users, no public registration.
        return $this->t('<a href="@login">Log in</a> to post comments', array(
          '@login' => $this->urlGenerator->generateFromRoute('user.login', array(), array('query' => $destination)),
        ));
      }
    }
    return '';
  }

}
