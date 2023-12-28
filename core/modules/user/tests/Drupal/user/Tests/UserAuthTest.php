<?php

/**
 * @file
 * Contains \Drupal\user\Tests\UserAuthTest.
 */

namespace Drupal\user\Tests;

use Drupal\Tests\UnitTestCase;
use Drupal\user\UserAuth;

/**
 * Tests the UserAuth class.
 *
 * @group Drupal
 * @group User
 *
 * @coverDefaultClass \Drupal\user\UserAuth
 */
class UserAuthTest extends UnitTestCase {

  /**
   * The mock user storage controller.
   *
   * @var \Drupal\Core\Entity\EntityStorageControllerInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $userStorage;

  /**
   * The mocked password service.
   *
   * @var \Drupal\Core\Password\PasswordInterface|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $passwordService;

  /**
   * The mock user.
   *
   * @var \Drupal\user\Entity\User|\PHPUnit_Framework_MockObject_MockObject
   */
  protected $testUser;

  /**
   * The user auth object under test.
   *
   * @var \Drupal\user\UserAuth
   */
  protected $userAuth;

  /**
   * The test username.
   *
   * @var string
   */
  protected $username = 'test_user';

  /**
   * The test password
   *
   * @var string
   */
  protected $password = 'password';

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'User auth service',
      'description' => 'Tests the user auth service',
      'group' => 'User',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->userStorage = $this->getMock('Drupal\Core\Entity\EntityStorageControllerInterface');

    $entity_manager = $this->getMock('Drupal\Core\Entity\EntityManagerInterface');
    // Getting the user storage controller should only happen once per test.
    $entity_manager->expects($this->once())
      ->method('getStorageController')
      ->with('user')
      ->will($this->returnValue($this->userStorage));

    $this->passwordService = $this->getMock('Drupal\Core\Password\PasswordInterface');

    $this->testUser = $this->getMockBuilder('Drupal\user\Entity\User')
      ->disableOriginalConstructor()
      ->setMethods(array('id', 'setPassword', 'save'))
      ->getMock();

    $this->userAuth = new UserAuth($entity_manager, $this->passwordService);
  }

  /**
   * Tests failing authentication with missing credential parameters.
   *
   * @covers ::authenticate()
   *
   * @dataProvider providerTestAuthenticateWithMissingCredentials
   */
  public function testAuthenticateWithMissingCredentials($username, $password) {
    $this->userStorage->expects($this->never())
      ->method('loadByProperties');

    $this->assertFalse($this->userAuth->authenticate($username, $password));
  }

  /**
   * Data provider for testAuthenticateWithMissingCredentials().
   *
   * @return array
   */
  public function providerTestAuthenticateWithMissingCredentials() {
    return array(
      array(NULL, NULL),
      array(NULL, ''),
      array('', NULL),
      array('', ''),
    );
  }

  /**
   * Tests the authenticate method with no account returned.
   *
   * @covers ::authenticate()
   */
  public function testAuthenticateWithNoAccountReturned() {
    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(array('name' => $this->username))
      ->will($this->returnValue(array()));

    $this->assertFalse($this->userAuth->authenticate($this->username, $this->password));
  }

  /**
   * Tests the authenticate method with an incorrect password.
   *
   * @covers ::authenticate()
   */
  public function testAuthenticateWithIncorrectPassword() {
    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(array('name' => $this->username))
      ->will($this->returnValue(array($this->testUser)));

    $this->passwordService->expects($this->once())
      ->method('check')
      ->with($this->password, $this->testUser)
      ->will($this->returnValue(FALSE));

    $this->assertFalse($this->userAuth->authenticate($this->username, $this->password));
  }

  /**
   * Tests the authenticate method with a correct password.
   *
   * @covers ::authenticate()
   */
  public function testAuthenticateWithCorrectPassword() {
    $this->testUser->expects($this->once())
      ->method('id')
      ->will($this->returnValue(1));

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(array('name' => $this->username))
      ->will($this->returnValue(array($this->testUser)));

    $this->passwordService->expects($this->once())
      ->method('check')
      ->with($this->password, $this->testUser)
      ->will($this->returnValue(TRUE));

    $this->assertsame(1, $this->userAuth->authenticate($this->username, $this->password));
  }

  /**
   * Tests the authenticate method with a correct password and new password hash.
   *
   * @covers ::authenticate()
   */
  public function testAuthenticateWithCorrectPasswordAndNewPasswordHash() {
    $this->testUser->expects($this->once())
      ->method('id')
      ->will($this->returnValue(1));
    $this->testUser->expects($this->once())
      ->method('setPassword')
      ->with($this->password);
    $this->testUser->expects($this->once())
      ->method('save');

    $this->userStorage->expects($this->once())
      ->method('loadByProperties')
      ->with(array('name' => $this->username))
      ->will($this->returnValue(array($this->testUser)));

    $this->passwordService->expects($this->once())
      ->method('check')
      ->with($this->password, $this->testUser)
      ->will($this->returnValue(TRUE));
    $this->passwordService->expects($this->once())
      ->method('userNeedsNewHash')
      ->with($this->testUser)
      ->will($this->returnValue(TRUE));

    $this->assertsame(1, $this->userAuth->authenticate($this->username, $this->password));
  }

}
