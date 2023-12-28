<?php

/**
 * @file
 * Contains \Drupal\path\Controller\PathController.
 */

namespace Drupal\path\Controller;

/**
 * Controller routines for path routes.
 */
class PathController {

  /**
   * @todo Remove path_admin_overview().
   */
  public function adminOverview() {
    module_load_include('admin.inc', 'path');
    return path_admin_overview();
  }

  /**
   * @todo Remove path_admin_overview().
   */
  public function adminOverviewFiltered($keys) {
    module_load_include('admin.inc', 'path');
    return path_admin_overview($keys);
  }

  /**
   * @todo Remove path_admin_edit().
   */
  public function adminEdit($path) {
    $path = \Drupal::service('path.alias_storage')->load(array('pid' => $path));
    module_load_include('admin.inc', 'path');
    return path_admin_edit($path);
  }

  /**
   * @todo Remove path_admin_edit().
   */
  public function adminAdd() {
    module_load_include('admin.inc', 'path');
    return path_admin_edit();
  }

}
