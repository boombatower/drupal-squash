<?php

/**
 * @file
 * Contains \Drupal\Core\Config\ConfigManager.
 */

namespace Drupal\Core\Config;

use Drupal\Component\Serialization\Yaml;
use Drupal\Core\Config\Entity\ConfigDependencyManager;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\StringTranslation\TranslationManager;

/**
 * The ConfigManager provides helper functions for the configuration system.
 */
class ConfigManager implements ConfigManagerInterface {

  /**
   * The entity manager.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface
   */
  protected $entityManager;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The typed config manager.
   *
   * @var \Drupal\Core\Config\TypedConfigManager
   */
  protected $typedConfigManager;

  /**
   * The string translation service.
   *
   * @var \Drupal\Core\StringTranslation\TranslationManager
   */
  protected $stringTranslation;

  /**
   * The active configuration storage.
   *
   * @var \Drupal\Core\Config\StorageInterface
   */
  protected $activeStorage;

  /**
   * Creates ConfigManager objects.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The configuration factory.
   * @param \Drupal\Core\Config\TypedConfigManager $typed_config_manager
   *   The typed config manager.
   * @param \Drupal\Core\StringTranslation\TranslationManager $string_translation
   *   The string translation service.
   */
  public function __construct(EntityManagerInterface $entity_manager, ConfigFactoryInterface $config_factory, TypedConfigManager $typed_config_manager, TranslationManager $string_translation, StorageInterface $active_storage) {
    $this->entityManager = $entity_manager;
    $this->configFactory = $config_factory;
    $this->typedConfigManager = $typed_config_manager;
    $this->stringTranslation = $string_translation;
    $this->activeStorage = $active_storage;
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityTypeIdByName($name) {
    $entities = array_filter($this->entityManager->getDefinitions(), function (EntityTypeInterface $entity_type) use ($name) {
      return ($config_prefix = $entity_type->getConfigPrefix()) && strpos($name, $config_prefix . '.') === 0;
    });
    return key($entities);
  }

  /**
   * {@inheritdoc}
   */
  public function getEntityManager() {
    return $this->entityManager;
  }

  /**
   * {@inheritdoc}
   */
  public function getConfigFactory() {
    return $this->configFactory;
  }

  /**
   * {@inheritdoc}
   */
  public function diff(StorageInterface $source_storage, StorageInterface $target_storage, $source_name, $target_name = NULL) {
    if (!isset($target_name)) {
      $target_name = $source_name;
    }
    // @todo Replace with code that can be autoloaded.
    //   https://drupal.org/node/1848266
    require_once __DIR__ . '/../../Component/Diff/DiffEngine.php';

    // The output should show configuration object differences formatted as YAML.
    // But the configuration is not necessarily stored in files. Therefore, they
    // need to be read and parsed, and lastly, dumped into YAML strings.
    $source_data = explode("\n", Yaml::encode($source_storage->read($source_name)));
    $target_data = explode("\n", Yaml::encode($target_storage->read($target_name)));

    // Check for new or removed files.
    if ($source_data === array('false')) {
      // Added file.
      $source_data = array($this->stringTranslation->translate('File added'));
    }
    if ($target_data === array('false')) {
      // Deleted file.
      $target_data = array($this->stringTranslation->translate('File removed'));
    }

    return new \Diff($source_data, $target_data);
  }

  /**
   * {@inheritdoc}
   */
  public function createSnapshot(StorageInterface $source_storage, StorageInterface $snapshot_storage) {
    $snapshot_storage->deleteAll();
    foreach ($source_storage->listAll() as $name) {
      $snapshot_storage->write($name, $source_storage->read($name));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function uninstall($type, $name) {
    // Remove all dependent configuration entities.
    $dependent_entities = $this->findConfigEntityDependentsAsEntities($type, array($name));

    // Reverse the array to that entities are removed in the correct order of
    // dependence. For example, this ensures that field instances are removed
    // before fields.
    foreach (array_reverse($dependent_entities) as $entity) {
      $entity->setUninstalling(TRUE);
      $entity->delete();
    }

    $config_names = $this->configFactory->listAll($name . '.');
    foreach ($config_names as $config_name) {
      $this->configFactory->get($config_name)->delete();
    }
    $schema_dir = drupal_get_path($type, $name) . '/' . InstallStorage::CONFIG_SCHEMA_DIRECTORY;
    if (is_dir($schema_dir)) {
      // Refresh the schema cache if uninstalling an extension that provides
      // configuration schema.
      $this->typedConfigManager->clearCachedDefinitions();
    }
  }

  /**
   * {@inheritdoc}
   */
  public function findConfigEntityDependents($type, array $names) {
    $dependency_manager = new ConfigDependencyManager();
    // This uses the configuration storage directly to avoid blowing the static
    // caches in the configuration factory and the configuration entity system.
    // Additionally this ensures that configuration entity dependency discovery
    // has no dependencies on the config entity classes. Assume data with UUID
    // is a config entity. Only configuration entities can be depended on so we
    // can ignore everything else.
    $data = array_filter($this->activeStorage->readMultiple($this->activeStorage->listAll()), function($config) {
      return isset($config['uuid']);
    });
    $dependency_manager->setData($data);
    $dependencies = array();
    foreach ($names as $name) {
      $dependencies = array_merge($dependencies, $dependency_manager->getDependentEntities($type, $name));
    }
    return $dependencies;
  }

  /**
   * {@inheritdoc}
   */
  public function findConfigEntityDependentsAsEntities($type, array $names) {
    $dependencies = $this->findConfigEntityDependents($type, $names);
    $entities = array();
    $definitions = $this->entityManager->getDefinitions();
    foreach ($dependencies as $config_name => $dependency) {
      // Group by entity type to efficient load entities using
      // \Drupal\Core\Entity\EntityStorageInterface::loadMultiple().
      $entity_type_id = $this->getEntityTypeIdByName($config_name);
      // It is possible that a non-configuration entity will be returned if a
      // simple configuration object has a UUID key. This would occur if the
      // dependents of the system module are calculated since system.site has
      // a UUID key.
      if ($entity_type_id) {
        $id = substr($config_name, strlen($definitions[$entity_type_id]->getConfigPrefix()) + 1);
        $entities[$entity_type_id][] = $id;
      }
    }
    $entities_to_return = array();
    foreach ($entities as $entity_type_id => $entities_to_load) {
      $storage = $this->entityManager->getStorage($entity_type_id);
      // Remove the keys since there are potential ID clashes from different
      // configuration entity types.
      $entities_to_return = array_merge($entities_to_return, array_values($storage->loadMultiple($entities_to_load)));
    }
    return $entities_to_return;
  }

}
