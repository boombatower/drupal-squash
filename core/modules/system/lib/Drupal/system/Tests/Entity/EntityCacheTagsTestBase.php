<?php

/**
 * @file
 * Contains \Drupal\system\Tests\Entity\EntityCacheTagsTestBase.
 */

namespace Drupal\system\Tests\Entity;

use Drupal\Component\Utility\String;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\system\Tests\Cache\PageCacheTagsTestBase;

/**
 * Provides helper methods for Entity cache tags tests.
 */
abstract class EntityCacheTagsTestBase extends PageCacheTagsTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('entity_reference', 'entity_test', 'field_test');

  /**
   * The main entity used for testing.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $entity;

  /**
   * The entity instance referencing the main entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $referencing_entity;

  /**
   * The entity instance not referencing the main entity.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $non_referencing_entity;

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    // Give anonymous users permission to view test entities, so that we can
    // verify the cache tags of cached versions of test entity pages.
    $user_role = entity_load('user_role', DRUPAL_ANONYMOUS_RID);
    $user_role->grantPermission('view test entity');
    $user_role->save();

    // Create an entity.
    $this->entity = $this->createEntity();

    // If this is a fieldable entity, then add a configurable field. We will use
    // this configurable field in later tests to ensure that modifications to
    // field (instance) configuration invalidate render cache entries.
    if ($this->entity->getEntityType()->isFieldable()) {
      // Add field, so we can modify the Field and FieldInstance entities to
      // verify that changes to those indeed clear cache tags.
      $field_name = drupal_strtolower($this->randomName());
      entity_create('field_config', array(
        'name' => 'configurable_field',
        'entity_type' => $this->entity->getEntityTypeId(),
        'type' => 'test_field',
        'settings' => array(),
      ))->save();
      entity_create('field_instance_config', array(
        'entity_type' => $this->entity->getEntityTypeId(),
        'bundle' => $this->entity->bundle(),
        'field_name' => 'configurable_field',
        'label' => 'Configurable field',
        'settings' => array(),
      ))->save();

      // Reload the entity now that a new field has been added to it.
      $storage_controller = $this->container
        ->get('entity.manager')
        ->getStorageController($this->entity->getEntityTypeId());
      $storage_controller->resetCache();
      $this->entity = $storage_controller->load($this->entity->id());
    }

    // Create a referencing and a non-referencing entity.
    list(
      $this->referencing_entity,
      $this->non_referencing_entity,
    ) = $this->createReferenceTestEntities($this->entity);
  }

  /**
   * Generates standardized entity cache tags test info.
   *
   * @param string $entity_type_label
   *   The label of the entity type whose cache tags to test.
   * @param string $group
   *   The test group.
   *
   * @return array
   *
   * @see \Drupal\simpletest\TestBase::getInfo()
   */
  protected static function generateStandardizedInfo($entity_type_label, $group) {
    return array(
      'name' => "$entity_type_label entity cache tags",
      'description' => "Test the $entity_type_label entity's cache tags.",
      'group' => $group,
    );
  }

  /**
   * Creates the entity to be tested.
   *
   * @return \Drupal\Core\Entity\EntityInterface
   *   The entity to be tested.
   */
  abstract protected function createEntity();

  /**
   * Creates a referencing and a non-referencing entity for testing purposes.
   *
   * @param \Drupal\Core\Entity\EntityInterface $referenced_entity
   *  The entity that the referencing entity should reference.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *  An array containing a referencing entity and a non-referencing entity.
   */
  protected function createReferenceTestEntities($referenced_entity) {
    // All referencing entities should be of the type 'entity_test'.
    $entity_type = 'entity_test';

    // Create a "foo" bundle for the given entity type.
    $bundle = 'foo';
    entity_test_create_bundle($bundle, NULL, $entity_type);

    // Add a field of the given type to the given entity type's "foo" bundle.
    $field_name = $referenced_entity->getEntityTypeId() . '_reference';
    entity_create('field_config', array(
      'name' => $field_name,
      'entity_type' => $entity_type,
      'type' => 'entity_reference',
      'cardinality' => FieldDefinitionInterface::CARDINALITY_UNLIMITED,
      'settings' => array(
        'target_type' => $referenced_entity->getEntityTypeId(),
      ),
    ))->save();
    entity_create('field_instance_config', array(
      'field_name' => $field_name,
      'entity_type' => $entity_type,
      'bundle' => $bundle,
      'settings' => array(
        'handler' => 'default',
        'handler_settings' => array(
          'target_bundles' => array(
            $referenced_entity->bundle() => $referenced_entity->bundle(),
          ),
          'sort' => array('field' => '_none'),
          'auto_create' => FALSE,
        ),
      ),
    ))->save();
    entity_get_display($entity_type, $bundle, 'full')
      ->setComponent($field_name, array('type' => 'entity_reference_label'))
      ->save();

    // Create an entity that does reference the entity being tested.
    $label_key = \Drupal::entityManager()->getDefinition($entity_type)->getKey('label');
    $referencing_entity = entity_create($entity_type, array(
      $label_key => 'Referencing ' . $entity_type,
      'status' => 1,
      'type' => $bundle,
      $field_name => array('target_id' => $referenced_entity->id()),
    ));
    $referencing_entity->save();

    // Create an entity that does not reference the entity being tested.
    $non_referencing_entity = entity_create($entity_type, array(
      $label_key => 'Non-referencing ' . $entity_type,
      'status' => 1,
      'type' => $bundle,
    ));
    $non_referencing_entity->save();

    return array(
      $referencing_entity,
      $non_referencing_entity,
    );
  }

  /**
   * Tests cache tags presence and invalidation of the entity when referenced.
   *
   * Tests the following cache tags:
   * - "<entity type>_view:1"
   * - "<entity type>:<entity ID>"
   * - "<referencing entity type>_view:1"
   * * - "<referencing entity type>:<referencing entity ID>"
   */
  public function testReferencedEntity() {
    $entity_type = $this->entity->getEntityTypeId();
    $referencing_entity_path = $this->referencing_entity->getSystemPath();
    $non_referencing_entity_path = $this->non_referencing_entity->getSystemPath();
    $listing_path = 'entity_test/list/' . $entity_type . '_reference/' . $entity_type . '/' . $this->entity->id();

    // Generate the standardized entity cache tags.
    $cache_tag = $entity_type . ':' . $this->entity->id();
    $view_cache_tag = $entity_type . '_view:1';

    // Generate the cache tags for the (non) referencing entities.
    $referencing_entity_cache_tags = array(
      'entity_test_view:1',
      'entity_test:' . $this->referencing_entity->id(),
      // Includes the main entity's cache tags, since this entity references it.
      $cache_tag,
      $view_cache_tag
    );
    $non_referencing_entity_cache_tags = array(
      'entity_test_view:1',
      'entity_test:' . $this->non_referencing_entity->id(),
    );


    // Prime the page cache for the referencing entity.
    $this->verifyPageCache($referencing_entity_path, 'MISS');

    // Verify a cache hit, but also the presence of the correct cache tags.
    $tags = array_merge(array('content:1'), $referencing_entity_cache_tags);
    $this->verifyPageCache($referencing_entity_path, 'HIT', $tags);

    // Also verify the existence of an entity render cache entry.
    $cid = 'entity_view:entity_test:' . $this->referencing_entity->id() . ':full:stark:r.anonymous';
    $cache_entry = \Drupal::cache()->get($cid);
    $this->assertIdentical($cache_entry->tags, $referencing_entity_cache_tags);


    // Prime the page cache for the non-referencing entity.
    $this->verifyPageCache($non_referencing_entity_path, 'MISS');

    // Verify a cache hit, but also the presence of the correct cache tags.
    $tags = array_merge(array('content:1'), $non_referencing_entity_cache_tags);
    $this->verifyPageCache($non_referencing_entity_path, 'HIT', $tags);

    // Also verify the existence of an entity render cache entry.
    $cid = 'entity_view:entity_test:' . $this->non_referencing_entity->id() . ':full:stark:r.anonymous';
    $cache_entry = \Drupal::cache()->get($cid);
    $this->assertIdentical($cache_entry->tags, $non_referencing_entity_cache_tags);



    // Prime the page cache for the listing of referencing entities.
    $this->verifyPageCache($listing_path, 'MISS');

    // Verify a cache hit, but also the presence of the correct cache tags.
    $tags = array_merge(array('content:1'), $referencing_entity_cache_tags);
    $this->verifyPageCache($listing_path, 'HIT', $tags);


    // Verify that after modifying the referenced entity, there is a cache miss
    // for both the referencing entity, and the listing of referencing entities,
    // but not for the non-referencing entity.
    $this->pass("Test modification of referenced entity.", 'Debug');
    $this->entity->save();
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');


    // Verify that after modifying the referencing entity, there is a cache miss
    // for both the referencing entity, and the listing of referencing entities,
    // but not for the non-referencing entity.
    $this->pass("Test modification of referencing entity.", 'Debug');
    $this->referencing_entity->save();
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');


    // Verify that after modifying the non-referencing entity, there is a cache
    // miss for only the non-referencing entity, not for the referencing entity,
    // nor for the listing of referencing entities.
    $this->pass("Test modification of non-referencing entity.", 'Debug');
    $this->non_referencing_entity->save();
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');
    $this->verifyPageCache($non_referencing_entity_path, 'MISS');

    // Verify cache hits.
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');


    // Verify that after modifying the entity's "full" display, there is a cache
    // miss for both the referencing entity, and the listing of referencing
    // entities, but not for the non-referencing entity.
    $this->pass("Test modification of referenced entity's 'full' display.", 'Debug');
    $entity_display = entity_get_display($entity_type, $this->entity->bundle(), 'full');
    $entity_display->save();
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');


    $bundle_entity_type = $this->entity->getEntityType()->getBundleEntityType();
    if ($bundle_entity_type !== 'bundle') {
      // Verify that after modifying the corresponding bundle entity, there is a
      // cache miss for both the referencing entity, and the listing of
      // referencing entities, but not for the non-referencing entity.
      $this->pass("Test modification of referenced entity's bundle entity.", 'Debug');
      $bundle_entity = entity_load($bundle_entity_type, $this->entity->bundle());
      $bundle_entity->save();
      $this->verifyPageCache($referencing_entity_path, 'MISS');
      $this->verifyPageCache($listing_path, 'MISS');
      $this->verifyPageCache($non_referencing_entity_path, 'HIT');

      // Verify cache hits.
      $this->verifyPageCache($referencing_entity_path, 'HIT');
      $this->verifyPageCache($listing_path, 'HIT');
    }


    if ($this->entity->getEntityType()->isFieldable()) {
      // Verify that after modifying a configurable field on the entity, there
      // is a cache miss.
      $this->pass("Test modification of referenced entity's configurable field.", 'Debug');
      $field_name = $this->entity->getEntityTypeId() . '.configurable_field';
      $field = entity_load('field_config', $field_name);
      $field->save();
      $this->verifyPageCache($referencing_entity_path, 'MISS');
      $this->verifyPageCache($listing_path, 'MISS');
      $this->verifyPageCache($non_referencing_entity_path, 'HIT');

      // Verify cache hits.
      $this->verifyPageCache($referencing_entity_path, 'HIT');
      $this->verifyPageCache($listing_path, 'HIT');


      // Verify that after modifying a configurable field instance on the
      // entity, there is a cache miss.
      $this->pass("Test modification of referenced entity's configurable field instance.", 'Debug');
      $field_instance_name = $this->entity->getEntityTypeId() . '.' . $this->entity->bundle() . '.configurable_field';
      $field_instance = entity_load('field_instance_config', $field_instance_name);
      $field_instance->save();
      $this->verifyPageCache($referencing_entity_path, 'MISS');
      $this->verifyPageCache($listing_path, 'MISS');
      $this->verifyPageCache($non_referencing_entity_path, 'HIT');

      // Verify cache hits.
      $this->verifyPageCache($referencing_entity_path, 'HIT');
      $this->verifyPageCache($listing_path, 'HIT');
    }


    // Verify that after invalidating the entity's cache tag directly,  there is
    // a cache miss for both the referencing entity, and the listing of
    // referencing entities, but not for the non-referencing entity.
    $this->pass("Test invalidation of referenced entity's cache tag.", 'Debug');
    Cache::invalidateTags(array($entity_type => array($this->entity->id())));
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');


    // Verify that after invalidating the generic entity type's view cache tag
    // directly, there is a cache miss for both the referencing entity, and the
    // listing of referencing entities, but not for the non-referencing entity.
    $this->pass("Test invalidation of referenced entity's 'view' cache tag.", 'Debug');
    Cache::invalidateTags(array($entity_type . '_view' => TRUE));
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $this->verifyPageCache($referencing_entity_path, 'HIT');
    $this->verifyPageCache($listing_path, 'HIT');


    // Verify that after deleting the entity, there is a cache miss for both the
    // referencing entity, and the listing of referencing entities, but not for
    // the non-referencing entity.
    $this->pass('Test deletion of referenced entity.', 'Debug');
    $this->entity->delete();
    $this->verifyPageCache($referencing_entity_path, 'MISS');
    $this->verifyPageCache($listing_path, 'MISS');
    $this->verifyPageCache($non_referencing_entity_path, 'HIT');

    // Verify cache hits.
    $tags = array(
      'content:1',
      'entity_test_view:1',
      'entity_test:' . $this->referencing_entity->id(),
    );
    $this->verifyPageCache($referencing_entity_path, 'HIT', $tags);
    $this->verifyPageCache($listing_path, 'HIT', array('content:1'));
  }

}
