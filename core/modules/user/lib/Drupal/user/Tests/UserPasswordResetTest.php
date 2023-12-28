<?php

/**
 * @file
 * Definition of Drupal\user\Tests\UserPasswordResetTest.
 */

namespace Drupal\user\Tests;

use Drupal\simpletest\WebTestBase;

/**
 * Tests resetting a user password.
 */
class UserPasswordResetTest extends WebTestBase {
  /**
   * The user object to test password resetting.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $account;

  public static function getInfo() {
    return array(
      'name' => 'Reset password',
      'description' => 'Ensure that password reset methods work as expected.',
      'group' => 'User',
    );
  }

  public function setUp() {
    parent::setUp();

    // Create a user.
    $account = $this->drupalCreateUser();

    // Activate user by logging in.
    $this->drupalLogin($account);

    $this->account = user_load($account->uid);
    $this->drupalLogout();
  }

  /**
   * Tests password reset functionality.
   */
  function testUserPasswordReset() {
    // Try to reset the password for an invalid account.
    $this->drupalGet('user/password');

    $edit = array('name' => $this->randomName(32));
    $this->drupalPost(NULL, $edit, t('E-mail new password'));

    $this->assertText(t('Sorry, @name is not recognized as a username or an e-mail address.', array('@name' => $edit['name'])), 'Validation error message shown when trying to request password for invalid account.');
    $this->assertEqual(count($this->drupalGetMails(array('id' => 'user_password_reset'))), 0, t('No e-mail was sent when requesting a password for an invalid account.'));

    // Reset the password by username via the password reset page.
    $edit['name'] = $this->account->name;
    $this->drupalPost(NULL, $edit, t('E-mail new password'));

     // Verify that the user was sent an e-mail.
    $this->assertMail('to', $this->account->mail, t('Password e-mail sent to user.'));
    $subject = t('Replacement login information for @username at @site', array('@username' => $this->account->name, '@site' => config('system.site')->get('name')));
    $this->assertMail('subject', $subject, 'Password reset e-mail subject is correct.');

    $resetURL = $this->getResetURL();
    $this->drupalGet($resetURL);

    // Check the one-time login page.
    $this->assertText($this->account->name, 'One-time login page contains the correct username.');
    $this->assertText(t('This login can be used only once.'), 'Found warning about one-time login.');

    // Check successful login.
    $this->drupalPost(NULL, NULL, t('Log in'));
    $this->assertLink(t('Log out'));
    $this->assertTitle(t('@name | @site', array('@name' => $this->account->name, '@site' => config('system.site')->get('name'))), 'Logged in using password reset link.');

    // Log out, and try to log in again using the same one-time link.
    $this->drupalLogout();
    $this->drupalGet($resetURL);
    $this->assertText(t('You have tried to use a one-time login link that has either been used or is no longer valid. Please request a new one using the form below.'), 'One-time link is no longer valid.');

    // Request a new password again, this time using the e-mail address.
    $this->drupalGet('user/password');
    // Count email messages before to compare with after.
    $before = count($this->drupalGetMails(array('id' => 'user_password_reset')));
    $edit['name'] = $this->account->mail;
    $this->drupalPost(NULL, $edit, t('E-mail new password'));
    $this->assertTrue( count($this->drupalGetMails(array('id' => 'user_password_reset'))) === $before + 1, 'E-mail sent when requesting password reset using e-mail address.');

    // Create a password reset link as if the request time was 60 seconds older than the allowed limit.
    $timeout = config('user.settings')->get('password_reset_timeout');
    $bogus_timestamp = REQUEST_TIME - $timeout - 60;
    $_uid = $this->account->uid;
    $this->drupalGet("user/reset/$_uid/$bogus_timestamp/" . user_pass_rehash($this->account->pass, $bogus_timestamp, $this->account->login));
    $this->assertText(t('You have tried to use a one-time login link that has expired. Please request a new one using the form below.'), 'Expired password reset request rejected.');
  }

  /**
   * Retrieves password reset e-mail and extracts the login link.
   */
  public function getResetURL() {
    // Assume the most recent email.
    $_emails = $this->drupalGetMails();
    $email = end($_emails);
    $urls = array();
    preg_match('#.+user/reset/.+#', $email['body'], $urls);

    return $urls[0];
  }

 /**
  * Prefill the text box on incorrect login via link to password reset page.
  */
  public function testUserResetPasswordTextboxFilled() {
    $this->drupalGet('user/login');
    $edit = array(
      'name' => $this->randomName(),
      'pass' => $this->randomName(),
    );
    $this->drupalPost('user', $edit, t('Log in'));
    $this->assertRaw(t('Sorry, unrecognized username or password. <a href="@password">Have you forgotten your password?</a>',
      array('@password' => url('user/password', array('query' => array('name' => $edit['name']))))));
    unset($edit['pass']);
    $this->drupalGet('user/password', array('query' => array('name' => $edit['name'])));
    $this->assertFieldByName('name', $edit['name'], 'User name found.');
  }
}
