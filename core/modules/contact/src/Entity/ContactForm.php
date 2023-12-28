<?php

/**
 * @file
 * Contains \Drupal\contact\Entity\ContactForm.
 */

namespace Drupal\contact\Entity;

use Drupal\Core\Config\Entity\ConfigEntityBundleBase;
use Drupal\contact\ContactFormInterface;
use Drupal\Core\Entity\EntityStorageControllerInterface;
use Drupal\contact\CategoryInterface;

/**
 * Defines the contact form entity.
 *
 * @ConfigEntityType(
 *   id = "contact_form",
 *   label = @Translation("Contact form"),
 *   handlers = {
 *     "access" = "Drupal\contact\ContactFormAccessControlHandler",
 *     "list_builder" = "Drupal\contact\ContactFormListBuilder",
 *     "form" = {
 *       "add" = "Drupal\contact\ContactFormEditForm",
 *       "edit" = "Drupal\contact\ContactFormEditForm",
 *       "delete" = "Drupal\contact\Form\ContactFormDeleteForm"
 *     }
 *   },
 *   config_prefix = "form",
 *   admin_permission = "administer contact forms",
 *   bundle_of = "contact_message",
 *   entity_keys = {
 *     "id" = "id",
 *     "label" = "label"
 *   },
 *   links = {
 *     "delete-form" = "entity.contact_form.delete_form",
 *     "edit-form" = "entity.contact_form.edit_form"
 *   }
 * )
 */
class ContactForm extends ConfigEntityBundleBase implements ContactFormInterface {

  /**
   * The form ID.
   *
   * @var string
   */
  protected $id;

  /**
   * The human-readable label of the category.
   *
   * @var string
   */
  protected $label;

  /**
   * List of recipient email addresses.
   *
   * @var array
   */
  protected $recipients = array();

  /**
   * An auto-reply message.
   *
   * @var string
   */
  protected $reply = '';

  /**
   * The weight of the category.
   *
   * @var int
   */
  protected $weight = 0;

  /**
   * {@inheritdoc}
   */
  public function getRecipients() {
    return $this->get('recipients');
  }

  /**
   * {@inheritdoc}
   */
  public function setRecipients($recipients) {
    $this->set('recipients', $recipients);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getReply() {
    return $this->get('reply');
  }

  /**
   * {@inheritdoc}
   */
  public function setReply($reply) {
    $this->set('reply', $reply);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWeight() {
    return $this->get('weight');
  }

  /**
   * {@inheritdoc}
   */
  public function setWeight($weight) {
    $this->set('weight', $weight);
    return $this;
  }

}
