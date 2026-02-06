<?php

namespace Backend\View\Helper\Booking;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingFormatFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new BookingFormat(
            $sm->getServiceLocator()->get('Square\Manager\SquareManager'),
            $sm->getServiceLocator()->get('Booking\Manager\Booking\BillManager'),
            $sm->getServiceLocator()->get('User\Manager\UserManager'),
            $sm->getServiceLocator()->get('Booking\Service\BookingStatusService')
        );
    }

}
