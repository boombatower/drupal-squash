<?php

/**
 * @file
 * Definition of Drupal\user\RegisterFormController.
 */

namespace Drupal\user;

use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Form controller for the user register forms.
 */
class RegisterFormController extends AccountFormController {

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::form().
   */
  public function form(array $form, array &$form_state) {
    global $user;
    $account = $this->entity;

    $admin = user_access('administer users');

    // Pass access information to the submit handler. Running an access check
    // inside the submit function interferes with form processing and breaks
    // hook_form_alter().
    $form['administer_users'] = array(
      '#type' => 'value',
      '#value' => $admin,
    );

    // If we aren't admin but already logged on, go to the user page instead.
    if (!$admin && $user->uid) {
      return new RedirectResponse(url('user/' . $user->uid, array('absolute' => TRUE)));
    }

    $form['#attached']['library'][] = array('system', 'jquery.cookie');
    $form['#attributes']['class'][] = 'user-info-from-cookie';

    // Start with the default user account fields.
    $form = parent::form($form, $form_state, $account);

    // Attach field widgets, and hide the ones where the 'user_register_form'
    // setting is not on.
    field_attach_form($account, $form, $form_state);
    foreach (field_info_instances('user', 'user') as $field_name => $instance) {
      if (empty($instance['settings']['user_register_form'])) {
        $form[$field_name]['#access'] = FALSE;
      }
    }

    if ($admin) {
      // Redirect back to page which initiated the create request; usually
      // admin/people/create.
      $form_state['redirect'] = current_path();
    }

    return $form;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::actions().
   */
  protected function actions(array $form, array &$form_state) {
    $element = parent::actions($form, $form_state);
    $element['submit']['#value'] = t('Create new account');
    return $element;
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::submit().
   */
  public function submit(array $form, array &$form_state) {
    $admin = $form_state['values']['administer_users'];

    if (!config('user.settings')->get('verify_mail') || $admin) {
      $pass = $form_state['values']['pass'];
    }
    else {
      $pass = user_password();
    }

    // Remove unneeded values.
    form_state_values_clean($form_state);

    $form_state['values']['pass'] = $pass;
    $form_state['values']['init'] = $form_state['values']['mail'];

    parent::submit($form, $form_state);
  }

  /**
   * Overrides Drupal\Core\Entity\EntityFormController::submit().
   */
  public function save(array $form, array &$form_state) {
    $account = $this->entity;
    $pass = $account->pass;
    $admin = $form_state['values']['administer_users'];
    $notify = !empty($form_state['values']['notify']);

    // Save has no return value so this cannot be tested.
    // Assume save has gone through correctly.
    $account->save();

    $form_state['user'] = $account;
    $form_state['values']['uid'] = $account->uid;

    watchdog('user', 'New user: %name %email.', array('%name' => $form_state['values']['name'], '%email' => '<' . $form_state['values']['mail'] . '>'), WATCHDOG_NOTICE, l(t('edit'), 'user/' . $account->uid . '/edit'));

    // Add plain text password into user account to generate mail tokens.
    $account->password = $pass;

    // New administrative account without notification.
    $uri = $account->uri();
    if ($admin && !$notify) {
      drupal_set_message(t('Created a new user account for <a href="@url">%name</a>. No e-mail has been sent.', array('@url' => url($uri['path'], $uri['options']), '%name' => $account->name)));
    }
    // No e-mail verification required; log in user immediately.
    elseif (!$admin && !config('user.settings')->get('verify_mail') && $account->status) {
      _user_mail_notify('register_no_approval_required', $account);
      $form_state['uid'] = $account->uid;
      user_login_form_submit(array(), $form_state);
      drupal_set_message(t('Registration successful. You are now logged in.'));
      $form_state['redirect'] = '';
    }
    // No administrator approval required.
    elseif ($account->status || $notify) {
      if (empty($account->mail) && $notify) {
        drupal_set_message(t('The new user <a href="@url">%name</a> was created without an email address, so no welcome message was sent.', array('@url' => url($uri['path'], $uri['options']), '%name' => $account->name)));
      }
      else {
        $op = $notify ? 'register_admin_created' : 'register_no_approval_required';
        if (_user_mail_notify($op, $account)) {
          if ($notify) {
            drupal_set_message(t('A welcome message with further instructions has been e-mailed to the new user <a href="@url">%name</a>.', array('@url' => url($uri['path'], $uri['options']), '%name' => $account->name)));
          }
          else {
            drupal_set_message(t('A welcome message with further instructions has been sent to your e-mail address.'));
            $form_state['redirect'] = '';
          }
        }
      }
    }
    // Administrator approval required.
    else {
      _user_mail_notify('register_pending_approval', $account);
      drupal_set_message(t('Thank you for applying for an account. Your account is currently pending approval by the site administrator.<br />In the meantime, a welcome message with further instructions has been sent to your e-mail address.'));
      $form_state['redirect'] = '';
    }
  }
}
