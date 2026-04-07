<?php

namespace Calendar\View\Helper;

use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;

class ReservationsForCell extends AbstractHelper
{

    public function __invoke(array $reservationsForCol, Square $square)
    {
        $reservationsForCell = array();

        foreach ($reservationsForCol as $rid => $reservation) {
            if ($reservation->get('status', 'confirmed') == 'cancelled') {
                continue;
            }

            $booking = $reservation->needExtra('booking');

            $effectiveSid = $reservation->getMeta('sid_override') ?: $booking->need('sid');
            if ($effectiveSid == $square->need('sid')) {
                if ($booking->need('status') != 'cancelled') {
                    if ($booking->need('visibility') == 'public') {
                        $reservationsForCell[$rid] = $reservation;
                    }
                }
            }
        }

        return $reservationsForCell;
    }

}
