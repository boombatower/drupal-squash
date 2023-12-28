<?php

/**
 * @file
 * Definition of Drupal\rest\test\ReadTest.
 */

namespace Drupal\rest\Tests;

use Drupal\Component\Serialization\Json;
use Drupal\rest\Tests\RESTTestBase;

/**
 * Tests resource read operations on test entities, nodes and users.
 */
class ReadTest extends RESTTestBase {

  /**
   * Modules to enable.
   *
   * @var array
   */
  public static $modules = array('hal', 'rest', 'entity_test');

  public static function getInfo() {
    return array(
      'name' => 'Read resource',
      'description' => 'Tests the retrieval of resources.',
      'group' => 'REST',
    );
  }

  /**
   * Tests several valid and invalid read requests on all entity types.
   */
  public function testRead() {
    // @todo Expand this at least to users.
    // Define the entity types we want to test.
    $entity_types = array('entity_test', 'node');
    foreach ($entity_types as $entity_type) {
      $this->enableService('entity:' . $entity_type, 'GET');
      // Create a user account that has the required permissions to read
      // resources via the REST API.
      $permissions = $this->entityPermissions($entity_type, 'view');
      $permissions[] = 'restful get entity:' . $entity_type;
      $account = $this->drupalCreateUser($permissions);
      $this->drupalLogin($account);

      // Create an entity programmatically.
      $entity = $this->entityCreate($entity_type);
      $entity->save();
      // Read it over the REST API.
      $response = $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
      $this->assertResponse('200', 'HTTP response code is correct.');
      $this->assertHeader('content-type', $this->defaultMimeType);
      $data = Json::decode($response);
      // Only assert one example property here, other properties should be
      // checked in serialization tests.
      $this->assertEqual($data['uuid'][0]['value'], $entity->uuid(), 'Entity UUID is correct');

      // Try to read the entity with an unsupported mime format.
      $response = $this->httpRequest($entity->getSystemPath(), 'GET', NULL, 'application/wrongformat');
      $this->assertResponse(200);
      $this->assertHeader('Content-type', 'text/html; charset=UTF-8');

      // Try to read an entity that does not exist.
      $response = $this->httpRequest($entity_type . '/9999', 'GET', NULL, $this->defaultMimeType);
      $this->assertResponse(404);
      $this->assertText('A fatal error occurred: The "' . $entity_type . '" parameter was not converted for the path', 'Response message is correct.');

      // Make sure that field level access works and that the according field is
      // not available in the response. Only applies to entity_test.
      // @see entity_test_entity_field_access()
      if ($entity_type == 'entity_test') {
        $entity->field_test_text->value = 'no access value';
        $entity->save();
        $response = $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
        $this->assertResponse(200);
        $this->assertHeader('content-type', $this->defaultMimeType);
        $data = Json::decode($response);
        $this->assertFalse(isset($data['field_test_text']), 'Field access protected field is not visible in the response.');
      }

      // Try to read an entity without proper permissions.
      $this->drupalLogout();
      $response = $this->httpRequest($entity->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
      $this->assertResponse(403);
      $this->assertNull(Json::decode($response), 'No valid JSON found.');
    }
    // Try to read a resource which is not REST API enabled.
    $account = $this->drupalCreateUser();
    $this->drupalLogin($account);
    $response = $this->httpRequest($account->getSystemPath(), 'GET', NULL, $this->defaultMimeType);
    $this->assertResponse(404);
    $this->assertNull(Json::decode($response), 'No valid JSON found.');
  }

  /**
   * Tests the resource structure.
   */
  public function testResourceStructure() {
    // Enable a service with a format restriction but no authentication.
    $this->enableService('entity:node', 'GET', 'json');
    // Create a user account that has the required permissions to read
    // resources via the REST API.
    $permissions = $this->entityPermissions('node', 'view');
    $permissions[] = 'restful get entity:node';
    $account = $this->drupalCreateUser($permissions);
    $this->drupalLogin($account);

    // Create an entity programmatically.
    $entity = $this->entityCreate('node');
    $entity->save();

    // Read it over the REST API.
    $response = $this->httpRequest($entity->getSystemPath(), 'GET', NULL, 'application/json');
    $this->assertResponse('200', 'HTTP response code is correct.');
  }

}
