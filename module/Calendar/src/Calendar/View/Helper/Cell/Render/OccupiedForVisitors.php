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

            if ($square->getMeta('public_names', 'false') == 'true') {
                $cellLabel = $booking->needExtra('user')->need('alias');
            } else if ($square->getMeta('private_names', 'false') == 'true' && $user) {
                $cellLabel = $booking->needExtra('user')->need('alias');
            } else {
                $cellLabel = null;
            }

            $cellGroup = ' cc-group-' . $booking->need('bid');

            $style = 'cc-single';

            if ($booking->getMeta('directpay') == 'true' and $booking->get('status_billing')!= 'paid') {
                if (! $cellLabel) {
                    $cellLabel = $view->t('temp blocked');
                }
                $style = 'cc-try';
            }

            switch ($booking->need('status')) {
                case 'single':
                    if (! $cellLabel) {
                        $cellLabel = $this->view->t('Occupied');
                    }

                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), $style . $cellGroup);
                case 'subscription':
                    if (! $cellLabel) {
                        $cellLabel = $this->view->t('Subscription');
                    }

                    return $view->calendarCellLink($view->escapeHtml($cellLabel), $view->url('square', [], $cellLinkParams), 'cc-multiple' . $cellGroup);
            }
        }
    }

}
