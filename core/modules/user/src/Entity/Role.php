<?php

/**
 * @file
 * Contains Drupal\user\Entity\Role.
 */

namespace Drupal\user\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBase;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\user\RoleInterface;

/**
 * Defines the user role entity class.
 *
 * @ConfigEntityType(
 *   id = "user_role",
 *   label = @Translation("Role"),
 *   controllers = {
 *     "storage" = "Drupal\user\RoleStorage",
 *     "access" = "Drupal\user\RoleAccessController",
 *     "list_builder" = "Drupal\user\RoleListBuilder",
 *     "form" = {
 *       "default" = "Drupal\user\RoleForm",
 *       "delete" = "Drupal\user\Form\UserRoleDelete"
 *     }
 *   },
 *   admin_permission = "administer permissions",
 *   config_prefix = "role",
 *   entity_keys = {
 *     "id" = "id",
 *     "weight" = "weight",
 *     "label" = "label"
 *   },
 *   links = {
 *     "delete-form" = "user.role_delete",
 *     "edit-form" = "user.role_edit",
 *     "edit-permissions-form" = "user.admin_permission"
 *   }
 * )
 */
class Role extends ConfigEntityBase implements RoleInterface {

  /**
   * The machine name of this role.
   *
   * @var string
   */
  public $id;

  /**
   * The human-readable label of this role.
   *
   * @var string
   */
  public $label;

  /**
   * The weight of this role in administrative listings.
   *
   * @var int
   */
  public $weight;

  /**
   * The permissions belonging to this role.
   *
   * @var array
   */
  public $permissions = array();

  /**
   * {@inheritdoc}
   */
  public function getPermissions() {
    return $this->permissions;
  }

  /**
   * {@inheritdoc}
   */
  public function hasPermission($permission) {
    return in_array($permission, $this->permissions);
  }

  /**
   * {@inheritdoc}
   */
  public function grantPermission($permission) {
    if (!$this->hasPermission($permission)) {
      $this->permissions[] = $permission;
    }
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function revokePermission($permission) {
    $this->permissions = array_diff($this->permissions, array($permission));
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public static function postLoad(EntityStorageInterface $storage, array &$entities) {
    parent::postLoad($storage, $entities);
    // Sort the queried roles by their weight.
    // See \Drupal\Core\Config\Entity\ConfigEntityBase::sort().
    uasort($entities, 'static::sort');
  }

  /**
   * {@inheritdoc}
   */
  public function preSave(EntityStorageInterface $storage) {
    parent::preSave($storage);

    if (!isset($this->weight) && ($roles = $storage->loadMultiple())) {
      // Set a role weight to make this new role last.
      $max = array_reduce($roles, function($max, $role) {
        return $max > $role->weight ? $max : $role->weight;
      });
      $this->weight = $max + 1;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function postSave(EntityStorageInterface $storage, $update = TRUE) {
    parent::postSave($storage, $update);

    // Clear render cache.
    entity_render_cache_clear();
  }

}
