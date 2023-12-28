<?php

/**
 * @file
 * Contains \Drupal\Core\Form\FormBuilder.
 */

namespace Drupal\Core\Form;

use Drupal\Component\Utility\Crypt;
use Drupal\Component\Utility\NestedArray;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Access\CsrfTokenGenerator;
use Drupal\Core\DependencyInjection\ClassResolverInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\HttpKernel;
use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use Drupal\Core\Render\Element;
use Drupal\Core\Site\Settings;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\FilterResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Provides form building and processing.
 */
class FormBuilder implements FormBuilderInterface, FormValidatorInterface, FormSubmitterInterface {

  /**
   * The module handler.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The factory for expirable key value stores used by form cache.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected $keyValueExpirableFactory;

  /**
   * The event dispatcher.
   *
   * @var \Symfony\Component\EventDispatcher\EventDispatcherInterface
   */
  protected $eventDispatcher;

  /**
   * The request stack.
   *
   * @var \Symfony\Component\HttpFoundation\RequestStack
   */
  protected $requestStack;

  /**
   * The CSRF token generator to validate the form token.
   *
   * @var \Drupal\Core\Access\CsrfTokenGenerator
   */
  protected $csrfToken;

  /**
   * The HTTP kernel to handle forms returning response objects.
   *
   * @var \Drupal\Core\HttpKernel
   */
  protected $httpKernel;

  /**
   * The class resolver.
   *
   * @var \Drupal\Core\DependencyInjection\ClassResolverInterface
   */
  protected $classResolver;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountInterface
   */
  protected $currentUser;

  /**
   * @var \Drupal\Core\Form\FormValidatorInterface
   */
  protected $formValidator;

  /**
   * @var \Drupal\Core\Form\FormSubmitterInterface
   */
  protected $formSubmitter;

  /**
   * Constructs a new FormBuilder.
   *
   * @param \Drupal\Core\Form\FormValidatorInterface $form_validator
   *   The form validator.
   * @param \Drupal\Core\Form\FormSubmitterInterface $form_submitter
   *   The form submission processor.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler.
   * @param \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface $key_value_expirable_factory
   *   The keyvalue expirable factory.
   * @param \Symfony\Component\EventDispatcher\EventDispatcherInterface $event_dispatcher
   *   The event dispatcher.
   * @param \Symfony\Component\HttpFoundation\RequestStack $request_stack
   *   The request stack.
   * @param \Drupal\Core\DependencyInjection\ClassResolverInterface $class_resolver
   *   The class resolver.
   * @param \Drupal\Core\Access\CsrfTokenGenerator $csrf_token
   *   The CSRF token generator.
   * @param \Drupal\Core\HttpKernel $http_kernel
   *   The HTTP kernel.
   */
  public function __construct(FormValidatorInterface $form_validator, FormSubmitterInterface $form_submitter, ModuleHandlerInterface $module_handler, KeyValueExpirableFactoryInterface $key_value_expirable_factory, EventDispatcherInterface $event_dispatcher, RequestStack $request_stack, ClassResolverInterface $class_resolver, CsrfTokenGenerator $csrf_token = NULL, HttpKernel $http_kernel = NULL) {
    $this->formValidator = $form_validator;
    $this->formSubmitter = $form_submitter;
    $this->moduleHandler = $module_handler;
    $this->keyValueExpirableFactory = $key_value_expirable_factory;
    $this->eventDispatcher = $event_dispatcher;
    $this->requestStack = $request_stack;
    $this->classResolver = $class_resolver;
    $this->csrfToken = $csrf_token;
    $this->httpKernel = $http_kernel;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId($form_arg, &$form_state) {
    // If the $form_arg is the name of a class, instantiate it. Don't allow
    // arbitrary strings to be passed to the class resolver.
    if (is_string($form_arg) && class_exists($form_arg)) {
      $form_arg = $this->classResolver->getInstanceFromDefinition($form_arg);
    }

    // If the $form_arg implements \Drupal\Core\Form\FormInterface, add that as
    // the callback object and determine the form ID.
    if (is_object($form_arg) && $form_arg instanceof FormInterface) {
      $form_state['build_info']['callback_object'] = $form_arg;
      if ($form_arg instanceof BaseFormIdInterface) {
        $form_state['build_info']['base_form_id'] = $form_arg->getBaseFormID();
      }
      return $form_arg->getFormId();
    }

    // Otherwise, the $form_arg is the form ID.
    return $form_arg;
  }

  /**
   * {@inheritdoc}
   */
  public function getForm($form_arg) {
    $form_state = array();

    $args = func_get_args();
    // Remove $form_arg from the arguments.
    unset($args[0]);
    $form_state['build_info']['args'] = array_values($args);

    $form_id = $this->getFormId($form_arg, $form_state);
    return $this->buildForm($form_id, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm($form_id, array &$form_state) {
    // Ensure some defaults; if already set they will not be overridden.
    $form_state += $this->getFormStateDefaults();

    // Ensure the form ID is prepared.
    $form_id = $this->getFormId($form_id, $form_state);

    if (!isset($form_state['input'])) {
      $request = $this->requestStack->getCurrentRequest();
      $form_state['input'] = $form_state['method'] == 'get' ? $request->query->all() : $request->request->all();
    }

    if (isset($_SESSION['batch_form_state'])) {
      // We've been redirected here after a batch processing. The form has
      // already been processed, but needs to be rebuilt. See _batch_finished().
      $form_state = $_SESSION['batch_form_state'];
      unset($_SESSION['batch_form_state']);
      return $this->rebuildForm($form_id, $form_state);
    }

    // If the incoming input contains a form_build_id, we'll check the cache for
    // a copy of the form in question. If it's there, we don't have to rebuild
    // the form to proceed. In addition, if there is stored form_state data from
    // a previous step, we'll retrieve it so it can be passed on to the form
    // processing code.
    $check_cache = isset($form_state['input']['form_id']) && $form_state['input']['form_id'] == $form_id && !empty($form_state['input']['form_build_id']);
    if ($check_cache) {
      $form = $this->getCache($form_state['input']['form_build_id'], $form_state);
    }

    // If the previous bit of code didn't result in a populated $form object, we
    // are hitting the form for the first time and we need to build it from
    // scratch.
    if (!isset($form)) {
      // If we attempted to serve the form from cache, uncacheable $form_state
      // keys need to be removed after retrieving and preparing the form, except
      // any that were already set prior to retrieving the form.
      if ($check_cache) {
        $form_state_before_retrieval = $form_state;
      }

      $form = $this->retrieveForm($form_id, $form_state);
      $this->prepareForm($form_id, $form, $form_state);

      // self::setCache() removes uncacheable $form_state keys defined in
      // self::getUncacheableKeys() in order for multi-step forms to work
      // properly. This means that form processing logic for single-step forms
      // using $form_state['cache'] may depend on data stored in those keys
      // during self::retrieveForm()/self::prepareForm(), but form
      // processing should not depend on whether the form is cached or not, so
      // $form_state is adjusted to match what it would be after a
      // self::setCache()/self::getCache() sequence. These exceptions are
      // allowed to survive here:
      // - always_process: Does not make sense in conjunction with form caching
      //   in the first place, since passing form_build_id as a GET parameter is
      //   not desired.
      // - temporary: Any assigned data is expected to survives within the same
      //   page request.
      if ($check_cache) {
        $uncacheable_keys = array_flip(array_diff($this->getUncacheableKeys(), array('always_process', 'temporary')));
        $form_state = array_diff_key($form_state, $uncacheable_keys);
        $form_state += $form_state_before_retrieval;
      }
    }

    // Now that we have a constructed form, process it. This is where:
    // - Element #process functions get called to further refine $form.
    // - User input, if any, gets incorporated in the #value property of the
    //   corresponding elements and into $form_state['values'].
    // - Validation and submission handlers are called.
    // - If this submission is part of a multistep workflow, the form is rebuilt
    //   to contain the information of the next step.
    // - If necessary, the form and form state are cached or re-cached, so that
    //   appropriate information persists to the next page request.
    // All of the handlers in the pipeline receive $form_state by reference and
    // can use it to know or update information about the state of the form.
    $response = $this->processForm($form_id, $form, $form_state);

    // If the form returns some kind of response, deliver it.
    if ($response instanceof Response) {
      $this->sendResponse($response);
      exit;
    }

    // If this was a successful submission of a single-step form or the last step
    // of a multi-step form, then self::processForm() issued a redirect to
    // another page, or back to this page, but as a new request. Therefore, if
    // we're here, it means that this is either a form being viewed initially
    // before any user input, or there was a validation error requiring the form
    // to be re-displayed, or we're in a multi-step workflow and need to display
    // the form's next step. In any case, we have what we need in $form, and can
    // return it for rendering.
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormStateDefaults() {
    return array(
      'rebuild' => FALSE,
      'rebuild_info' => array(),
      'redirect' => NULL,
      // @todo 'args' is usually set, so no other default 'build_info' keys are
      //   appended via += $this->getFormStateDefaults().
      'build_info' => array(
        'args' => array(),
        'files' => array(),
      ),
      'temporary' => array(),
      'validation_complete' => FALSE,
      'submitted' => FALSE,
      'executed' => FALSE,
      'programmed' => FALSE,
      'programmed_bypass_access_check' => TRUE,
      'cache'=> FALSE,
      'method' => 'post',
      'groups' => array(),
      'buttons' => array(),
      'errors' => array(),
      'limit_validation_errors' => NULL,
    );
  }

  /**
   * {@inheritdoc}
   */
  public function rebuildForm($form_id, &$form_state, $old_form = NULL) {
    $form = $this->retrieveForm($form_id, $form_state);

    // If only parts of the form will be returned to the browser (e.g., Ajax or
    // RIA clients), re-use the old #build_id to not require client-side code to
    // manually update the hidden 'build_id' input element.
    // Otherwise, a new #build_id is generated, to not clobber the previous
    // build's data in the form cache; also allowing the user to go back to an
    // earlier build, make changes, and re-submit.
    // @see self::prepareForm()
    if (isset($old_form['#build_id']) && !empty($form_state['rebuild_info']['copy']['#build_id'])) {
      $form['#build_id'] = $old_form['#build_id'];
    }
    else {
      $form['#build_id'] = 'form-' . Crypt::randomBytesBase64();
    }

    // #action defaults to request_uri(), but in case of Ajax and other partial
    // rebuilds, the form is submitted to an alternate URL, and the original
    // #action needs to be retained.
    if (isset($old_form['#action']) && !empty($form_state['rebuild_info']['copy']['#action'])) {
      $form['#action'] = $old_form['#action'];
    }

    $this->prepareForm($form_id, $form, $form_state);

    // Caching is normally done in self::processForm(), but what needs to be
    // cached is the $form structure before it passes through
    // self::doBuildForm(), so we need to do it here.
    // @todo For Drupal 8, find a way to avoid this code duplication.
    if (empty($form_state['no_cache'])) {
      $this->setCache($form['#build_id'], $form, $form_state);
    }

    // Clear out all group associations as these might be different when
    // re-rendering the form.
    $form_state['groups'] = array();

    // Return a fully built form that is ready for rendering.
    return $this->doBuildForm($form_id, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getCache($form_build_id, &$form_state) {
    if ($form = $this->keyValueExpirableFactory->get('form')->get($form_build_id)) {
      $user = $this->currentUser();
      if ((isset($form['#cache_token']) && $this->csrfToken->validate($form['#cache_token'])) || (!isset($form['#cache_token']) && $user->isAnonymous())) {
        if ($stored_form_state = $this->keyValueExpirableFactory->get('form_state')->get($form_build_id)) {
          // Re-populate $form_state for subsequent rebuilds.
          $form_state = $stored_form_state + $form_state;

          // If the original form is contained in include files, load the files.
          // @see form_load_include()
          $form_state['build_info'] += array('files' => array());
          foreach ($form_state['build_info']['files'] as $file) {
            if (is_array($file)) {
              $file += array('type' => 'inc', 'name' => $file['module']);
              $this->moduleHandler->loadInclude($file['module'], $file['type'], $file['name']);
            }
            elseif (file_exists($file)) {
              require_once DRUPAL_ROOT . '/' . $file;
            }
          }
        }
        return $form;
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function setCache($form_build_id, $form, $form_state) {
    // 6 hours cache life time for forms should be plenty.
    $expire = 21600;

    // Cache form structure.
    if (isset($form)) {
      if ($this->currentUser()->isAuthenticated()) {
        $form['#cache_token'] = $this->csrfToken->get();
      }
      $this->keyValueExpirableFactory->get('form')->setWithExpire($form_build_id, $form, $expire);
    }

    // Cache form state.
    if ($data = array_diff_key($form_state, array_flip($this->getUncacheableKeys()))) {
      $this->keyValueExpirableFactory->get('form_state')->setWithExpire($form_build_id, $data, $expire);
    }
  }

  /**
   * Returns an array of $form_state keys that shouldn't be cached.
   */
  protected function getUncacheableKeys() {
    return array(
      // Public properties defined by form constructors and form handlers.
      'always_process',
      'must_validate',
      'rebuild',
      'rebuild_info',
      'redirect',
      'redirect_route',
      'no_redirect',
      'temporary',
      // Internal properties defined by form processing.
      'buttons',
      'triggering_element',
      'complete_form',
      'groups',
      'input',
      'method',
      'submit_handlers',
      'validation_complete',
      'submitted',
      'executed',
      'validate_handlers',
      'values',
      'errors',
      'limit_validation_errors',
    );
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm($form_arg, &$form_state) {
    if (!isset($form_state['build_info']['args'])) {
      $args = func_get_args();
      // Remove $form and $form_state from the arguments.
      unset($args[0], $args[1]);
      $form_state['build_info']['args'] = array_values($args);
    }
    // Merge in default values.
    $form_state += $this->getFormStateDefaults();

    // Populate $form_state['input'] with the submitted values before retrieving
    // the form, to be consistent with what self::buildForm() does for
    // non-programmatic submissions (form builder functions may expect it to be
    // there).
    $form_state['input'] = $form_state['values'];

    $form_state['programmed'] = TRUE;

    $form_id = $this->getFormId($form_arg, $form_state);
    $form = $this->retrieveForm($form_id, $form_state);
    // Programmed forms are always submitted.
    $form_state['submitted'] = TRUE;

    // Reset form validation.
    $form_state['must_validate'] = TRUE;
    $this->clearErrors($form_state);

    $this->prepareForm($form_id, $form, $form_state);
    $this->processForm($form_id, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function retrieveForm($form_id, &$form_state) {
    // Record the $form_id.
    $form_state['build_info']['form_id'] = $form_id;

    // We save two copies of the incoming arguments: one for modules to use
    // when mapping form ids to constructor functions, and another to pass to
    // the constructor function itself.
    $args = $form_state['build_info']['args'];

    // If an explicit form builder callback is defined we just use it, otherwise
    // we look for a function named after the $form_id.
    $callback = $form_id;
    if (!empty($form_state['build_info']['callback'])) {
      $callback = $form_state['build_info']['callback'];
    }
    elseif (!empty($form_state['build_info']['callback_object'])) {
      $callback = array($form_state['build_info']['callback_object'], 'buildForm');
    }

    $form = array();
    // Assign a default CSS class name based on $form_id.
    // This happens here and not in self::prepareForm() in order to allow the
    // form constructor function to override or remove the default class.
    $form['#attributes']['class'][] = $this->drupalHtmlClass($form_id);
    // Same for the base form ID, if any.
    if (isset($form_state['build_info']['base_form_id'])) {
      $form['#attributes']['class'][] = $this->drupalHtmlClass($form_state['build_info']['base_form_id']);
    }

    // We need to pass $form_state by reference in order for forms to modify it,
    // since call_user_func_array() requires that referenced variables are
    // passed explicitly.
    $args = array_merge(array($form, &$form_state), $args);

    $form = call_user_func_array($callback, $args);
    // If the form returns some kind of response, deliver it.
    if ($form instanceof Response) {
      $this->sendResponse($form);
      exit;
    }
    $form['#form_id'] = $form_id;

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function processForm($form_id, &$form, &$form_state) {
    $form_state['values'] = array();

    // With GET, these forms are always submitted if requested.
    if ($form_state['method'] == 'get' && !empty($form_state['always_process'])) {
      if (!isset($form_state['input']['form_build_id'])) {
        $form_state['input']['form_build_id'] = $form['#build_id'];
      }
      if (!isset($form_state['input']['form_id'])) {
        $form_state['input']['form_id'] = $form_id;
      }
      if (!isset($form_state['input']['form_token']) && isset($form['#token'])) {
        $form_state['input']['form_token'] = $this->csrfToken->get($form['#token']);
      }
    }

    // self::doBuildForm() finishes building the form by calling element
    // #process functions and mapping user input, if any, to #value properties,
    // and also storing the values in $form_state['values']. We need to retain
    // the unprocessed $form in case it needs to be cached.
    $unprocessed_form = $form;
    $form = $this->doBuildForm($form_id, $form, $form_state);

    // Only process the input if we have a correct form submission.
    if ($form_state['process_input']) {
      // Form constructors may explicitly set #token to FALSE when cross site
      // request forgery is irrelevant to the form, such as search forms.
      if (isset($form['#token']) && $form['#token'] === FALSE) {
        unset($form['#token']);
      }
      // Form values for programmed form submissions typically do not include a
      // value for the submit button. But without a triggering element, a
      // potentially existing #limit_validation_errors property on the primary
      // submit button is not taken account. Therefore, check whether there is
      // exactly one submit button in the form, and if so, automatically use it
      // as triggering_element.
      if ($form_state['programmed'] && !isset($form_state['triggering_element']) && count($form_state['buttons']) == 1) {
        $form_state['triggering_element'] = reset($form_state['buttons']);
      }
      $this->formValidator->validateForm($form_id, $form, $form_state);

      // drupal_html_id() maintains a cache of element IDs it has seen, so it
      // can prevent duplicates. We want to be sure we reset that cache when a
      // form is processed, so scenarios that result in the form being built
      // behind the scenes and again for the browser don't increment all the
      // element IDs needlessly.
      if (!$this->getAnyErrors()) {
        // In case of errors, do not break HTML IDs of other forms.
        $this->drupalStaticReset('drupal_html_id');
      }

      if (!$form_state['rebuild'] && !$this->formValidator->getAnyErrors()) {
        if ($submit_response = $this->formSubmitter->doSubmitForm($form, $form_state)) {
          return $submit_response;
        }
      }

      // Don't rebuild or cache form submissions invoked via self::submitForm().
      if (!empty($form_state['programmed'])) {
        return;
      }

      // If $form_state['rebuild'] has been set and input has been processed
      // without validation errors, we are in a multi-step workflow that is not
      // yet complete. A new $form needs to be constructed based on the changes
      // made to $form_state during this request. Normally, a submit handler
      // sets $form_state['rebuild'] if a fully executed form requires another
      // step. However, for forms that have not been fully executed (e.g., Ajax
      // submissions triggered by non-buttons), there is no submit handler to
      // set $form_state['rebuild']. It would not make sense to redisplay the
      // identical form without an error for the user to correct, so we also
      // rebuild error-free non-executed forms, regardless of
      // $form_state['rebuild'].
      // @todo Simplify this logic; considering Ajax and non-HTML front-ends,
      //   along with element-level #submit properties, it makes no sense to
      //   have divergent form execution based on whether the triggering element
      //   has #executes_submit_callback set to TRUE.
      if (($form_state['rebuild'] || !$form_state['executed']) && !$this->getAnyErrors()) {
        // Form building functions (e.g., self::handleInputElement()) may use
        // $form_state['rebuild'] to determine if they are running in the
        // context of a rebuild, so ensure it is set.
        $form_state['rebuild'] = TRUE;
        $form = $this->rebuildForm($form_id, $form_state, $form);
      }
    }

    // After processing the form, the form builder or a #process callback may
    // have set $form_state['cache'] to indicate that the form and form state
    // shall be cached. But the form may only be cached if the 'no_cache'
    // property is not set to TRUE. Only cache $form as it was prior to
    // self::doBuildForm(), because self::doBuildForm() must run for each
    // request to accommodate new user input. Rebuilt forms are not cached here,
    // because self::rebuildForm() already takes care of that.
    if (!$form_state['rebuild'] && $form_state['cache'] && empty($form_state['no_cache'])) {
      $this->setCache($form['#build_id'], $unprocessed_form, $form_state);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function prepareForm($form_id, &$form, &$form_state) {
    $user = $this->currentUser();

    $form['#type'] = 'form';
    $form_state['programmed'] = isset($form_state['programmed']) ? $form_state['programmed'] : FALSE;

    // Fix the form method, if it is 'get' in $form_state, but not in $form.
    if ($form_state['method'] == 'get' && !isset($form['#method'])) {
      $form['#method'] = 'get';
    }

    // Generate a new #build_id for this form, if none has been set already.
    // The form_build_id is used as key to cache a particular build of the form.
    // For multi-step forms, this allows the user to go back to an earlier
    // build, make changes, and re-submit.
    // @see self::buildForm()
    // @see self::rebuildForm()
    if (!isset($form['#build_id'])) {
      $form['#build_id'] = 'form-' . Crypt::randomBytesBase64();
    }
    $form['form_build_id'] = array(
      '#type' => 'hidden',
      '#value' => $form['#build_id'],
      '#id' => $form['#build_id'],
      '#name' => 'form_build_id',
      // Form processing and validation requires this value, so ensure the
      // submitted form value appears literally, regardless of custom #tree
      // and #parents being set elsewhere.
      '#parents' => array('form_build_id'),
    );

    // Add a token, based on either #token or form_id, to any form displayed to
    // authenticated users. This ensures that any submitted form was actually
    // requested previously by the user and protects against cross site request
    // forgeries.
    // This does not apply to programmatically submitted forms. Furthermore,
    // since tokens are session-bound and forms displayed to anonymous users are
    // very likely cached, we cannot assign a token for them.
    // During installation, there is no $user yet.
    if ($user && $user->isAuthenticated() && !$form_state['programmed']) {
      // Form constructors may explicitly set #token to FALSE when cross site
      // request forgery is irrelevant to the form, such as search forms.
      if (isset($form['#token']) && $form['#token'] === FALSE) {
        unset($form['#token']);
      }
      // Otherwise, generate a public token based on the form id.
      else {
        $form['#token'] = $form_id;
        $form['form_token'] = array(
          '#id' => $this->drupalHtmlId('edit-' . $form_id . '-form-token'),
          '#type' => 'token',
          '#default_value' => $this->csrfToken->get($form['#token']),
          // Form processing and validation requires this value, so ensure the
          // submitted form value appears literally, regardless of custom #tree
          // and #parents being set elsewhere.
          '#parents' => array('form_token'),
        );
      }
    }

    if (isset($form_id)) {
      $form['form_id'] = array(
        '#type' => 'hidden',
        '#value' => $form_id,
        '#id' => $this->drupalHtmlId("edit-$form_id"),
        // Form processing and validation requires this value, so ensure the
        // submitted form value appears literally, regardless of custom #tree
        // and #parents being set elsewhere.
        '#parents' => array('form_id'),
      );
    }
    if (!isset($form['#id'])) {
      $form['#id'] = $this->drupalHtmlId($form_id);
    }

    $form += $this->getElementInfo('form');
    $form += array('#tree' => FALSE, '#parents' => array());

    if (!isset($form['#validate'])) {
      // Ensure that modules can rely on #validate being set.
      $form['#validate'] = array();
      if (isset($form_state['build_info']['callback_object'])) {
        $form['#validate'][] = array($form_state['build_info']['callback_object'], 'validateForm');
      }
      // Check for a handler specific to $form_id.
      elseif (function_exists($form_id . '_validate')) {
        $form['#validate'][] = $form_id . '_validate';
      }
      // Otherwise check whether this is a shared form and whether there is a
      // handler for the shared $form_id.
      elseif (isset($form_state['build_info']['base_form_id']) && function_exists($form_state['build_info']['base_form_id'] . '_validate')) {
        $form['#validate'][] = $form_state['build_info']['base_form_id'] . '_validate';
      }
    }

    if (!isset($form['#submit'])) {
      // Ensure that modules can rely on #submit being set.
      $form['#submit'] = array();
      if (isset($form_state['build_info']['callback_object'])) {
        $form['#submit'][] = array($form_state['build_info']['callback_object'], 'submitForm');
      }
      // Check for a handler specific to $form_id.
      elseif (function_exists($form_id . '_submit')) {
        $form['#submit'][] = $form_id . '_submit';
      }
      // Otherwise check whether this is a shared form and whether there is a
      // handler for the shared $form_id.
      elseif (isset($form_state['build_info']['base_form_id']) && function_exists($form_state['build_info']['base_form_id'] . '_submit')) {
        $form['#submit'][] = $form_state['build_info']['base_form_id'] . '_submit';
      }
    }

    // If no #theme has been set, automatically apply theme suggestions.
    // theme_form() itself is in #theme_wrappers and not #theme. Therefore, the
    // #theme function only has to care for rendering the inner form elements,
    // not the form itself.
    if (!isset($form['#theme'])) {
      $form['#theme'] = array($form_id);
      if (isset($form_state['build_info']['base_form_id'])) {
        $form['#theme'][] = $form_state['build_info']['base_form_id'];
      }
    }

    // Invoke hook_form_alter(), hook_form_BASE_FORM_ID_alter(), and
    // hook_form_FORM_ID_alter() implementations.
    $hooks = array('form');
    if (isset($form_state['build_info']['base_form_id'])) {
      $hooks[] = 'form_' . $form_state['build_info']['base_form_id'];
    }
    $hooks[] = 'form_' . $form_id;
    $this->moduleHandler->alter($hooks, $form, $form_state, $form_id);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm($form_id, &$form, &$form_state) {
    $this->formValidator->validateForm($form_id, $form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function redirectForm($form_state) {
    return $this->formSubmitter->redirectForm($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function executeValidateHandlers(&$form, &$form_state) {
    $this->formValidator->executeValidateHandlers($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function executeSubmitHandlers(&$form, &$form_state) {
    $this->formSubmitter->executeSubmitHandlers($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function doSubmitForm(&$form, &$form_state) {
    throw new \LogicException('Use FormBuilderInterface::processForm() instead.');
  }

  /**
   * {@inheritdoc}
   */
  public function setErrorByName($name, array &$form_state, $message = '') {
    $this->formValidator->setErrorByName($name, $form_state, $message);
  }

  /**
   * {@inheritdoc}
   */
  public function clearErrors(array &$form_state) {
    $this->formValidator->clearErrors($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getErrors(array $form_state) {
    return $this->formValidator->getErrors($form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function getAnyErrors() {
    return $this->formValidator->getAnyErrors();
  }

  /**
   * {@inheritdoc}
   */
  public function getError($element, array &$form_state) {
    return $this->formValidator->getError($element, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function setError(&$element, array &$form_state, $message = '') {
    $this->formValidator->setError($element, $form_state, $message);
  }

  /**
   * {@inheritdoc}
   */
  public function doBuildForm($form_id, &$element, &$form_state) {
    // Initialize as unprocessed.
    $element['#processed'] = FALSE;

    // Use element defaults.
    if (isset($element['#type']) && empty($element['#defaults_loaded']) && ($info = $this->getElementInfo($element['#type']))) {
      // Overlay $info onto $element, retaining preexisting keys in $element.
      $element += $info;
      $element['#defaults_loaded'] = TRUE;
    }
    // Assign basic defaults common for all form elements.
    $element += array(
      '#required' => FALSE,
      '#attributes' => array(),
      '#title_display' => 'before',
      '#errors' => NULL,
    );

    // Special handling if we're on the top level form element.
    if (isset($element['#type']) && $element['#type'] == 'form') {
      if (!empty($element['#https']) && Settings::get('mixed_mode_sessions', FALSE) &&
        !UrlHelper::isExternal($element['#action'])) {
        global $base_root;

        // Not an external URL so ensure that it is secure.
        $element['#action'] = str_replace('http://', 'https://', $base_root) . $element['#action'];
      }

      // Store a reference to the complete form in $form_state prior to building
      // the form. This allows advanced #process and #after_build callbacks to
      // perform changes elsewhere in the form.
      $form_state['complete_form'] = &$element;

      // Set a flag if we have a correct form submission. This is always TRUE
      // for programmed forms coming from self::submitForm(), or if the form_id
      // coming from the POST data is set and matches the current form_id.
      if ($form_state['programmed'] || (!empty($form_state['input']) && (isset($form_state['input']['form_id']) && ($form_state['input']['form_id'] == $form_id)))) {
        $form_state['process_input'] = TRUE;
      }
      else {
        $form_state['process_input'] = FALSE;
      }

      // All form elements should have an #array_parents property.
      $element['#array_parents'] = array();
    }

    if (!isset($element['#id'])) {
      $element['#id'] = $this->drupalHtmlId('edit-' . implode('-', $element['#parents']));
    }

    // Add the aria-describedby attribute to associate the form control with its
    // description.
    if (!empty($element['#description'])) {
      $element['#attributes']['aria-describedby'] = $element['#id'] . '--description';
    }
    // Handle input elements.
    if (!empty($element['#input'])) {
      $this->handleInputElement($form_id, $element, $form_state);
    }
    // Allow for elements to expand to multiple elements, e.g., radios,
    // checkboxes and files.
    if (isset($element['#process']) && !$element['#processed']) {
      foreach ($element['#process'] as $process) {
        $element = call_user_func_array($process, array(&$element, &$form_state, &$form_state['complete_form']));
      }
      $element['#processed'] = TRUE;
    }

    // We start off assuming all form elements are in the correct order.
    $element['#sorted'] = TRUE;

    // Recurse through all child elements.
    $count = 0;
    foreach (Element::children($element) as $key) {
      // Prior to checking properties of child elements, their default
      // properties need to be loaded.
      if (isset($element[$key]['#type']) && empty($element[$key]['#defaults_loaded']) && ($info = $this->getElementInfo($element[$key]['#type']))) {
        $element[$key] += $info;
        $element[$key]['#defaults_loaded'] = TRUE;
      }

      // Don't squash an existing tree value.
      if (!isset($element[$key]['#tree'])) {
        $element[$key]['#tree'] = $element['#tree'];
      }

      // Deny access to child elements if parent is denied.
      if (isset($element['#access']) && !$element['#access']) {
        $element[$key]['#access'] = FALSE;
      }

      // Make child elements inherit their parent's #disabled and #allow_focus
      // values unless they specify their own.
      foreach (array('#disabled', '#allow_focus') as $property) {
        if (isset($element[$property]) && !isset($element[$key][$property])) {
          $element[$key][$property] = $element[$property];
        }
      }

      // Don't squash existing parents value.
      if (!isset($element[$key]['#parents'])) {
        // Check to see if a tree of child elements is present. If so,
        // continue down the tree if required.
        $element[$key]['#parents'] = $element[$key]['#tree'] && $element['#tree'] ? array_merge($element['#parents'], array($key)) : array($key);
      }
      // Ensure #array_parents follows the actual form structure.
      $array_parents = $element['#array_parents'];
      $array_parents[] = $key;
      $element[$key]['#array_parents'] = $array_parents;

      // Assign a decimal placeholder weight to preserve original array order.
      if (!isset($element[$key]['#weight'])) {
        $element[$key]['#weight'] = $count/1000;
      }
      else {
        // If one of the child elements has a weight then we will need to sort
        // later.
        unset($element['#sorted']);
      }
      $element[$key] = $this->doBuildForm($form_id, $element[$key], $form_state);
      $count++;
    }

    // The #after_build flag allows any piece of a form to be altered
    // after normal input parsing has been completed.
    if (isset($element['#after_build']) && !isset($element['#after_build_done'])) {
      foreach ($element['#after_build'] as $callable) {
        $element = call_user_func_array($callable, array($element, &$form_state));
      }
      $element['#after_build_done'] = TRUE;
    }

    // If there is a file element, we need to flip a flag so later the
    // form encoding can be set.
    if (isset($element['#type']) && $element['#type'] == 'file') {
      $form_state['has_file_element'] = TRUE;
    }

    // Final tasks for the form element after self::doBuildForm() has run for
    // all other elements.
    if (isset($element['#type']) && $element['#type'] == 'form') {
      // If there is a file element, we set the form encoding.
      if (isset($form_state['has_file_element'])) {
        $element['#attributes']['enctype'] = 'multipart/form-data';
      }

      // If a form contains a single textfield, and the ENTER key is pressed
      // within it, Internet Explorer submits the form with no POST data
      // identifying any submit button. Other browsers submit POST data as
      // though the user clicked the first button. Therefore, to be as
      // consistent as we can be across browsers, if no 'triggering_element' has
      // been identified yet, default it to the first button.
      if (!$form_state['programmed'] && !isset($form_state['triggering_element']) && !empty($form_state['buttons'])) {
        $form_state['triggering_element'] = $form_state['buttons'][0];
      }

      // If the triggering element specifies "button-level" validation and
      // submit handlers to run instead of the default form-level ones, then add
      // those to the form state.
      foreach (array('validate', 'submit') as $type) {
        if (isset($form_state['triggering_element']['#' . $type])) {
          $form_state[$type . '_handlers'] = $form_state['triggering_element']['#' . $type];
        }
      }

      // If the triggering element executes submit handlers, then set the form
      // state key that's needed for those handlers to run.
      if (!empty($form_state['triggering_element']['#executes_submit_callback'])) {
        $form_state['submitted'] = TRUE;
      }

      // Special processing if the triggering element is a button.
      if (!empty($form_state['triggering_element']['#is_button'])) {
        // Because there are several ways in which the triggering element could
        // have been determined (including from input variables set by
        // JavaScript or fallback behavior implemented for IE), and because
        // buttons often have their #name property not derived from their
        // #parents property, we can't assume that input processing that's
        // happened up until here has resulted in
        // $form_state['values'][BUTTON_NAME] being set. But it's common for
        // forms to have several buttons named 'op' and switch on
        // $form_state['values']['op'] during submit handler execution.
        $form_state['values'][$form_state['triggering_element']['#name']] = $form_state['triggering_element']['#value'];
      }
    }
    return $element;
  }

  /**
   * Adds the #name and #value properties of an input element before rendering.
   */
  protected function handleInputElement($form_id, &$element, &$form_state) {
    if (!isset($element['#name'])) {
      $name = array_shift($element['#parents']);
      $element['#name'] = $name;
      if ($element['#type'] == 'file') {
        // To make it easier to handle files in file.inc, we place all
        // file fields in the 'files' array. Also, we do not support
        // nested file names.
        // @todo Remove this files prefix now?
        $element['#name'] = 'files[' . $element['#name'] . ']';
      }
      elseif (count($element['#parents'])) {
        $element['#name'] .= '[' . implode('][', $element['#parents']) . ']';
      }
      array_unshift($element['#parents'], $name);
    }

    // Setting #disabled to TRUE results in user input being ignored regardless
    // of how the element is themed or whether JavaScript is used to change the
    // control's attributes. However, it's good UI to let the user know that
    // input is not wanted for the control. HTML supports two attributes for:
    // this: http://www.w3.org/TR/html401/interact/forms.html#h-17.12. If a form
    // wants to start a control off with one of these attributes for UI
    // purposes, only, but still allow input to be processed if it's submitted,
    // it can set the desired attribute in #attributes directly rather than
    // using #disabled. However, developers should think carefully about the
    // accessibility implications of doing so: if the form expects input to be
    // enterable under some condition triggered by JavaScript, how would someone
    // who has JavaScript disabled trigger that condition? Instead, developers
    // should consider whether a multi-step form would be more appropriate
    // (#disabled can be changed from step to step). If one still decides to use
    // JavaScript to affect when a control is enabled, then it is best for
    // accessibility for the control to be enabled in the HTML, and disabled by
    // JavaScript on document ready.
    if (!empty($element['#disabled'])) {
      if (!empty($element['#allow_focus'])) {
        $element['#attributes']['readonly'] = 'readonly';
      }
      else {
        $element['#attributes']['disabled'] = 'disabled';
      }
    }

    // With JavaScript or other easy hacking, input can be submitted even for
    // elements with #access=FALSE or #disabled=TRUE. For security, these must
    // not be processed. Forms that set #disabled=TRUE on an element do not
    // expect input for the element, and even forms submitted with
    // self::submitForm() must not be able to get around this. Forms that set
    // #access=FALSE on an element usually allow access for some users, so forms
    // submitted with self::submitForm() may bypass access restriction and be
    // treated as high-privilege users instead.
    $process_input = empty($element['#disabled']) && (($form_state['programmed'] && $form_state['programmed_bypass_access_check']) || ($form_state['process_input'] && (!isset($element['#access']) || $element['#access'])));

    // Set the element's #value property.
    if (!isset($element['#value']) && !array_key_exists('#value', $element)) {
      $value_callable = !empty($element['#value_callback']) ? $element['#value_callback'] : 'form_type_' . $element['#type'] . '_value';
      if ($process_input) {
        // Get the input for the current element. NULL values in the input need
        // to be explicitly distinguished from missing input. (see below)
        $input_exists = NULL;
        $input = NestedArray::getValue($form_state['input'], $element['#parents'], $input_exists);
        // For browser-submitted forms, the submitted values do not contain
        // values for certain elements (empty multiple select, unchecked
        // checkbox). During initial form processing, we add explicit NULL
        // values for such elements in $form_state['input']. When rebuilding the
        // form, we can distinguish elements having NULL input from elements
        // that were not part of the initially submitted form and can therefore
        // use default values for the latter, if required. Programmatically
        // submitted forms can submit explicit NULL values when calling
        // self::submitForm() so we do not modify $form_state['input'] for them.
        if (!$input_exists && !$form_state['rebuild'] && !$form_state['programmed']) {
          // Add the necessary parent keys to $form_state['input'] and sets the
          // element's input value to NULL.
          NestedArray::setValue($form_state['input'], $element['#parents'], NULL);
          $input_exists = TRUE;
        }
        // If we have input for the current element, assign it to the #value
        // property, optionally filtered through $value_callback.
        if ($input_exists) {
          if (is_callable($value_callable)) {
            $element['#value'] = call_user_func_array($value_callable, array(&$element, $input, &$form_state));
          }
          if (!isset($element['#value']) && isset($input)) {
            $element['#value'] = $input;
          }
        }
        // Mark all posted values for validation.
        if (isset($element['#value']) || (!empty($element['#required']))) {
          $element['#needs_validation'] = TRUE;
        }
      }
      // Load defaults.
      if (!isset($element['#value'])) {
        // Call #type_value without a second argument to request default_value
        // handling.
        if (is_callable($value_callable)) {
          $element['#value'] = call_user_func_array($value_callable, array(&$element, FALSE, &$form_state));
        }
        // Final catch. If we haven't set a value yet, use the explicit default
        // value. Avoid image buttons (which come with garbage value), so we
        // only get value for the button actually clicked.
        if (!isset($element['#value']) && empty($element['#has_garbage_value'])) {
          $element['#value'] = isset($element['#default_value']) ? $element['#default_value'] : '';
        }
      }
    }

    // Determine which element (if any) triggered the submission of the form and
    // keep track of all the clickable buttons in the form for
    // form_state_values_clean(). Enforce the same input processing restrictions
    // as above.
    if ($process_input) {
      // Detect if the element triggered the submission via Ajax.
      if ($this->elementTriggeredScriptedSubmission($element, $form_state)) {
        $form_state['triggering_element'] = $element;
      }

      // If the form was submitted by the browser rather than via Ajax, then it
      // can only have been triggered by a button, and we need to determine
      // which button within the constraints of how browsers provide this
      // information.
      if (!empty($element['#is_button'])) {
        // All buttons in the form need to be tracked for
        // form_state_values_clean() and for the self::doBuildForm() code that
        // handles a form submission containing no button information in
        // \Drupal::request()->request.
        $form_state['buttons'][] = $element;
        if ($this->buttonWasClicked($element, $form_state)) {
          $form_state['triggering_element'] = $element;
        }
      }
    }

    // Set the element's value in $form_state['values'], but only, if its key
    // does not exist yet (a #value_callback may have already populated it).
    if (!NestedArray::keyExists($form_state['values'], $element['#parents'])) {
      $this->setValue($element, $element['#value'], $form_state);
    }
  }

  /**
   * Detects if an element triggered the form submission via Ajax.
   *
   * This detects button or non-button controls that trigger a form submission
   * via Ajax or some other scriptable environment. These environments can set
   * the special input key '_triggering_element_name' to identify the triggering
   * element. If the name alone doesn't identify the element uniquely, the input
   * key '_triggering_element_value' may also be set to require a match on
   * element value. An example where this is needed is if there are several
   * // buttons all named 'op', and only differing in their value.
   */
  protected function elementTriggeredScriptedSubmission($element, &$form_state) {
    if (!empty($form_state['input']['_triggering_element_name']) && $element['#name'] == $form_state['input']['_triggering_element_name']) {
      if (empty($form_state['input']['_triggering_element_value']) || $form_state['input']['_triggering_element_value'] == $element['#value']) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Determines if a given button triggered the form submission.
   *
   * This detects button controls that trigger a form submission by being
   * clicked and having the click processed by the browser rather than being
   * captured by JavaScript. Essentially, it detects if the button's name and
   * value are part of the POST data, but with extra code to deal with the
   * convoluted way in which browsers submit data for image button clicks.
   *
   * This does not detect button clicks processed by Ajax (that is done in
   * self::elementTriggeredScriptedSubmission()) and it does not detect form
   * submissions from Internet Explorer in response to an ENTER key pressed in a
   * textfield (self::doBuildForm() has extra code for that).
   *
   * Because this function contains only part of the logic needed to determine
   * $form_state['triggering_element'], it should not be called from anywhere
   * other than within the Form API. Form validation and submit handlers needing
   * to know which button was clicked should get that information from
   * $form_state['triggering_element'].
   */
  protected function buttonWasClicked($element, &$form_state) {
    // First detect normal 'vanilla' button clicks. Traditionally, all standard
    // buttons on a form share the same name (usually 'op'), and the specific
    // return value is used to determine which was clicked. This ONLY works as
    // long as $form['#name'] puts the value at the top level of the tree of
    // \Drupal::request()->request data.
    if (isset($form_state['input'][$element['#name']]) && $form_state['input'][$element['#name']] == $element['#value']) {
      return TRUE;
    }
    // When image buttons are clicked, browsers do NOT pass the form element
    // value in \Drupal::request()->Request. Instead they pass an integer
    // representing the coordinates of the click on the button image. This means
    // that image buttons MUST have unique $form['#name'] values, but the
    // details of their \Drupal::request()->request data should be ignored.
    elseif (!empty($element['#has_garbage_value']) && isset($element['#value']) && $element['#value'] !== '') {
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function setValue($element, $value, &$form_state) {
    NestedArray::setValue($form_state['values'], $element['#parents'], $value, TRUE);
  }

  /**
   * Triggers kernel.response and sends a form response.
   *
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   A response object.
   */
  protected function sendResponse(Response $response) {
    $request = $this->requestStack->getCurrentRequest();
    $event = new FilterResponseEvent($this->httpKernel, $request, HttpKernelInterface::MASTER_REQUEST, $response);

    $this->eventDispatcher->dispatch(KernelEvents::RESPONSE, $event);
    // Prepare and send the response.
    $event->getResponse()
      ->prepare($request)
      ->send();
    $this->httpKernel->terminate($request, $response);
  }

  /**
   * Wraps element_info().
   *
   * @return array
   */
  protected function getElementInfo($type) {
    return element_info($type);
  }

  /**
   * Wraps drupal_html_class().
   *
   * @return string
   */
  protected function drupalHtmlClass($class) {
    return drupal_html_class($class);
  }

  /**
   * Wraps drupal_html_id().
   *
   * @return string
   */
  protected function drupalHtmlId($id) {
    return drupal_html_id($id);
  }

  /**
   * Wraps drupal_static_reset().
   */
  protected function drupalStaticReset($name = NULL) {
    drupal_static_reset($name);
  }

  /**
   * Gets the current active user.
   *
   * @return \Drupal\Core\Session\AccountInterface
   */
  protected function currentUser() {
    if (!$this->currentUser) {
      if (\Drupal::hasService('current_user')) {
        $this->currentUser = \Drupal::currentUser();
      }
      else {
        global $user;
        $this->currentUser = $user;
      }
    }
    return $this->currentUser;
  }

}
