<?php

namespace Backend\View\Helper\Booking;

use Booking\Entity\Reservation;
use Booking\Manager\BookingManager;
use Booking\Manager\ReservationManager;
use Booking\Service\BookingStatusService;
use Square\Manager\SquareManager;
use Booking\Manager\Booking\BillManager;
use User\Manager\UserManager;
use Zend\View\Helper\AbstractHelper;

class BookingFormat extends AbstractHelper
{

    protected $squareManager;
    protected $bookingBillManager;
    protected $userManager;
    protected $bookingStatusService;
    protected $reservationManager;
    protected $bookingManager;

    public function __construct(SquareManager $squareManager, BillManager $bookingBillManager, UserManager $userManager, BookingStatusService $bookingStatusService, ReservationManager $reservationManager, BookingManager $bookingManager)
    {
        $this->squareManager = $squareManager;
        $this->bookingBillManager = $bookingBillManager;
        $this->userManager = $userManager;
        $this->bookingStatusService = $bookingStatusService;
        $this->reservationManager = $reservationManager;
        $this->bookingManager = $bookingManager;
    }

    public function __invoke(Reservation $reservation, $dateStart = null, $dateEnd = null, $search = null)
    {
        $view = $this->getView();
        $html = '';

        $booking = $reservation->needExtra('booking');
        $reservationCancelled = ($reservation->get('status', 'confirmed') == 'cancelled');

        switch (true) {
            case $booking->need('status') == 'cancelled':
            case $reservationCancelled:
                $attr = ' class="gray"';
                break;
            default:
                $attr = null;
                break;
        }

        $html .= sprintf('<tr %s>', $attr);

        $checkboxStatus = $reservationCancelled ? 'cancelled' : $booking->need('status');
        $html .= sprintf('<td class="centered-text no-print bulk-check-col">'
            . '<input type="checkbox" name="bulk-rids[]" value="%s" class="form-check-input bulk-check" data-status="%s">'
            . '</td>',
            $reservation->get('rid'),
            $checkboxStatus);

        $statusMap = array(
            'single' => array('label' => 'E', 'class' => 'status-icon status-single', 'title' => $view->t('Single')),
            'subscription' => array('label' => 'A', 'class' => 'status-icon status-subscription', 'title' => $view->t('Subscription')),
            'cancelled' => array('label' => 'S', 'class' => 'status-icon status-cancelled', 'title' => $view->t('Cancelled')),
        );

        // Show cancelled status if reservation is cancelled (even if booking is still active)
        // For cancelled reservations within active subscriptions: show "A/S" to link back to the subscription
        if ($reservationCancelled && $booking->need('status') == 'subscription') {
            $statusHtml = sprintf(
                '<span class="status-icon status-subscription" title="%s" style="margin-right: 2px;">A</span>'
                . '<span class="status-icon status-cancelled" title="%s">S</span>',
                $view->t('Subscription'), $view->t('Cancelled'));
        } elseif ($reservationCancelled) {
            $statusKey = 'cancelled';
            $statusInfo = $statusMap[$statusKey];
            $statusHtml = sprintf('<span class="%s" title="%s">%s</span>', $statusInfo['class'], $statusInfo['title'], $statusInfo['label']);
        } else {
            $statusKey = $booking->need('status');
            $statusInfo = isset($statusMap[$statusKey]) ? $statusMap[$statusKey] : array('label' => '?', 'class' => 'status-icon', 'title' => $statusKey);
            $statusHtml = sprintf('<span class="%s" title="%s">%s</span>', $statusInfo['class'], $statusInfo['title'], $statusInfo['label']);
        }

        $html .= sprintf('<td class="status-col centered-text">%s</td>', $statusHtml);

        $html .= sprintf('<td class="nr-col responsive-pass-5">%s</td>',
            $booking->need('bid'));

        if ($booking->getExtra('user')) {
            $userName = $booking->getExtra('user')->get('alias');
        } else {
            $userName = $booking->need('uid');
        }

        $user = $this->userManager->get($booking->need('uid'));

        $html .= sprintf('<td><b>%s</b></td>',
            $userName);

        $member = 0;
        if ($user != null && $user->getMeta('member') != null) {
            $member = $user->getMeta('member');
        }

        if ($member) {
        $html .= sprintf('<td class="member-col responsive-pass-2">%s</td>',
            $view->t('Yes'));
        }
        else {
        $html .= sprintf('<td class="member-col responsive-pass-2">%s</td>',
            $view->t('No'));
        }

        /* Date and time col */

        $date = new \DateTime($reservation->get('date'));

        $fullDate = $view->dateFormat($date, \IntlDateFormatter::FULL);
        $fullDateParts = explode(', ', $fullDate);

        $html .= sprintf('<td class="responsive-pass-3">%s</td>',
            $fullDateParts[0]);

        $html .= sprintf('<td>%s</td>',
            $view->dateFormat($date, \IntlDateFormatter::MEDIUM));

        $html .= sprintf('<td>%s</td>',
            substr($reservation->get('time_start'), 0, 5) . '-' . substr($reservation->get('time_end'), 0, 5));

        /* Square col */

        if ($booking->get('sid')) {
            $squareName = $this->squareManager->get($booking->get('sid'))->get('name');
        } else {
            $squareName = '-';
        }

        $html .= sprintf('<td class="court-col responsive-pass-4">%s</td>',
            $squareName);

        /* Notes col */

        $notes = $booking->getMeta('notes');

        if ($notes) {
            if (strlen($notes) > 48) {
                $notes = substr($notes, 0, 48) . '&hellip;';
            }

            $notes = '<span class="small-text">' . $notes . '</span>';
        } else {
            $notes = '-';
        }

        $html .= sprintf('<td class="notes-col responsive-pass-3">%s</td>',
            $notes);

        /* Price col */

        $price = 0;

        $bills = $this->bookingBillManager->getBy(array('bid' => $booking->need('bid')), 'bbid ASC'); 

        if ($bills) {
            foreach ($bills as $bill) {
               $price += $bill->need('price');
           }
        }

        $html .= sprintf('<td class="price-col responsive-pass-5"><b>%s</b></td>',
            $view->currencyFormat($price / 100));

        /* Billing status col */

        $statusBilling = $booking->get('status_billing');

        if ($statusBilling) {
            $statusCssMap = array(
                'paid' => 'billing-paid',
                'pending' => 'billing-pending',
                'cancelled' => 'billing-cancelled',
                'uncollectable' => 'billing-uncollectable',
                'member' => 'billing-member',
            );

            $cssClass = isset($statusCssMap[$statusBilling]) ? $statusCssMap[$statusBilling] : 'billing-pending';

            if ($statusBilling === 'member') {
                $statusTitle = $view->t('Member');
            } else {
                $statusTitle = $view->t($this->bookingStatusService->getStatusTitle($statusBilling));
            }

            $html .= sprintf('<td class="responsive-pass-2"><span class="billing-badge %s">%s</span></td>', $cssClass, $statusTitle);
        } else {
            $html .= '<td class="responsive-pass-2">-</td>';
        }

        /* Payment method col */

        $paymentMethodMap = array(
            'paypal' => 'PayPal',
            'stripe' => 'Stripe',
            'klarna' => 'Klarna',
            'budget' => $view->t('Budget'),
            'paylater' => $view->t('Pay later'),
            'member' => $view->t('Member'),
        );
        $paymentMethod = $booking->getMeta('paymentMethod', '');
        $paymentMethodLabel = isset($paymentMethodMap[$paymentMethod]) ? $paymentMethodMap[$paymentMethod] : ($paymentMethod ?: '-');

        $html .= sprintf('<td class="payment-method-col responsive-pass-3">%s</td>',
            $paymentMethodLabel);

        /* Actions col */

        $editUrl = $view->url('backend/booking/edit', [], ['query' => [
            'ds' => $date->format('Y-m-d'),
            'ts' => substr($reservation->get('time_start'), 0, 5),
            'te' => substr($reservation->get('time_end'), 0, 5),
            's' => $booking->get('sid'),
            'r' => $reservation->get('rid')]]);

        $deleteUrl = $view->url('backend/booking/delete', ['rid' => $reservation->get('rid')]);

        $isCancelledBookingOrReservation = ($booking->get('status') == 'cancelled' || $reservationCancelled);

        if ($isCancelledBookingOrReservation) {

            // Check if user has reactivation permission
            $sessionUser = $this->getView()->sessionUser();
            $canReactivatePermission = $sessionUser && $sessionUser->can('calendar.reactivate-bookings');

            // Check if time slot is free for reactivation
            $canReactivate = true;
            $dateTimeStart = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_start'));
            $dateTimeEnd = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_end'));
            $overlapping = $this->reservationManager->getInRange($dateTimeStart, $dateTimeEnd);

            if ($overlapping) {
                $this->bookingManager->getByReservations($overlapping);
                foreach ($overlapping as $overlapRes) {
                    $overlapBooking = $overlapRes->getExtra('booking');
                    if ($overlapBooking
                        && $overlapBooking->get('bid') != $booking->get('bid')
                        && $overlapBooking->get('sid') == $booking->get('sid')
                        && $overlapBooking->get('status') != 'cancelled') {
                        $canReactivate = false;
                        break;
                    }
                }
            }

            // For cancelled reservations within active bookings, use reservation-level reactivation
            if ($reservationCancelled && $booking->get('status') != 'cancelled') {
                $reactivateUrl = $view->url('backend/booking/delete', ['rid' => $reservation->get('rid')], ['query' => ['edit-mode' => 'reservation', 'reactivate' => 'true']]);
                $deleteActionLabel = $view->t('Delete');
            } else {
                $reactivateUrl = $view->url('backend/booking/delete', ['rid' => $reservation->get('rid')], ['query' => ['confirmed' => 'true', 'reactivate' => 'true']]);
                $deleteActionLabel = $view->t('Delete');
            }

            if ($canReactivate && $canReactivatePermission) {
                $html .= sprintf('<td class="actions-col no-print">'
                    . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-edit"></a> '
                    . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-reload"></a> '
                    . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-cross"></a>'
                    . '</td>',
                    $editUrl, $view->t('Edit'), $view->t('Edit'),
                    $reactivateUrl, $view->t('Reactivate'), $view->t('Reactivate'),
                    $deleteUrl, $deleteActionLabel, $deleteActionLabel);
            } else {
                $html .= sprintf('<td class="actions-col no-print">'
                    . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-edit"></a> '
                    . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-cross"></a>'
                    . '</td>',
                    $editUrl, $view->t('Edit'), $view->t('Edit'),
                    $deleteUrl, $deleteActionLabel, $deleteActionLabel);
            }

        } else {

            $html .= sprintf('<td class="actions-col no-print">'
                . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-edit"></a> '
                . '<a href="%s" title="%s" aria-label="%s" class="unlined gray symbolic symbolic-cross"></a>'
                . '</td>',
                $editUrl, $view->t('Edit'), $view->t('Edit'),
                $deleteUrl, $view->t('Cancel booking'), $view->t('Cancel booking'));
        }

        $html .= '</tr>';

        return $html;
    }

}
