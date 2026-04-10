<?php

namespace Calendar\View\Helper\Cell\Render;

use Booking\Service\BookingStatusService;
use Zend\View\Helper\AbstractHelper;

class OccupiedForPrivileged extends AbstractHelper
{

    protected $bookingStatusService;

    public function __construct(BookingStatusService $bookingStatusService)
    {
        $this->bookingStatusService = $bookingStatusService;
    }

    public function __invoke(array $reservations, array $cellLinkParams)
    {
        $view = $this->getView();

        $reservationsCount = count($reservations);

        if ($reservationsCount > 1) {
            return $view->calendarCellLink($this->view->t('Occupied'), $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-single');
        } else {
            $reservation = current($reservations);
            $booking = $reservation->needExtra('booking');
            $statusBillingOverride = $reservation->getMeta('status_billing_override');
            $statusBilling = $statusBillingOverride ?: $booking->get('status_billing');
            $bookingStatusColor = $this->bookingStatusService->getStatusColor($statusBilling);

            if ($bookingStatusColor) {
                $cellStyle = 'outline: solid 3px ' . $bookingStatusColor;
            } else {
                $cellStyle = null;
            }

            $bookingUser = $booking->needExtra('user');
            $cellLabel = $bookingUser->need('alias');
            $cellGroup = ' cc-group-' . $booking->need('bid');

            $style = 'cc-single';

            if ($statusBilling == 'pending') {
                if (! $cellLabel) {
                    $cellLabel = $view->t('temp blocked');
                }
                $style = 'cc-try';
            }

            $extraClasses = '';
            $isMember = (bool) $bookingUser->getMeta('member');
            $hasGuestPlayer = ($booking->getMeta('gp') == '1' || $booking->getMeta('guestPlayer') == '1');

            if ($isMember) {
                $extraClasses .= ' cc-member';
                if ($hasGuestPlayer) {
                    $extraClasses .= ' cc-guest'; // MG: Mitglied mit Gastspieler
                }
            } else {
                $extraClasses .= ' cc-guest'; // G: Kein-Mitglied
            }

            switch ($booking->need('status')) {
                case 'single':
                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('backend/booking/edit', [], $cellLinkParams), $style . $cellGroup . $extraClasses, null, $cellStyle);
                case 'subscription':
                    return $view->calendarCellLink($cellLabel, $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-multiple' . $cellGroup . $extraClasses, null, $cellStyle);
            }
        }
    }

}
