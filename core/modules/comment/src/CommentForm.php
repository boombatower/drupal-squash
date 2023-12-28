<?php

/**
 * @file
 * Definition of Drupal\comment\CommentForm.
 */

namespace Drupal\comment;

use Drupal\comment\Plugin\Field\FieldType\CommentItemInterface;
use Drupal\Component\Utility\String;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\ContentEntityForm;
use Drupal\Core\Entity\EntityManagerInterface;
use Drupal\Core\Language\Language;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Base for controller for comment forms.
 */
class CommentForm extends ContentEntityForm {

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity.manager'),
      $container->get('current_user')
    );
  }

  /**
   * Constructs a new CommentForm.
   *
   * @param \Drupal\Core\Entity\EntityManagerInterface $entity_manager
   *   The entity manager service.
   * @param \Drupal\Core\Session\AccountInterface $current_user
   *   The current user.
   */
  public function __construct(EntityManagerInterface $entity_manager, AccountInterface $current_user) {
    parent::__construct($entity_manager);
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  protected function init(array &$form_state) {
    $comment = $this->entity;

    // Make the comment inherit the current content language unless specifically
    // set.
    if ($comment->isNew()) {
      $language_content = \Drupal::languageManager()->getCurrentLanguage(LanguageInterface::TYPE_CONTENT);
      $comment->langcode->value = $language_content->id;
    }

    parent::init($form_state);
  }

  /**
   * Overrides Drupal\Core\Entity\EntityForm::form().
   */
  public function form(array $form, array &$form_state) {
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = $this->entity;
    $entity = $this->entityManager->getStorage($comment->getCommentedEntityTypeId())->load($comment->getCommentedEntityId());
    $field_name = $comment->getFieldName();
    $field_definition = $this->entityManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$comment->getFieldName()];

    // Use #comment-form as unique jump target, regardless of entity type.
    $form['#id'] = drupal_html_id('comment_form');
    $form['#theme'] = array('comment_form__' . $entity->getEntityTypeId() . '__' . $entity->bundle() . '__' . $field_name, 'comment_form');

    $anonymous_contact = $field_definition->getSetting('anonymous');
    $is_admin = $comment->id() && $this->currentUser->hasPermission('administer comments');

    if (!$this->currentUser->isAuthenticated() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT) {
      $form['#attached']['library'][] = 'core/jquery.cookie';
      $form['#attributes']['class'][] = 'user-info-from-cookie';
    }

    // If not replying to a comment, use our dedicated page callback for new
    // Comments on entities.
    if (!$comment->id() && !$comment->hasParentComment()) {
      $form['#action'] = url('comment/reply/' . $entity->getEntityTypeId() . '/' . $entity->id() . '/' . $field_name);
    }

    if (isset($form_state['comment_preview'])) {
      $form += $form_state['comment_preview'];
    }

    $form['author'] = array();
    // Display author information in a details element for comment moderators.
    if ($is_admin) {
      $form['author'] += array(
        '#type' => 'details',
        '#title' => $this->t('Administration'),
      );
    }

    // Prepare default values for form elements.
    if ($is_admin) {
      $author = $comment->getAuthorName();
      $status = $comment->isPublished();
      if (empty($form_state['comment_preview'])) {
        $form['#title'] = $this->t('Edit comment %title', array(
          '%title' => $comment->getSubject(),
        ));
      }
    }
    else {
      if ($this->currentUser->isAuthenticated()) {
        $author = $this->currentUser->getUsername();
      }
      else {
        $author = ($comment->getAuthorName() ? $comment->getAuthorName() : '');
      }
      $status = ($this->currentUser->hasPermission('skip comment approval') ? CommentInterface::PUBLISHED : CommentInterface::NOT_PUBLISHED);
    }

    $date = '';
    if ($comment->id()) {
      $date = !empty($comment->date) ? $comment->date : DrupalDateTime::createFromTimestamp($comment->getCreatedTime());
    }

    // Add the author name field depending on the current user.
    $form['author']['name'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Your name'),
      '#default_value' => $author,
      '#required' => ($this->currentUser->isAnonymous() && $anonymous_contact == COMMENT_ANONYMOUS_MUST_CONTACT),
      '#maxlength' => 60,
      '#size' => 30,
    );
    if ($is_admin) {
      $form['author']['name']['#title'] = $this->t('Authored by');
      $form['author']['name']['#description'] = $this->t('Leave blank for %anonymous.', array('%anonymous' => $this->config('user.settings')->get('anonymous')));
      $form['author']['name']['#autocomplete_route_name'] = 'user.autocomplete';
    }
    elseif ($this->currentUser->isAuthenticated()) {
      $form['author']['name']['#type'] = 'item';
      $form['author']['name']['#value'] = $form['author']['name']['#default_value'];
      $form['author']['name']['#theme'] = 'username';
      $form['author']['name']['#account'] = $this->currentUser;
    }

    $language_configuration = \Drupal::moduleHandler()->invoke('language', 'get_default_configuration', array('comment', $comment->getTypeId()));
    $form['langcode'] = array(
      '#title' => t('Language'),
      '#type' => 'language_select',
      '#default_value' => $comment->getUntranslated()->language()->id,
      '#languages' => Language::STATE_ALL,
      '#access' => isset($language_configuration['language_show']) && $language_configuration['language_show'],
    );

    // Add author email and homepage fields depending on the current user.
    $form['author']['mail'] = array(
      '#type' => 'email',
      '#title' => $this->t('Email'),
      '#default_value' => $comment->getAuthorEmail(),
      '#required' => ($this->currentUser->isAnonymous() && $anonymous_contact == COMMENT_ANONYMOUS_MUST_CONTACT),
      '#maxlength' => 64,
      '#size' => 30,
      '#description' => $this->t('The content of this field is kept private and will not be shown publicly.'),
      '#access' => $is_admin || ($this->currentUser->isAnonymous() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT),
    );

    $form['author']['homepage'] = array(
      '#type' => 'url',
      '#title' => $this->t('Homepage'),
      '#default_value' => $comment->getHomepage(),
      '#maxlength' => 255,
      '#size' => 30,
      '#access' => $is_admin || ($this->currentUser->isAnonymous() && $anonymous_contact != COMMENT_ANONYMOUS_MAYNOT_CONTACT),
    );

    // Add administrative comment publishing options.
    $form['author']['date'] = array(
      '#type' => 'datetime',
      '#title' => $this->t('Authored on'),
      '#default_value' => $date,
      '#size' => 20,
      '#access' => $is_admin,
    );

    $form['author']['status'] = array(
      '#type' => 'radios',
      '#title' => $this->t('Status'),
      '#default_value' => $status,
      '#options' => array(
        CommentInterface::PUBLISHED => $this->t('Published'),
        CommentInterface::NOT_PUBLISHED => $this->t('Not published'),
      ),
      '#access' => $is_admin,
    );

    $form['subject'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Subject'),
      '#maxlength' => 64,
      '#default_value' => $comment->getSubject(),
      '#access' => $field_definition->getSetting('subject'),
    );

    // Used for conditional validation of author fields.
    $form['is_anonymous'] = array(
      '#type' => 'value',
      '#value' => ($comment->id() ? !$comment->getOwnerId() : $this->currentUser->isAnonymous()),
    );

    return parent::form($form, $form_state, $comment);
  }

  /**
   * Overrides Drupal\Core\Entity\EntityForm::actions().
   */
  protected function actions(array $form, array &$form_state) {
    $element = parent::actions($form, $form_state);
    /* @var \Drupal\comment\CommentInterface $comment */
    $comment = $this->entity;
    $entity = $comment->getCommentedEntity();
    $field_definition = $this->entityManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$comment->getFieldName()];
    $preview_mode = $field_definition->getSetting('preview');

    // No delete action on the comment form.
    unset($element['delete']);

    // Mark the submit action as the primary action, when it appears.
    $element['submit']['#button_type'] = 'primary';

    // Only show the save button if comment previews are optional or if we are
    // already previewing the submission.
    $element['submit']['#access'] = ($comment->id() && $this->currentUser->hasPermission('administer comments')) || $preview_mode != DRUPAL_REQUIRED || isset($form_state['comment_preview']);

    $element['preview'] = array(
      '#type' => 'submit',
      '#value' => $this->t('Preview'),
      '#access' => $preview_mode != DRUPAL_DISABLED,
      '#validate' => array(
        array($this, 'validate'),
      ),
      '#submit' => array(
        array($this, 'submit'),
        array($this, 'preview'),
      ),
    );

    return $element;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityForm::validate().
   */
  public function validate(array $form, array &$form_state) {
    parent::validate($form, $form_state);
    $entity = $this->entity;

    if (!$entity->isNew()) {
      // Verify the name in case it is being changed from being anonymous.
      $accounts = $this->entityManager->getStorage('user')->loadByProperties(array('name' => $form_state['values']['name']));
      $account = reset($accounts);
      $form_state['values']['uid'] = $account ? $account->id() : 0;

      $date = $form_state['values']['date'];
      if ($date instanceOf DrupalDateTime && $date->hasErrors()) {
        $this->setFormError('date', $form_state, $this->t('You have to specify a valid date.'));
      }
      if ($form_state['values']['name'] && !$form_state['values']['is_anonymous'] && !$account) {
        $this->setFormError('name', $form_state, $this->t('You have to specify a valid author.'));
      }
    }
    elseif ($form_state['values']['is_anonymous']) {
      // Validate anonymous comment author fields (if given). If the (original)
      // author of this comment was an anonymous user, verify that no registered
      // user with this name exists.
      if ($form_state['values']['name']) {
        $accounts = $this->entityManager->getStorage('user')->loadByProperties(array('name' => $form_state['values']['name']));
        if (!empty($accounts)) {
          $this->setFormError('name', $form_state, $this->t('The name you used belongs to a registered user.'));
        }
      }
    }
  }

  /**
   * Overrides EntityForm::buildEntity().
   */
  public function buildEntity(array $form, array &$form_state) {
    $comment = parent::buildEntity($form, $form_state);
    if (!empty($form_state['values']['date']) && $form_state['values']['date'] instanceOf DrupalDateTime) {
      $comment->setCreatedTime($form_state['values']['date']->getTimestamp());
    }
    else {
      $comment->setCreatedTime(REQUEST_TIME);
    }
    $comment->changed->value = REQUEST_TIME;
    return $comment;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityForm::submit().
   */
  public function submit(array $form, array &$form_state) {
    /** @var \Drupal\comment\CommentInterface $comment */
    $comment = parent::submit($form, $form_state);

    // If the comment was posted by a registered user, assign the author's ID.
    // @todo Too fragile. Should be prepared and stored in comment_form()
    // already.
    $author_name = $comment->getAuthorName();
    if (!$comment->is_anonymous && !empty($author_name) && ($account = user_load_by_name($author_name))) {
      $comment->setOwner($account);
    }
    // If the comment was posted by an anonymous user and no author name was
    // required, use "Anonymous" by default.
    if ($comment->is_anonymous && (!isset($author_name) || $author_name === '')) {
      $comment->setAuthorName($this->config('user.settings')->get('anonymous'));
    }

    // Validate the comment's subject. If not specified, extract from comment
    // body.
    if (trim($comment->getSubject()) == '') {
      // The body may be in any format, so:
      // 1) Filter it into HTML
      // 2) Strip out all HTML tags
      // 3) Convert entities back to plain-text.
      $comment_text = $comment->comment_body->processed;
      $comment->setSubject(Unicode::truncate(trim(String::decodeEntities(strip_tags($comment_text))), 29, TRUE));
      // Edge cases where the comment body is populated only by HTML tags will
      // require a default subject.
      if ($comment->getSubject() == '') {
        $comment->setSubject($this->t('(No subject)'));
      }
    }

    return $comment;
  }

  /**
   * Form submission handler for the 'preview' action.
   *
   * @param $form
   *   An associative array containing the structure of the form.
   * @param $form_state
   *   A reference to a keyed array containing the current state of the form.
   */
  public function preview(array &$form, array &$form_state) {
    $comment = $this->entity;
    $form_state['comment_preview'] = comment_preview($comment, $form_state);
    $form_state['comment_preview']['#title'] = $this->t('Preview comment');
    $form_state['rebuild'] = TRUE;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityForm::save().
   */
  public function save(array $form, array &$form_state) {
    $comment = $this->entity;
    $entity = $comment->getCommentedEntity();
    $field_name = $comment->getFieldName();
    $uri = $entity->urlInfo();

    if ($this->currentUser->hasPermission('post comments') && ($this->currentUser->hasPermission('administer comments') || $entity->{$field_name}->status == CommentItemInterface::OPEN)) {
      // Save the anonymous user information to a cookie for reuse.
      if ($this->currentUser->isAnonymous()) {
        user_cookie_save(array_intersect_key($form_state['values'], array_flip(array('name', 'mail', 'homepage'))));
      }

      $comment->save();
      $form_state['values']['cid'] = $comment->id();

      // Add an entry to the watchdog log.
      watchdog('content', 'Comment posted: %subject.', array('%subject' => $comment->getSubject()), WATCHDOG_NOTICE, l(t('View'), 'comment/' . $comment->id(), array('fragment' => 'comment-' . $comment->id())));

      // Explain the approval queue if necessary.
      if (!$comment->isPublished()) {
        if (!$this->currentUser->hasPermission('administer comments')) {
          drupal_set_message($this->t('Your comment has been queued for review by site administrators and will be published after approval.'));
        }
      }
      else {
        drupal_set_message($this->t('Your comment has been posted.'));
      }
      $query = array();
      // Find the current display page for this comment.
      $field_definition = $this->entityManager->getFieldDefinitions($entity->getEntityTypeId(), $entity->bundle())[$field_name];
      $page = comment_get_display_page($comment->id(), $field_definition);
      if ($page > 0) {
        $query['page'] = $page;
      }
      // Redirect to the newly posted comment.
      $uri->setOption('query', $query);
      $uri->setOption('fragment', 'comment-' . $comment->id());
    }
    else {
      watchdog('content', 'Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $comment->getSubject()), WATCHDOG_WARNING);
      drupal_set_message($this->t('Comment: unauthorized comment submitted or comment submitted to a closed post %subject.', array('%subject' => $comment->getSubject())), 'error');
      // Redirect the user to the entity they are commenting on.
    }
    $form_state['redirect_route'] = $uri;
  }
}
