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

            // Names are only revealed for subscription bookings, never for
            // regular single bookings (per request: "nur Abos, nicht normale Spieler").
            $showNames = $square->getMeta('public_names', 'false') == 'true'
                || ($square->getMeta('private_names', 'false') == 'true' && $user);

            $cellGroup = ' cc-group-' . $booking->need('bid');

            switch ($booking->need('status')) {
                case 'single':
                    return $view->calendarCellLink($view->escapeHtml($this->view->t('Occupied')), $view->url('square', [], $cellLinkParams), 'cc-single' . $cellGroup);
                case 'subscription':
                    if ($showNames) {
                        $cellLabel = $booking->needExtra('user')->need('alias');
                    } else {
                        $cellLabel = $this->view->t('Subscription');
                    }

                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), 'cc-multiple' . $cellGroup);
            }
        }
    }

}
