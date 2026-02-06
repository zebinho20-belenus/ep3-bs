<?php

namespace Base\View\Helper;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SessionUserFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        $serviceManager = $sm->getServiceLocator();

        $user = null;
        if ($serviceManager->has('User\Manager\UserSessionManager')) {
            $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
            $user = $userSessionManager->getSessionUser();
        }

        return new SessionUser($user);
    }

}
