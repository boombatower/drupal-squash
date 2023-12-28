<?php

/**
 * @file
 * Contains \Drupal\Core\Installer\InstallerServiceProvider.
 */

namespace Drupal\Core\Installer;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;
use Drupal\Core\DependencyInjection\ServiceModifierInterface;

/**
 * Service provider for the early installer environment.
 *
 * This class is manually added by install_begin_request() via
 * $conf['container_service_providers'] and required to prevent various services
 * from trying to retrieve data from storages that do not exist yet.
 */
class InstallerServiceProvider implements ServiceProviderInterface, ServiceModifierInterface {

  /**
   * {@inheritdoc}
   */
  public function register(ContainerBuilder $container) {
    // Inject the special configuration storage for the installer.
    // This special implementation MUST NOT be used anywhere else than the early
    // installer environment.
    $container->register('config.storage', 'Drupal\Core\Config\InstallStorage');

    // Replace services with in-memory implementations.
    foreach (array('bootstrap', 'config', 'cache', 'menu', 'page', 'path') as $bin) {
      $container
        ->register("cache.$bin", 'Drupal\Core\Cache\MemoryBackend')
        ->addArgument($bin);
    }
    $container
      ->register('keyvalue', 'Drupal\Core\KeyValueStore\KeyValueMemoryFactory');
    $container
      ->register('keyvalue.expirable', 'Drupal\Core\KeyValueStore\KeyValueNullExpirableFactory');

    // Replace services with no-op implementations.
    $container
      ->register('lock', 'Drupal\Core\Lock\NullLockBackend');
    $container
      ->register('url_generator', 'Drupal\Core\Routing\NullGenerator');
    $container
      ->register('router.dumper', 'Drupal\Core\Routing\NullMatcherDumper');

    // Replace the route builder with an empty implementation.
    // @todo Convert installer steps into routes; add an installer.routing.yml.
    $definition = $container->getDefinition('router.builder');
    $definition->setClass('Drupal\Core\Installer\InstallerRouteBuilder');

    // Remove dependencies on Drupal's default session handling.
    $container->removeDefinition('authentication.cookie');
  }

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    // Disable configuration overrides.
    // ConfigFactory would to try to load language overrides and InstallStorage
    // throws an exception upon trying to load a non-existing file.
    $container->get('config.factory')->setOverrideState(FALSE);

    // No service may persist when the early installer kernel is rebooted into
    // the production environment.
    // @todo The DrupalKernel reboot performed by drupal_install_system() is
    //   actually not a "regular" reboot (like ModuleHandler::install()), so
    //   services are not actually persisted.
    foreach ($container->findTaggedServiceIds('persist') as $id => $tags) {
      $definition = $container->getDefinition($id);
      $definition->clearTag('persist');
    }
  }

}
