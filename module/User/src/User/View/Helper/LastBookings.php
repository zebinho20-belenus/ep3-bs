<?php

namespace User\View\Helper;

use Booking\Manager\BookingManager;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use DateTime;
use Square\Manager\SquareManager;
use User\Entity\User;
use Zend\View\Helper\AbstractHelper;

class LastBookings extends AbstractHelper
{

    protected $bookingManager;
    protected $reservationManager;
    protected $squareManager;
    protected $bookingBillManager;

    public function __construct(BookingManager $bookingManager, ReservationManager $reservationManager,
        SquareManager $squareManager, BillManager $bookingBillManager)
    {
        $this->bookingManager = $bookingManager;
        $this->reservationManager = $reservationManager;
        $this->squareManager = $squareManager;
        $this->bookingBillManager = $bookingBillManager;
    }

    public function __invoke(User $user)
    {
        $view = $this->getView();

        $userBookings = $this->bookingManager->getByValidity(array(
            'uid' => $user->need('uid'),
        ));

        if ($userBookings) {
            $this->reservationManager->getByBookings($userBookings);
            $this->bookingBillManager->getByBookings($userBookings);

            $now = new DateTime();

            $lowerLimit = clone $now;
            $lowerLimit->modify('-2 days');

            $upperLimit = clone $now;
            $upperLimit->modify('+28 days');

            $html = '';

            $html .= '<ul style=\'padding: 0px 16px 0px 28px;\'>';

            $bookingsActuallyDisplayed = 0;
            $hasUnpaid = false;

            foreach ($userBookings as $booking) {
                $reservations = $booking->needExtra('reservations');

                $bookingDateTimeStart = null;
                $bookingDateTimeEnd = null;

                foreach ($reservations as $reservation) {
                    $tmpDateTimeStart = new DateTime($reservation->need('date') . ' ' . $reservation->need('time_start'));
                    $tmpDateTimeEnd = new DateTime($reservation->need('date') . ' ' . $reservation->need('time_end'));

                    if (is_null($bookingDateTimeStart) || $tmpDateTimeStart < $bookingDateTimeStart) {
                        $bookingDateTimeStart = $tmpDateTimeStart;
                    }

                    if (is_null($bookingDateTimeEnd) || $tmpDateTimeEnd < $bookingDateTimeStart) {
                        $bookingDateTimeEnd = $tmpDateTimeEnd;
                    }
                }

                if ($bookingDateTimeEnd >= $lowerLimit && $bookingDateTimeStart <= $upperLimit) {
                    $square = $this->squareManager->get($booking->need('sid'));
                    $squareType = $view->option('subject.square.type');

                    // Billing status indicator
                    $statusBilling = $booking->get('status_billing');
                    $bills = $booking->getExtra('bills');
                    $price = 0;
                    if ($bills) {
                        foreach ($bills as $bill) {
                            $price += $bill->need('price');
                        }
                    }

                    $billingInfo = '';
                    if ($statusBilling == 'pending' && $price > 0) {
                        $billingInfo = ' <span style=\'color: #d97706; font-size: 0.85em;\'>(' . $view->t('Pending') . ')</span>';
                        if ($bookingDateTimeStart >= $now) {
                            $hasUnpaid = true;
                        }
                    } elseif ($statusBilling == 'paid') {
                        $billingInfo = ' <span style=\'color: #16a34a; font-size: 0.85em;\'>(' . $view->t('Paid') . ')</span>';
                    }

                    if ($bookingDateTimeStart < $now) {
                        $html .= sprintf('<li class=\'gray\'><s>%s %s &nbsp; %s</s>%s</li>',
                            $squareType, $view->t($square->need('name')), $view->prettyDate($bookingDateTimeStart), $billingInfo);
                    } else {
                        $html .= sprintf('<li><span class=\'my-highlight\'>%s %s</span> &nbsp; %s%s</li>',
                            $squareType, $view->t($square->need('name')), $view->prettyDate($bookingDateTimeStart), $billingInfo);
                    }

                    $bookingsActuallyDisplayed++;
                }
            }

            $html .= '</ul>';

            if (! $bookingsActuallyDisplayed) {
                $html = '<div><em>' . $view->t('You have no imminent bookings.') . '</em></div>';
            } elseif ($hasUnpaid) {
                $html = '<div style=\'color: #d97706; font-weight: bold; margin-bottom: 4px;\'>' . $view->t('You have unpaid bookings') . '</div>' . $html;
            }

            return $html;
        } else {
            return '<div><em>' . sprintf($view->t('You have not booked any %s yet.'), $view->option('subject.square.type.plural')) . '</em></div>';
        }
    }

}
