<?php

namespace Backend\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class MemberEmailManagerFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new MemberEmailManager(
            $sm->get('Backend\Table\MemberEmailTable')
        );
    }

}
