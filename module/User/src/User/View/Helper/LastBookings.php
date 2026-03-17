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

    public $unpaidCount = 0;
    public $upcomingCount = 0;

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
        $this->unpaidCount = 0;
        $this->upcomingCount = 0;

        $userBookings = $this->bookingManager->getByValidity(array(
            'uid' => $user->need('uid'),
        ));

        if (! $userBookings) {
            return '<div><em>' . sprintf($view->t('You have not booked any %s yet.'), $view->option('subject.square.type.plural')) . '</em></div>';
        }

        $this->reservationManager->getByBookings($userBookings);
        $this->bookingBillManager->getByBookings($userBookings);

        $now = new DateTime();
        $yearStart = new DateTime('first day of January this year');
        $yearStart->setTime(0, 0);
        $squareType = $view->option('subject.square.type');

        // Collect future bookings and count unpaid bookings in current year (#71)
        $futureBookings = [];
        $unpaidCount = 0;

        foreach ($userBookings as $booking) {
            $reservations = $booking->needExtra('reservations');

            // Find earliest reservation date for this booking
            $bookingDateTimeStart = null;
            foreach ($reservations as $reservation) {
                $tmpDateTimeStart = new DateTime($reservation->need('date') . ' ' . $reservation->need('time_start'));
                if (is_null($bookingDateTimeStart) || $tmpDateTimeStart < $bookingDateTimeStart) {
                    $bookingDateTimeStart = $tmpDateTimeStart;
                }
            }

            if (! $bookingDateTimeStart) {
                continue;
            }

            // Calculate price
            $bills = $booking->getExtra('bills');
            $price = 0;
            if ($bills) {
                foreach ($bills as $bill) {
                    $price += $bill->need('price');
                }
            }

            $statusBilling = $booking->get('status_billing');

            // Count all unpaid bookings in current year (past + future, #71)
            if ($statusBilling == 'pending' && $price > 0 && $bookingDateTimeStart >= $yearStart) {
                $unpaidCount++;
            }

            if ($bookingDateTimeStart >= $now) {
                // Future booking
                $futureBookings[] = [
                    'booking' => $booking,
                    'dateTimeStart' => $bookingDateTimeStart,
                    'price' => $price,
                    'statusBilling' => $statusBilling,
                ];
            }
        }

        $this->unpaidCount = $unpaidCount;
        $this->upcomingCount = count($futureBookings);

        // Sort future bookings by date ascending, take first 4
        usort($futureBookings, function ($a, $b) {
            return $a['dateTimeStart'] <=> $b['dateTimeStart'];
        });
        $futureBookings = array_slice($futureBookings, 0, 4);

        // Build HTML
        if (! $futureBookings && ! $unpaidCount) {
            return '<div><em>' . $view->t('You have no imminent bookings.') . '</em></div>';
        }

        $html = '';

        if ($futureBookings) {
            $html .= '<ul style=\'padding: 0px 16px 0px 28px;\'>';

            foreach ($futureBookings as $fb) {
                $booking = $fb['booking'];
                $square = $this->squareManager->get($booking->need('sid'));

                $billingInfo = '';
                if ($fb['statusBilling'] == 'pending' && $fb['price'] > 0) {
                    $billingInfo = ' <span style=\'color: #d97706; font-size: 0.85em;\'>(' . $view->t('Pending') . ')</span>';
                } elseif ($fb['statusBilling'] == 'paid') {
                    $billingInfo = ' <span style=\'color: #16a34a; font-size: 0.85em;\'>(' . $view->t('Paid') . ')</span>';
                }

                $html .= sprintf('<li><span class=\'my-highlight\'>%s %s</span> &nbsp; %s%s</li>',
                    $squareType, $view->t($square->need('name')), $view->prettyDate($fb['dateTimeStart']), $billingInfo);
            }

            $html .= '</ul>';
        }

        if ($unpaidCount > 0) {
            $unpaidText = ($unpaidCount == 1)
                ? $view->t('1 unpaid booking')
                : sprintf($view->t('%s unpaid bookings'), $unpaidCount);

            $html .= '<div style=\'color: #dc2626; font-weight: bold; margin-top: 4px; font-size: 0.9em;\'>' . $unpaidText . '</div>';
        }

        return $html;
    }

}
