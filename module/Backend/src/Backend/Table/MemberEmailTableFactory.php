<?php

namespace Backend\Table;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class MemberEmailTableFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new MemberEmailTable(MemberEmailTable::NAME, $sm->get('Zend\Db\Adapter\Adapter'));
    }

}