<?php

namespace Base\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class AuditServiceFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new AuditService(
            $sm->get('Base\Table\AuditLogTable'),
            getcwd() . '/data/log/audit.log'
        );
    }

}
