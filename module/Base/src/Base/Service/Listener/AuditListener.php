<?php

namespace Base\Service\Listener;

use Base\Service\AuditService;
use User\Manager\UserSessionManager;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;

class AuditListener extends AbstractListenerAggregate
{
    protected $auditService;
    protected $userSessionManager;

    public function __construct(AuditService $auditService, UserSessionManager $userSessionManager)
    {
        $this->auditService = $auditService;
        $this->userSessionManager = $userSessionManager;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach('create.single', array($this, 'onBookingCreate'));
        $events->attach('cancel.single', array($this, 'onBookingCancel'));
    }

    public function onBookingCreate(Event $event)
    {
        $booking = $event->getTarget();
        $sessionUser = $this->getSessionUser();

        $this->auditService->log('booking', 'create',
            sprintf('Buchung #%s erstellt (Platz %s, Benutzer %s)',
                $booking->get('bid'), $booking->get('sid'), $booking->get('uid')),
            [
                'user_id' => $sessionUser ? $sessionUser->get('uid') : $booking->get('uid'),
                'user_name' => $sessionUser ? $sessionUser->get('alias') : null,
                'entity_type' => 'booking',
                'entity_id' => $booking->get('bid'),
                'detail' => [
                    'sid' => $booking->get('sid'),
                    'uid' => $booking->get('uid'),
                    'status' => $booking->get('status'),
                    'status_billing' => $booking->get('status_billing'),
                    'quantity' => $booking->get('quantity'),
                    'paymentMethod' => $booking->getMeta('paymentMethod'),
                    'directpay' => $booking->getMeta('directpay'),
                    'hasBudget' => $booking->getMeta('hasBudget'),
                    'budget' => $booking->getMeta('budget'),
                    'newbudget' => $booking->getMeta('newbudget'),
                ],
            ]);
    }

    public function onBookingCancel(Event $event)
    {
        $booking = $event->getTarget();
        $sessionUser = $this->getSessionUser();

        $this->auditService->log('booking', 'cancel',
            sprintf('Buchung #%s storniert', $booking->get('bid')),
            [
                'user_id' => $sessionUser ? $sessionUser->get('uid') : null,
                'user_name' => $sessionUser ? $sessionUser->get('alias') : null,
                'entity_type' => 'booking',
                'entity_id' => $booking->get('bid'),
                'detail' => [
                    'uid' => $booking->get('uid'),
                    'admin_cancelled' => $booking->getMeta('admin_cancelled'),
                    'refunded' => $booking->getMeta('refunded'),
                ],
            ]);
    }

    protected function getSessionUser()
    {
        try {
            return $this->userSessionManager->getSessionUser();
        } catch (\Exception $e) {
            return null;
        }
    }
}
