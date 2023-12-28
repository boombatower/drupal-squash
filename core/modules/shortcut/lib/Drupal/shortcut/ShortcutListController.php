<?php

/**
 * Contains \Drupal\shortcut\ShortcutListController.
 */

namespace Drupal\shortcut;

use Drupal\Core\Config\Entity\ConfigEntityListController;
use Drupal\Core\Entity\EntityInterface;

/**
 * Provides a listing of shortcut sets.
 */
class ShortcutListController extends ConfigEntityListController {

  /**
   * Overrides \Drupal\Core\Entity\EntityListController::buildHeader().
   */
  public function buildHeader() {
    $row['label'] = t('Name');
    $row['operations'] = t('Operations');
    return $row;
  }

  /**
   * Overrides \Drupal\Core\Entity\EntityListController::getOperations().
   */
  public function getOperations(EntityInterface $entity) {
    $operations = parent::getOperations($entity);
    $uri = $entity->uri();

    $operations['edit']['title'] = t('Edit menu');
    $operations['edit']['href'] = $uri['path'] . '/edit';

    $operations['list'] = array(
      'title' => t('List links'),
      'href' => $uri['path'],
    );
    if (!$entity->access('delete')) {
      unset($operations['delete']);
    }
    return $operations;
  }

  /**
   * Overrides \Drupal\Core\Entity\EntityListController::buildRow().
   */
  public function buildRow(EntityInterface $entity) {
    $row['name'] = check_plain($entity->label());
    $row['operations']['data'] = $this->buildOperations($entity);
    return $row;
  }

}
