<?php

/**
 * @file
 * Contains \Drupal\Tests\Core\StringTranslation\StringTranslationTraitTest.
 */

namespace Drupal\Tests\Core\StringTranslation;

use Drupal\Tests\UnitTestCase;

/**
 * Tests \Drupal\Core\StringTranslation\StringTranslationTrait.
 *
 * @see \Drupal\Core\StringTranslation\StringTranslationTrait
 * @coversDefaultClass \Drupal\Core\StringTranslation\StringTranslationTrait
 *
 * @group Drupal
 * @group StringTranslation
 */
class StringTranslationTraitTest extends UnitTestCase {

  /**
   * A reflection of self::$translation.
   *
   * @var \ReflectionClass
   */
  protected $reflection;

  /**
   * The mock under test that uses StringTranslationTrait.
   *
   * @var object
   * @see PHPUnit_Framework_MockObject_Generator::getObjectForTrait()
   */
  protected $translation;

  /**
   * {@inheritdoc}
   */
  public static function getInfo() {
    return array(
      'name' => 'String translation trait',
      'description' => 'Tests the string translation trait.',
      'group' => 'StringTranslation',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    $this->translation = $this->getObjectForTrait('\Drupal\Core\StringTranslation\StringTranslationTrait');
    $stub = $this->getStringTranslationStub();
    $stub->expects($this->any())
      ->method('formatPlural')
      ->will($this->returnArgument(2));
    $this->translation->setStringTranslation($stub);
    $this->reflection = new \ReflectionClass(get_class($this->translation));
  }

  /**
   * @covers ::t
   */
  public function testT() {
    $method = $this->reflection->getMethod('t');
    $method->setAccessible(TRUE);

    $this->assertEquals('something', $method->invoke($this->translation, 'something'));
  }

  /**
   * @covers ::formatPlural
   */
  public function testFormatPlural() {
    $method = $this->reflection->getMethod('formatPlural');
    $method->setAccessible(TRUE);

    $this->assertEquals('apples', $method->invoke($this->translation, 2, 'apple', 'apples'));
  }

}
