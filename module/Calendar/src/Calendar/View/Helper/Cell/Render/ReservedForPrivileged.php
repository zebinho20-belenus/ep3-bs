<?php

namespace Calendar\View\Helper\Cell\Render;

use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;

class ReservedForPrivileged extends AbstractHelper
{

    public function __invoke(array $reservations, array $cellLinkParams, Square $square)
    {
        $view = $this->getView();

        $reservationsCount = count($reservations);

        if ($reservationsCount == 0) {
	        $labelReserved = $square->getMeta('label.reserved', $this->view->t('Reserved'));

            $newBookingParams = $cellLinkParams;
            $newBookingParams['query']['force'] = 'new';
            return $view->calendarCellLink($labelReserved, $view->url('backend/booking/edit', [], $newBookingParams), 'cc-reserved');
        } else if ($reservationsCount == 1) {
            $reservation = current($reservations);
            $booking = $reservation->needExtra('booking');

            $cellLabel = $booking->needExtra('user')->need('alias');
            $cellGroup = ' cc-group-' . $booking->need('bid');

            return $view->calendarCellLink($cellLabel, $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-reserved cc-reserved-partially' . $cellGroup);
        } else {
	        $labelReserved = $square->getMeta('label.reserved', 'Still reserved');

            return $view->calendarCellLink($labelReserved, $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-reserved cc-reserved-partially');
        }
    }

}
