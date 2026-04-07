<?php

namespace Base\Service\Listener;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class AuditListenerFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new AuditListener(
            $sm->get('Base\Service\AuditService'),
            $sm->get('User\Manager\UserSessionManager'),
            $sm->get('User\Manager\UserManager'),
            $sm->get('Square\Manager\SquareManager')
        );
    }

}
