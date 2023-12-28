<?php

/**
 * @file
 * Contains \Drupal\aggregator\FeedAccessController.
 */

namespace Drupal\aggregator;

use Drupal\Core\Entity\EntityAccessController;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Defines an access controller for the feed entity.
 *
 * @see \Drupal\aggregator\Entity\Feed
 */
class FeedAccessController extends EntityAccessController {

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, $langcode, AccountInterface $account) {
    switch ($operation) {
      case 'view':
        return $account->hasPermission('access news feeds');
        break;

      default:
        return $account->hasPermission('administer news feeds');
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    return $account->hasPermission('administer news feeds');
  }

}
