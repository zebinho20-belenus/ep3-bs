<?php

namespace Service;

use Zend\EventManager\EventInterface;
use Zend\ModuleManager\Feature\AutoloaderProviderInterface;
use Zend\ModuleManager\Feature\BootstrapListenerInterface;
use Zend\ModuleManager\Feature\ConfigProviderInterface;
use Zend\Mvc\MvcEvent;

class Module implements AutoloaderProviderInterface, BootstrapListenerInterface, ConfigProviderInterface
{

    public function getAutoloaderConfig()
    {
        return array(
            'Zend\Loader\StandardAutoloader' => array(
                'namespaces' => array(
                    __NAMESPACE__ => __DIR__ . '/src/' . __NAMESPACE__,
                ),
            ),
        );
    }

    public function onBootstrap(EventInterface $e)
    {
        $events = $e->getApplication()->getEventManager();
        $events->attach(MvcEvent::EVENT_ROUTE, array($this, 'onDispatch'));
    }

    public function onDispatch(MvcEvent $e)
    {
        $serviceManager = $e->getApplication()->getServiceManager();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        $maintenanceMode = $optionManager->get('service.maintenance', 'false');

        if ($maintenanceMode == 'true' || $maintenanceMode == 'administration') {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');

            $user = $userSessionManager->getSessionUser();

            if ($user) {
                $userStatus = $user->need('status');

                /* Admins always pass through. */
                if ($userStatus == 'admin') {
                    return;
                }

                /* In administration mode, assist users also pass through. */
                if ($maintenanceMode == 'administration' && $userStatus == 'assist') {
                    return;
                }

                $userSessionManager->logout();
            }

            /* Redirect all routes except login to the system status page. */

            $routeMatch = $e->getRouteMatch();

            if (! ($routeMatch->getParam('controller') == 'User\Controller\Session' && $routeMatch->getParam('action') == 'login')) {
                $routeMatch->setParam('controller', 'Service\Controller\Service');
                $routeMatch->setParam('action', 'status');
            }
        }
    }

    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

}