<?php

/**
 * @file
 * Contains Drupal\Core\EventSubscriber\LegacyAccessSubscriber.
 */

namespace Drupal\Core\EventSubscriber;

use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\HttpKernel\Event\GetResponseEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Access subscriber for legacy controller requests.
 */
class LegacyAccessSubscriber implements EventSubscriberInterface {

  /**
   * Verifies that the current user can access the requested path.
   *
   * @todo This is a total hack to keep our current access system working. It
   *   should be replaced with something robust and injected at some point.
   *
   * @param Symfony\Component\HttpKernel\Event\GetResponseEvent $event
   *   The Event to process.
   */
  public function onKernelRequestAccessCheck(GetResponseEvent $event) {

    $router_item = $event->getRequest()->attributes->get('drupal_menu_item');

    if (isset($router_item['access']) && !$router_item['access']) {
      throw new AccessDeniedHttpException();
    }
  }

  /**
   * Registers the methods in this class that should be listeners.
   *
   * @return array
   *   An array of event listener definitions.
   */
  static function getSubscribedEvents() {
    $events[KernelEvents::REQUEST][] = array('onKernelRequestAccessCheck', 30);

    return $events;
  }
}
