<?php

namespace Calendar\View\Helper\Cell\Render;

use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;

class OccupiedForVisitors extends AbstractHelper
{

    public function __invoke(array $reservations, array $cellLinkParams, Square $square, $user = null)
    {
        $view = $this->getView();

        $reservationsCount = count($reservations);

        if ($reservationsCount > 1) {
            return $view->calendarCellLink($this->view->t('Occupied'), $view->url('square', [], $cellLinkParams), 'cc-single');
        } else {
            $reservation = current($reservations);
            $booking = $reservation->needExtra('booking');

            // Effective billing status (per-reservation override wins over booking).
            $statusBilling = $reservation->getMeta('status_billing_override') ?: $booking->need('status_billing');

            // The real name is revealed only for training bookings: automatically
            // to any logged-in user, and to visitors only if the square is
            // configured for public names. All other bookings stay anonymous
            // ("Belegt"/"Abo") so regular players' names are never leaked.
            $showNames = ($statusBilling === 'training')
                && ($user || $square->getMeta('public_names', 'false') == 'true');

            $cellGroup = ' cc-group-' . $booking->need('bid');

            switch ($booking->need('status')) {
                case 'single':
                    $cellLabel = $showNames ? $booking->needExtra('user')->need('alias') : $this->view->t('Occupied');

                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), 'cc-single' . $cellGroup);
                case 'subscription':
                    $cellLabel = $showNames ? $booking->needExtra('user')->need('alias') : $this->view->t('Subscription');

                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), 'cc-multiple' . $cellGroup);
            }
        }
    }

}
