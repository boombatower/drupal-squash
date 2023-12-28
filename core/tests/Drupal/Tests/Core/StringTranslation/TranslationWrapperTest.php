<?php

namespace Drupal\Tests\Core\StringTranslation;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\StringTranslation\TranslationWrapper;
use Drupal\Tests\UnitTestCase;

/**
 * Tests the TranslationWrapper class.
 *
 * @coversDefaultClass \Drupal\Core\StringTranslation\TranslationWrapper
 * @group StringTranslation
 */
class TranslationWrapperTest extends UnitTestCase {

  /**
   * @covers ::__construct
   * @group legacy
   * @expectedDeprecation Drupal\Core\StringTranslation\TranslationWrapper is deprecated in drupal:8.0.0 and is removed from drupal:10.0.0. Use the \Drupal\Core\StringTranslation\TranslatableMarkup class instead. See https://www.drupal.org/node/2571255
   */
  public function testTranslationWrapper() {
    $object = new TranslationWrapper('Deprecated');
    $this->assertTrue($object instanceof TranslatableMarkup);
  }

}
