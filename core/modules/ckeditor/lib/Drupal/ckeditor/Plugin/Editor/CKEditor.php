<?php

/**
 * @file
 * Contains \Drupal\ckeditor\Plugin\Editor\CKEditor.
 */

namespace Drupal\ckeditor\Plugin\Editor;

use Drupal\Core\Language\Language;
use Drupal\editor\Plugin\EditorBase;
use Drupal\editor\Annotation\Editor;
use Drupal\Core\Annotation\Translation;
use Drupal\editor\Plugin\Core\Entity\Editor as EditorEntity;

/**
 * Defines a CKEditor-based text editor for Drupal.
 *
 * @Editor(
 *   id = "ckeditor",
 *   label = @Translation("CKEditor"),
 *   supports_inline_editing = TRUE
 * )
 */
class CKEditor extends EditorBase {

  /**
   * Implements \Drupal\editor\Plugin\EditPluginInterface::getDefaultSettings().
   */
  public function getDefaultSettings() {
    return array(
      'toolbar' => array(
        'buttons' => array(
          array(
            'Bold', 'Italic',
            '|', 'Link', 'Unlink',
            '|', 'BulletedList', 'NumberedList',
            '|', 'Blockquote', 'Image',
            '|', 'Source',
          ),
        ),
      ),
      'plugins' => array(),
    );
  }

  /**
   * Implements \Drupal\editor\Plugin\EditPluginInterface::settingsForm().
   */
  public function settingsForm(array $form, array &$form_state, EditorEntity $editor) {
    $module_path = drupal_get_path('module', 'ckeditor');
    $manager = drupal_container()->get('plugin.manager.ckeditor.plugin');
    $ckeditor_settings_toolbar = array(
      '#theme' => 'ckeditor_settings_toolbar',
      '#editor' => $editor,
      '#plugins' => $manager->getButtonsPlugins(),
    );
    $form['toolbar'] = array(
      '#type' => 'container',
      '#attached' => array(
        'library' => array(array('ckeditor', 'drupal.ckeditor.admin')),
        'js' => array(
          array(
            'type' => 'setting',
            'data' => array('ckeditor' => array(
              'toolbarAdmin' => drupal_render($ckeditor_settings_toolbar),
            )),
          )
        ),
      ),
      '#attributes' => array('class' => array('ckeditor-toolbar-configuration')),
    );
    $form['toolbar']['buttons'] = array(
      '#type' => 'textarea',
      '#title' => t('Toolbar buttons'),
      '#default_value' => json_encode($editor->settings['toolbar']['buttons']),
      '#attributes' => array('class' => array('ckeditor-toolbar-textarea')),
    );

    // CKEditor plugin settings, if any.
    $form['plugin_settings'] = array(
      '#type' => 'vertical_tabs',
    );
    $manager->injectPluginSettingsForm($form, $form_state, $editor);
    if (count(element_children($form['plugins'])) === 0) {
      unset($form['plugins']);
      unset($form['plugin_settings']);
    }

    // Hidden CKEditor instance. We need a hidden CKEditor instance with all
    // plugins enabled, so we can retrieve CKEditor's per-feature metadata (on
    // which tags, attributes, styles and classes are enabled). This metadata is
    // necessary for certain filters' (e.g. the html_filter filter) settings to
    // be updated accordingly.
    // Get a list of all external plugins and their corresponding files.
    $plugins = array_keys($manager->getDefinitions());
    $all_external_plugins = array();
    foreach ($plugins as $plugin_id) {
      $plugin = $manager->createInstance($plugin_id);
      if (!$plugin->isInternal()) {
        $all_external_plugins[$plugin_id] = $plugin->getFile();
      }
    }
    // Get a list of all buttons that are provided by all plugins.
    $all_buttons = array_reduce($manager->getButtonsPlugins(), function($result, $item) {
      return array_merge($result, array_keys($item));
    }, array());
    // Build a fake Editor object, which we'll use to generate JavaScript
    // settings for this fake Editor instance.
    $fake_editor = entity_create('editor', array(
      'format' => '',
      'editor' => 'ckeditor',
      'settings' => array(
        // Single toolbar row that contains all existing buttons.
        'toolbar' => array('buttons' => array(0 => $all_buttons)),
        'plugins' => $editor->settings['plugins'],
      ),
    ));
    $form['hidden_ckeditor'] = array(
      '#markup' => '<div id="ckeditor-hidden" class="element-hidden"></div>',
      '#attached' => array(
        'js' => array(
          array(
            'type' => 'setting',
            'data' => array('ckeditor' => array(
              'hiddenCKEditorConfig' => $this->getJSSettings($fake_editor),
            )),
          ),
        ),
      ),
    );

    return $form;
  }

  /**
   * Implements \Drupal\editor\Plugin\EditPluginInterface::settingsFormSubmit().
   */
  public function settingsFormSubmit(array $form, array &$form_state) {
    // Modify the toolbar settings by reference. The values in
    // $form_state['values']['editor']['settings'] will be saved directly by
    // editor_form_filter_admin_format_submit().
    $toolbar_settings = &$form_state['values']['editor']['settings']['toolbar'];

    $toolbar_settings['buttons'] = json_decode($toolbar_settings['buttons'], FALSE);

    // Remove the plugin settings' vertical tabs state; no need to save that.
    if (isset($form_state['values']['editor']['settings']['plugins'])) {
      unset($form_state['values']['editor']['settings']['plugin_settings']);
    }
  }

  /**
   * Implements \Drupal\editor\Plugin\EditPluginInterface::getJSSettings().
   */
  public function getJSSettings(EditorEntity $editor) {
    $language_interface = language(Language::TYPE_INTERFACE);

    $settings = array();
    $manager = drupal_container()->get('plugin.manager.ckeditor.plugin');

    // Get the settings for all enabled plugins, even the internal ones.
    $enabled_plugins = array_keys($manager->getEnabledPlugins($editor, TRUE));
    foreach ($enabled_plugins as $plugin_id) {
      $plugin = $manager->createInstance($plugin_id);
      $settings += $plugin->getConfig($editor);
    }

    // Next, set the most fundamental CKEditor settings.
    $external_plugins = $manager->getEnabledPlugins($editor);
    $settings += array(
      'toolbar' => $this->buildToolbarJSSetting($editor),
      'contentsCss' => $this->buildContentsCssJSSetting($editor),
      'extraPlugins' => implode(',', array_keys($external_plugins)),
      'language' => $language_interface->langcode,
      // Configure CKEditor to not load styles.js. The StylesCombo plugin will
      // set stylesSet according to the user's settings, if the "Styles" button
      // is enabled. We cannot get rid of this until CKEditor will stop loading
      // styles.js by default.
      // See http://dev.ckeditor.com/ticket/9992#comment:9.
      'stylesSet' => FALSE,
    );

    // Finally, set Drupal-specific CKEditor settings.
    $settings += array(
      'drupalExternalPlugins' => array_map('file_create_url', $external_plugins),
    );

    ksort($settings);

    return $settings;
  }

  /**
   * Implements \Drupal\editor\Plugin\EditPluginInterface::getLibraries().
   */
  public function getLibraries(EditorEntity $editor) {
    return array(
      array('ckeditor', 'drupal.ckeditor'),
    );
  }

  /**
   * Builds the "toolbar" configuration part of the CKEditor JS settings.
   *
   * @see getJSSettings()
   *
   * @param \Drupal\editor\Plugin\Core\Entity\Editor $editor
   *   A configured text editor object.
   * @return array
   *   An array containing the "toolbar" configuration.
   */
  public function buildToolbarJSSetting(EditorEntity $editor) {
    $toolbar = array();
    foreach ($editor->settings['toolbar']['buttons'] as $row_number => $row) {
      $button_group = array();
      foreach ($row as $button_name) {
        // Change the toolbar separators into groups.
        if ($button_name === '|') {
          $toolbar[] = $button_group;
          $button_group = array();
        }
        else {
          $button_group['items'][] = $button_name;
        }
      }
      $toolbar[] = $button_group;
      $toolbar[] = '/';
    }

    return $toolbar;
  }

  /**
   * Builds the "contentsCss" configuration part of the CKEditor JS settings.
   *
   * @see getJSSettings()
   *
   * @param \Drupal\editor\Plugin\Core\Entity\Editor $editor
   *   A configured text editor object.
   * @return array
   *   An array containing the "contentsCss" configuration.
   */
  public function buildContentsCssJSSetting(EditorEntity $editor) {
    $css = array(
      drupal_get_path('module', 'ckeditor') . '/css/ckeditor-iframe.css',
    );
    $css = array_merge($css, _ckeditor_theme_css());
    drupal_alter('ckeditor_css', $css, $editor);
    $css = array_map('file_create_url', $css);

    return array_values($css);
  }

}
