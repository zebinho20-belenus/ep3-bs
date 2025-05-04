<?php

namespace Booking\Service;

use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingServiceFactory implements FactoryInterface
{

    public function createService(ServiceLocatorInterface $sm)
    {
        $notificationListener = $sm->get('Booking\Service\Listener\NotificationListener');

        $bookingService = new BookingService(
            $sm->get('Base\Manager\OptionManager'),
            $sm->get('Base\Manager\ConfigManager'),
            $sm->get('Booking\Manager\BookingManager'),
            $sm->get('Booking\Manager\Booking\BillManager'),
            $sm->get('Booking\Manager\ReservationManager'),
            $sm->get('Square\Manager\SquarePricingManager'),
            $sm->get('ViewHelperManager'),
            $sm->get('Zend\Db\Adapter\Adapter')->getDriver()->getConnection(),
            $sm->get('Base\Service\MailService'),
            $sm);

        $eventManager = $bookingService->getEventManager();
        $eventManager->attach($notificationListener);

        return $bookingService;
    }

}
