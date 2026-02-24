<?php

namespace Square\Table;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class SquareOpeningTimesTableFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new SquareOpeningTimesTable(SquareOpeningTimesTable::NAME, $sm->get('Zend\Db\Adapter\Adapter'));
    }

}
