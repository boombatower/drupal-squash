<?php

/**
 * @file
 * Contains \Drupal\Component\Datetime\Date.
 */

namespace Drupal\Core\Datetime;

use Drupal\Component\Utility\Xss;
use Drupal\Core\Config\ConfigFactory;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageManager;

/**
 * Provides a service to handler various date related functionality.
 */
class Date {

  /**
   * The list of loaded timezones.
   *
   * @var array
   */
  protected $timezones;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactory
   */
  protected $configFactory;

  /**
   * Language manager for retrieving the default langcode when none is specified.
   *
   * @var \Drupal\Core\Language\LanguageManager
   */
  protected $languageManager;

  /**
   * Constructs a DateFormats object.
   *
   * @param \Drupal\Core\Config\ConfigFactory $config_factory
   *   The config factory.
   * @param \Drupal\Core\Language\LanguageManager $language_manager
   *   The language manager.
   */
  public function __construct(ConfigFactory $config_factory, LanguageManager $language_manager) {
    $this->configFactory = $config_factory;
    $this->languageManager = $language_manager;
  }

  /**
   * Formats a date, using a date type or a custom date format string.
   *
   * @param int $timestamp
   *   A UNIX timestamp to format.
   * @param string $type
   *   (optional) The format to use, one of:
   *   - One of the built-in formats: 'short', 'medium',
   *     'long', 'html_datetime', 'html_date', 'html_time',
   *     'html_yearless_date', 'html_week', 'html_month', 'html_year'.
   *   - The name of a date type defined by a module in
   *     hook_date_format_types(), if it's been assigned a format.
   *   - The machine name of an administrator-defined date format.
   *   - 'custom', to use $format.
   *   Defaults to 'medium'.
   * @param string $format
   *   (optional) If $type is 'custom', a PHP date format string suitable for
   *   input to date(). Use a backslash to escape ordinary text, so it does not
   *   get interpreted as date format characters.
   * @param string $timezone
   *   (optional) Time zone identifier, as described at
   *   http://php.net/manual/timezones.php Defaults to the time zone used to
   *   display the page.
   * @param string $langcode
   *   (optional) Language code to translate to. Defaults to the language used to
   *   display the page.
   *
   * @return string
   *   A translated date string in the requested format.
   */
  public function format($timestamp, $type = 'medium', $format = '', $timezone = NULL, $langcode = NULL) {
    if (!isset($timezone)) {
      $timezone = date_default_timezone_get();
    }
    // Store DateTimeZone objects in an array rather than repeatedly
    // constructing identical objects over the life of a request.
    if (!isset($this->timezones[$timezone])) {
      $this->timezones[$timezone] = timezone_open($timezone);
    }

    if (empty($langcode)) {
      $langcode = $this->languageManager->getLanguage(Language::TYPE_INTERFACE)->langcode;
    }

    // Create a DrupalDateTime object from the timestamp and timezone.
    $date = new DrupalDateTime($timestamp, $this->timezones[$timezone]);

    // Find the appropriate format type.
    $key = $date->canUseIntl() ? DrupalDateTime::INTL : DrupalDateTime::PHP;

    // If we have a non-custom date format use the provided date format pattern.
    $config = $this->configFactory->get('system.date');
    if ($type != 'custom') {
      $format = $config->get('formats.' . $type . '.pattern.' . $key);
    }

    // Fall back to medium if a format was not found.
    if (empty($format)) {
      $format = $config->get('formats.medium.pattern.' . $key);
    }

    // Call $date->format().
    $settings = array(
      'langcode' => $langcode,
      'format_string_type' => $key,
    );
    return Xss::filter($date->format($format, $settings));
  }

}
