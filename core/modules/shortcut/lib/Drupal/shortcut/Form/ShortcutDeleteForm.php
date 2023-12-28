<?php

/**
 * @file
 * Contains \Drupal\shortcut\Form\ShortcutDeleteForm.
 */

namespace Drupal\shortcut\Form;

use Drupal\Core\Entity\EntityConfirmFormBase;
use Drupal\Core\Entity\EntityControllerInterface;
use Drupal\shortcut\ShortcutStorageControllerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the shortcut set deletion form.
 */
class ShortcutDeleteForm extends EntityConfirmFormBase implements EntityControllerInterface {

  /**
   * The database connection.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $database;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The shortcut storage controller.
   *
   * @var \Drupal\shortcut\ShortcutStorageControllerInterface
   */
  protected $storageController;

  /**
   * Constructs a ShortcutDeleteForm object.
   */
  public function __construct(Connection $database, ModuleHandlerInterface $module_handler, ShortcutStorageControllerInterface $storage_controller) {
    $this->database = $database;
    $this->moduleHandler = $module_handler;
    $this->storageController = $storage_controller;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, $entity_type, array $entity_info) {
    return new static(
      $container->get('database'),
      $container->get('module_handler'),
      $container->get('plugin.manager.entity')->getStorageController('shortcut')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return t('Are you sure you want to delete the shortcut set %title?', array('%title' => $this->entity->label()));
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelPath() {
    return 'admin/config/user-interface/shortcut/manage/' . $this->entity->id();
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return t('Delete');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, array &$form_state, Request $request = NULL) {
    // Find out how many users are directly assigned to this shortcut set, and
    // make a message.
    $number = $this->storageController->countAssignedUsers($this->entity);
    $info = '';
    if ($number) {
      $info .= '<p>' . format_plural($number,
        '1 user has chosen or been assigned to this shortcut set.',
        '@count users have chosen or been assigned to this shortcut set.') . '</p>';
    }

    // Also, if a module implements hook_shortcut_default_set(), it's possible
    // that this set is being used as a default set. Add a message about that too.
    if ($this->moduleHandler->getImplementations('shortcut_default_set')) {
      $info .= '<p>' . t('If you have chosen this shortcut set as the default for some or all users, they may also be affected by deleting it.') . '</p>';
    }

    $form['info'] = array(
      '#markup' => $info,
    );

    return parent::buildForm($form, $form_state, $request);
  }

  /**
   * {@inheritdoc}
   */
  public function submit(array $form, array &$form_state) {
    $this->entity->delete();
    $form_state['redirect'] = 'admin/config/user-interface/shortcut';
    drupal_set_message(t('The shortcut set %title has been deleted.', array('%title' => $this->entity->label())));
  }

}
