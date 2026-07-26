<?php

namespace Booking\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class PaymentReconciliationServiceFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        return new PaymentReconciliationService(
            $sm->get('Zend\Db\Adapter\Adapter'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Booking\Manager\Booking\BillManager'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Square\Manager\SquareManager'),
            $sm->get('User\Manager\UserManager'),
            $sm->get('Booking\Service\BookingService'),
            $sm->get('Base\Service\AuditService'),
            $sm->get('Backend\Service\MailService'),
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Base\Manager\ConfigManager'),
            $sm->get('payum')
        );
    }

}
