<?php

namespace Square\View\Helper;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class QuantityChoiceFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        $optionManager = $sm->getServiceLocator()->get('Base\Manager\OptionManager');
        $userSessionManager = $sm->getServiceLocator()->get('User\Manager\UserSessionManager');
        return new QuantityChoice($optionManager, $userSessionManager);
        //return new QuantityChoice($sm->getServiceLocator()->get('Base\Manager\OptionManager'));
    }

}