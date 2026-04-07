<?php

namespace Base\Table;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class AuditLogTableFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new AuditLogTable(AuditLogTable::NAME, $sm->get('Zend\Db\Adapter\Adapter'));
    }

}
