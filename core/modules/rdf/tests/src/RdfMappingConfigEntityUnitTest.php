<?php

/**
 * @file
 * Contains \Drupal\rdf\Tests\RdfMappingConfigEntityUnitTest.
 */

namespace Drupal\rdf\Tests;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Tests\UnitTestCase;
use Drupal\rdf\Entity\RdfMapping;

/**
 * @coversDefaultClass \Drupal\rdf\Entity\RdfMapping
 *
 * @group Drupal
 * @group Config
 */
class RdfMappingConfigEntityUnitTest extends UnitTestCase {

  /**
   * The entity type used for testing.
   *
   * @var \Drupal\Core\Entity\EntityTypeInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $entityType;

  /**
   * The entity manager used for testing.
   *
   * @var \Drupal\Core\Entity\EntityManagerInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $entityManager;

  /**
   * The ID of the type of the entity under test.
   *
   * @var string
   */
  protected $entityTypeId;

  /**
   * The UUID generator used for testing.
   *
   * @var \Drupal\Component\Uuid\UuidInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $uuid;

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'description' => '',
      'name' => '\Drupal\field\Entity\RdfMapping unit test',
      'group' => 'Entity',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->entityTypeId = $this->randomName();

    $this->entityType = $this->getMock('\Drupal\Core\Entity\EntityTypeInterface');
    $this->entityType->expects($this->any())
      ->method('getProvider')
      ->will($this->returnValue('entity'));

    $this->entityManager = $this->getMock('\Drupal\Core\Entity\EntityManagerInterface');

    $this->uuid = $this->getMock('\Drupal\Component\Uuid\UuidInterface');

    $container = new ContainerBuilder();
    $container->set('entity.manager', $this->entityManager);
    $container->set('uuid', $this->uuid);
    \Drupal::setContainer($container);

  }

  /**
   * @covers ::calculateDependencies
   */
  public function testCalculateDependencies() {
    $target_entity_type_id = $this->randomName(16);

    $target_entity_type = $this->getMock('\Drupal\Core\Entity\EntityTypeInterface');
    $target_entity_type->expects($this->any())
      ->method('getProvider')
      ->will($this->returnValue('test_module'));
    $values = array('targetEntityType' => $target_entity_type_id);
    $target_entity_type->expects($this->any())
      ->method('getBundleEntityType')
      ->will($this->returnValue('bundle'));

    $this->entityManager->expects($this->at(0))
      ->method('getDefinition')
      ->with($target_entity_type_id)
      ->will($this->returnValue($target_entity_type));
    $this->entityManager->expects($this->at(1))
      ->method('getDefinition')
      ->with($this->entityTypeId)
      ->will($this->returnValue($this->entityType));

    $entity = new RdfMapping($values, $this->entityTypeId);
    $dependencies = $entity->calculateDependencies();
    $this->assertArrayNotHasKey('entity', $dependencies);
    $this->assertContains('test_module', $dependencies['module']);
  }

  /**
   * @covers ::calculateDependencies
   */
  public function testCalculateDependenciesWithEntityBundle() {
    $target_entity_type_id = $this->randomName(16);
    $target_entity_type = $this->getMock('\Drupal\Core\Entity\EntityTypeInterface');
    $target_entity_type->expects($this->any())
                     ->method('getProvider')
                     ->will($this->returnValue('test_module'));
    $bundle_id = $this->randomName(10);
    $values = array('targetEntityType' => $target_entity_type_id , 'bundle' => $bundle_id);

    $bundle_entity_type_id = $this->randomName(17);
    $bundle_entity = $this->getMock('\Drupal\Core\Config\Entity\ConfigEntityInterface');
    $bundle_entity
      ->expects($this->once())
      ->method('getConfigDependencyName')
      ->will($this->returnValue('test_module.type.' . $bundle_id));

    $target_entity_type->expects($this->any())
                     ->method('getBundleEntityType')
                     ->will($this->returnValue($bundle_entity_type_id));

    $this->entityManager->expects($this->at(0))
                        ->method('getDefinition')
                        ->with($target_entity_type_id)
                        ->will($this->returnValue($target_entity_type));
    $this->entityManager->expects($this->at(1))
                        ->method('getDefinition')
                        ->with($this->entityTypeId)
                        ->will($this->returnValue($this->entityType));

    $storage = $this->getMock('Drupal\Core\Entity\EntityStorageInterface');
    $storage->expects($this->once())
      ->method('load')
      ->with($bundle_id)
      ->will($this->returnValue($bundle_entity));

    $this->entityManager->expects($this->once())
                        ->method('getStorage')
                        ->with($bundle_entity_type_id)
                        ->will($this->returnValue($storage));

    $entity = new RdfMapping($values, $this->entityTypeId);
    $dependencies = $entity->calculateDependencies();
    $this->assertContains('test_module.type.' . $bundle_id, $dependencies['entity']);
    $this->assertContains('test_module', $dependencies['module']);
  }

}
