<?php

/**
 * @file
 * Contains \Drupal\language\LanguageAccessControlHandler.
 */

namespace Drupal\language;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines the access control handler for the language entity type.
 *
 * @see \Drupal\language\Entity\Language
 */
class LanguageAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   */
  public function checkAccess(EntityInterface $entity, $operation, $langcode, AccountInterface $account) {
    switch ($operation) {
      case 'update':
      case 'delete':
        return AccessResult::allowedIf(!$entity->locked)->cacheUntilEntityChanges($entity)
          ->andIf(parent::checkAccess($entity, $operation, $langcode, $account));

      default:
        // No opinion.
        return AccessResult::create();
    }
  }

}
