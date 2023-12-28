<?php

/**
 * @file
 * Contains \Drupal\Core\Field\Plugin\Field\FieldFormatter\LanguageFormatter.
 */

namespace Drupal\Core\Field\Plugin\Field\FieldFormatter;

use Drupal\Component\Utility\String;
use Drupal\Core\Field\FormatterBase;
use Drupal\Core\Field\FieldItemListInterface;

/**
 * Plugin implementation of the 'language' formatter.
 *
 * @FieldFormatter(
 *   id = "language",
 *   label = @Translation("Language"),
 *   field_types = {
 *     "language"
 *   }
 * )
 */
class LanguageFormatter extends FormatterBase {

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items) {
    $elements = array();

    foreach ($items as $delta => $item) {
      $elements[$delta] = array('#markup' => $item->language ? String::checkPlain($item->language->getName()) : '');
    }

    return $elements;
  }

}
