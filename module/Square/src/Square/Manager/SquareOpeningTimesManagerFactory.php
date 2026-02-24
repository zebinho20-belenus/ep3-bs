<?php

namespace Square\Manager;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SquareOpeningTimesManagerFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new SquareOpeningTimesManager(
            $sm->get('Square\Table\SquareOpeningTimesTable'),
            $sm->get('Square\Manager\SquareManager'));
    }

}
