<?php

namespace Base\Service\Listener;

use Base\Service\AuditService;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Manager\UserSessionManager;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;

class AuditListener extends AbstractListenerAggregate
{
    protected $auditService;
    protected $userSessionManager;
    protected $userManager;
    protected $squareManager;

    public function __construct(AuditService $auditService, UserSessionManager $userSessionManager, UserManager $userManager, SquareManager $squareManager)
    {
        $this->auditService = $auditService;
        $this->userSessionManager = $userSessionManager;
        $this->userManager = $userManager;
        $this->squareManager = $squareManager;
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

        $userName = $this->resolveUserName($booking->get('uid'));
        $squareName = $this->resolveSquareName($booking->get('sid'));
        $playerNames = $this->resolvePlayerNames($booking);
        $dateTime = $this->resolveDateTime($booking);

        $message = sprintf('Buchung #%s: %s auf %s', $booking->get('bid'), $userName, $squareName);
        if ($dateTime) {
            $message .= ' (' . $dateTime . ')';
        }

        $detail = [
            'square_name' => $squareName,
            'user_name_full' => $userName,
            'player_names' => $playerNames,
            'status' => $booking->get('status'),
            'status_billing' => $booking->get('status_billing'),
            'quantity' => $booking->get('quantity'),
            'paymentMethod' => $booking->getMeta('paymentMethod'),
            'hasBudget' => $booking->getMeta('hasBudget'),
            'budget' => $booking->getMeta('budget'),
            'newbudget' => $booking->getMeta('newbudget'),
        ];
        if ($dateTime) {
            $detail['date_time'] = $dateTime;
        }

        $this->auditService->log('booking', 'create', $message, [
            'user_id' => $sessionUser ? $sessionUser->get('uid') : $booking->get('uid'),
            'user_name' => $sessionUser ? $sessionUser->get('alias') : null,
            'entity_type' => 'booking',
            'entity_id' => $booking->get('bid'),
            'detail' => $detail,
        ]);
    }

    public function onBookingCancel(Event $event)
    {
        $booking = $event->getTarget();
        $sessionUser = $this->getSessionUser();

        $userName = $this->resolveUserName($booking->get('uid'));
        $squareName = $this->resolveSquareName($booking->get('sid'));
        $dateTime = $this->resolveDateTime($booking);

        $cancelMsg = sprintf('Buchung #%s storniert: %s auf %s', $booking->get('bid'), $userName, $squareName);
        if ($dateTime) {
            $cancelMsg .= ' (' . $dateTime . ')';
        }

        $this->auditService->log('booking', 'cancel', $cancelMsg,
            [
                'user_id' => $sessionUser ? $sessionUser->get('uid') : null,
                'user_name' => $sessionUser ? $sessionUser->get('alias') : null,
                'entity_type' => 'booking',
                'entity_id' => $booking->get('bid'),
                'detail' => [
                    'square_name' => $squareName,
                    'user_name_full' => $userName,
                    'admin_cancelled' => $booking->getMeta('admin_cancelled'),
                    'refunded' => $booking->getMeta('refunded'),
                ],
            ]);
    }

    protected function resolveUserName($uid)
    {
        try {
            $user = $this->userManager->get($uid);
            $name = trim($user->getMeta('firstname') . ' ' . $user->getMeta('lastname'));
            return $name ?: $user->get('alias');
        } catch (\Exception $e) {
            return 'uid=' . $uid;
        }
    }

    protected function resolveSquareName($sid)
    {
        try {
            $square = $this->squareManager->get($sid);
            return 'Platz ' . $square->get('name');
        } catch (\Exception $e) {
            return 'sid=' . $sid;
        }
    }

    protected function resolvePlayerNames($booking)
    {
        $raw = $booking->getMeta('player-names');
        if (! $raw) {
            return null;
        }
        $players = json_decode($raw, true);
        if (! is_array($players)) {
            return null;
        }
        $names = [];
        foreach ($players as $p) {
            if (isset($p['value']) && $p['value']) {
                $names[] = $p['value'];
            }
        }
        return $names ?: null;
    }

    protected function resolveDateTime($booking)
    {
        $reservations = $booking->getExtra('reservations');
        if (! $reservations || ! is_array($reservations)) {
            return null;
        }
        $first = reset($reservations);
        if (! $first) {
            return null;
        }
        $date = date('d.m.Y', strtotime($first->get('date')));
        $time = substr($first->get('time_start'), 0, 5) . '-' . substr($first->get('time_end'), 0, 5);
        return $date . ' ' . $time;
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
