<?php

namespace Backend\Controller;

use Booking\Entity\Booking;
use User\Entity\User;
use Booking\Table\BookingTable;
use Booking\Table\ReservationTable;
use DateTime;
use Zend\Db\Adapter\Adapter;
use Zend\Mvc\Controller\AbstractActionController;
use GuzzleHttp\Client;
use Stripe\Webhook;
use Stripe\Exception;

class BookingController extends AbstractActionController
{

    public function indexAction()
    {
        $sessionUser = $this->authorize('admin.booking');

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $bookings = array();
        $reservations = array();

        $dateStart = $this->params()->fromQuery('date-start');
        $dateEnd = $this->params()->fromQuery('date-end');
        $search = $this->params()->fromQuery('search');

        if ($dateStart) {
            $dateStart = new \DateTime($dateStart);
        }

        if ($dateEnd) {
            $dateEnd = new \DateTime($dateEnd);
            $dateEnd->setTime(23, 59);
        }

        // Default to ±2 weeks from today when no dates provided (#55)
        if (!$dateStart && !$dateEnd) {
            $dateStart = new \DateTime();
            $dateStart->modify('-2 weeks');
            $dateEnd = new \DateTime();
            $dateEnd->modify('+2 weeks');
            $dateEnd->setTime(23, 59);
        }

        if (($dateStart && $dateEnd) || $search) {
            $filters = $this->backendBookingDetermineFilters($search);

            try {
                // $limit = 10000;
                $limit = null;

                if ($dateStart && $dateEnd) {
                    $reservations = $reservationManager->getInRange($dateStart, $dateEnd, $limit);
                    $bookings = $bookingManager->getByReservations($reservations, $filters['filters']);
                } else {
                    $bookings = $bookingManager->getBy($filters['filters'], null, $limit);
                }

                $bookings = $this->complexFilterBookings($bookings, $filters);
                $reservations = $reservationManager->getByBookings($bookings);

                // Filter reservations to only include those within the requested date range
                // (getByBookings fetches ALL reservations for matched bookings, which for
                // subscription bookings includes reservations outside the date range)
                if ($dateStart && $dateEnd) {
                    $reservations = array_filter($reservations, function($reservation) use ($dateStart, $dateEnd) {
                        $resDate = new \DateTime($reservation->get('date'));
                        return $resDate >= $dateStart && $resDate <= $dateEnd;
                    });
                }

                $userManager->getByBookings($bookings);
            } catch (\RuntimeException $e) {
                $bookings = array();
                $reservations = array();
            }
        }

        return array(
            'bookings' => $bookings,
            'reservations' => $reservations,
            'dateStart' => $dateStart,
            'dateEnd' => $dateEnd,
            'search' => $search,
            'sessionUser' => $sessionUser,
        );
    }

    protected function complexFilterBookings($bookings, $filters)
    {
        $serviceManager = @$this->getServiceLocator();

        foreach ($filters['filterParts'] as $filterPart) {

            // Filter for billing total
            if ($filterPart[0] == str_replace(' ', '_', strtolower($this->t('Billing total')))) {
                $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
                $bookingBillManager->getByBookings($bookings);

                $bookings = array_filter($bookings, function(Booking $booking) use ($filterPart) {
                    switch ($filterPart[1]) {
                        case '=':
                            return $booking->getExtra('bills_total') == (int) $filterPart[2];
                        case '>':
                            return $booking->getExtra('bills_total') > (int) $filterPart[2];
                        case '<':
                            return $booking->getExtra('bills_total') < (int) $filterPart[2];
                        default:
                            return false;
                    }
                });
            }
        }

        return $bookings;
    }

    public function editAction()
    {
        $sessionUser = $this->authorize('admin.booking, calendar.see-data');

        $params = $this->backendBookingDetermineParams(true);

        $reservation = $booking = null;

        $editMode = null;      

        if (! ($this->getRequest()->isPost() || $this->params()->fromQuery('force') == 'new')) {
            switch (count($params['reservations'])) {
                case 0:
                    break;
                case 1:
                    $reservation = current($params['reservations']);
                    $booking = $reservation->getExtra('booking');

                    if ($booking->get('status') == 'subscription' && $sessionUser->can(['calendar.create-subscription-bookings', 'calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings'])) {
                        if (! $params['editMode']) {
                            return $this->forward()->dispatch('Backend\Controller\Booking', ['action' => 'editMode', 'params' => $params]);
                        }
                    }
                    break;
                default:
                    return $this->forward()->dispatch('Backend\Controller\Booking', ['action' => 'editChoice', 'params' => $params]);
            }
        }

        $serviceManager = @$this->getServiceLocator();
        $formElementManager = $serviceManager->get('FormElementManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');

        $editForm = $formElementManager->get('Backend\Form\Booking\EditForm');


        if ($this->getRequest()->isPost()) {
            $editForm->setData($this->params()->fromPost());

            if ($editForm->isValid()) {
                $d = $editForm->getData();

                /* Reactivate cancelled booking */

                if ($this->params()->fromPost('bf-reactivate') && $d['bf-rid'] && $sessionUser->can('admin.booking')) {
                    $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
                    $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
                    $userManager = $serviceManager->get('User\Manager\UserManager');

                    $reactivateReservation = $reservationManager->get($d['bf-rid']);
                    $reactivateBooking = $bookingManager->get($reactivateReservation->get('bid'));

                    if ($reactivateBooking->get('status') == 'cancelled') {

                        // Check if time slot is still free
                        $dateTimeStart = new \DateTime($reactivateReservation->get('date') . ' ' . $reactivateReservation->get('time_start'));
                        $dateTimeEnd = new \DateTime($reactivateReservation->get('date') . ' ' . $reactivateReservation->get('time_end'));
                        $overlapping = $reservationManager->getInRange($dateTimeStart, $dateTimeEnd);

                        if ($overlapping) {
                            $conflictBookings = $bookingManager->getByReservations($overlapping);
                            $hasConflict = false;
                            foreach ($overlapping as $overlapRes) {
                                $overlapBooking = $overlapRes->getExtra('booking');
                                if ($overlapBooking
                                    && $overlapBooking->get('bid') != $reactivateBooking->get('bid')
                                    && $overlapBooking->get('sid') == $reactivateBooking->get('sid')
                                    && $overlapBooking->get('status') != 'cancelled') {
                                    $hasConflict = true;
                                    break;
                                }
                            }
                            if ($hasConflict) {
                                $this->flashMessenger()->addErrorMessage('This time slot is already occupied by another booking');
                                return $this->redirect()->toRoute('backend/booking/edit', [], ['query' => [
                                    'ds' => $reactivateReservation->get('date'),
                                    'ts' => substr($reactivateReservation->get('time_start'), 0, 5),
                                    'te' => substr($reactivateReservation->get('time_end'), 0, 5),
                                    's'  => $reactivateBooking->get('sid'),
                                    'r'  => $reactivateReservation->get('rid'),
                                ]]);
                            }
                        }

                        $reactivateBooking->set('status', 'single');
                        $reactivateBooking->setMeta('cancellor', null);
                        $reactivateBooking->setMeta('cancelled', null);
                        $reactivateBooking->setMeta('admin_cancelled', null);
                        $reactivateBooking->setMeta('backend_cancelled', null);
                        $reactivateBooking->setMeta('reactivated_by', $sessionUser->get('alias'));
                        $reactivateBooking->setMeta('reactivated', date('Y-m-d H:i:s'));
                        $bookingManager->save($reactivateBooking);

                        // Send reactivation email to user and admin
                        try {
                            $bookingUser = $userManager->get($reactivateBooking->get('uid'));
                            $this->sendReactivationEmail($reactivateBooking, $bookingUser, $sessionUser);
                        } catch (\Exception $e) {
                            // Continue despite errors
                        }

                        $this->flashMessenger()->addSuccessMessage('Booking has been reactivated');

                        return $this->redirect()->toRoute('frontend', [], ['query' => [
                            'date' => $reactivateReservation->get('date'),
                        ]]);
                    }
                }

                /* Process form (note, that reservation and booking are not available here) */

                if ($d['bf-rid']) {

                    /* Update booking/reservation */

                    $updateResult = $this->backendBookingUpdate($d['bf-rid'], $d['bf-user'], $d['bf-time-start'], $d['bf-time-end'], $d['bf-date-start'],
                        $d['bf-sid'], $d['bf-status-billing'], $d['bf-quantity'], $d['bf-notes'], $params['editMode']);

                    $savedBooking = $updateResult['booking'];
                    $oldData = $updateResult['oldData'];

                    /* Save guest player meta */
                    $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
                    $savedBooking->setMeta('gp', $d['bf-guest-player'] ? '1' : '0');
                    $savedBooking->setMeta('guestPlayer', $d['bf-guest-player'] ? '1' : '0');
                    $bookingManager->save($savedBooking);

                    /* Save player names from form */
                    $playerNames = [];
                    for ($i = 2; $i <= 4; $i++) {
                        $name = trim($this->params()->fromPost('bf-player-name-' . $i, ''));
                        if ($name) {
                            $playerNames[] = ['value' => $name];
                        }
                    }
                    if (!empty($playerNames)) {
                        $savedBooking->setMeta('player-names', json_encode($playerNames));
                    } else {
                        $savedBooking->setMeta('player-names', null);
                    }
                    $bookingManager->save($savedBooking);

                    /* Recalculate bill if guest player status changed */
                    $oldGp = $oldData['gp'];
                    $newGp = $d['bf-guest-player'] ? '1' : '0';

                    if ($oldGp !== $newGp) {
                        /* Update player names: add/remove "Gastspieler" suffix */
                        $storedNames = $savedBooking->getMeta('player-names');
                        if ($storedNames) {
                            $namesList = json_decode($storedNames, true) ?: @unserialize($storedNames);
                            if (is_array($namesList)) {
                                foreach ($namesList as &$entry) {
                                    if (isset($entry['value'])) {
                                        $name = trim($entry['value']);
                                        $hasSuffix = (bool) preg_match('/\s+Gastspieler$/', $name);
                                        if ($newGp === '1' && !$hasSuffix && $name !== '') {
                                            $entry['value'] = $name . ' Gastspieler';
                                        } elseif ($newGp === '0' && $hasSuffix) {
                                            $entry['value'] = preg_replace('/\s+Gastspieler$/', '', $name);
                                        }
                                    }
                                }
                                unset($entry);
                                $savedBooking->setMeta('player-names', json_encode($namesList));
                                $bookingManager->save($savedBooking);
                            }
                        }

                        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
                        $squarePricingManager = $serviceManager->get('Square\Manager\SquarePricingManager');
                        $reservationManager2 = $serviceManager->get('Booking\Manager\ReservationManager');
                        $userManager2 = $serviceManager->get('User\Manager\UserManager');

                        $billUser = $userManager2->get($savedBooking->get('uid'));
                        $billSquare = $squareManager->get($savedBooking->get('sid'));
                        $squareType = $this->option('subject.square.type');
                        $squareName = $this->t($billSquare->need('name'));
                        $dateRangeHelper = $serviceManager->get('ViewHelperManager')->get('DateRange');

                        $member = $billUser && $billUser->getMeta('member') ? 1 : 0;
                        $guestPlayer = $newGp === '1';

                        // Calculate old total before deleting bills (for potential refund)
                        $oldTotal = 0;
                        $existingBills = $bookingBillManager->getBy(['bid' => $savedBooking->need('bid')], 'bbid ASC');
                        if ($existingBills) {
                            foreach ($existingBills as $existingBill) {
                                $oldTotal += $existingBill->get('price');
                                $bookingBillManager->delete($existingBill->need('bbid'));
                            }
                        }

                        // Recreate bills with correct pricing
                        foreach ($reservationManager2->getBy(['bid' => $savedBooking->need('bid')]) as $res) {
                            $dtStart = new \DateTime($res->get('date') . ' ' . $res->get('time_start'));
                            $dtEnd = new \DateTime($res->get('date') . ' ' . $res->get('time_end'));

                            if ($guestPlayer && $member) {
                                $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $billSquare, $savedBooking->get('quantity'), 0);
                                if ($pricing) {
                                    $pricing['price'] = intval($pricing['price'] / 2);
                                }
                            } elseif ($guestPlayer) {
                                $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $billSquare, $savedBooking->get('quantity'), 0);
                            } else {
                                $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $billSquare, $savedBooking->get('quantity'), $member);
                            }

                            if ($pricing) {
                                $description = sprintf('%s %s, %s', $squareType, $squareName, $dateRangeHelper($dtStart, $dtEnd));

                                $bookingBillManager->save(new Booking\Bill(array(
                                    'bid' => $savedBooking->need('bid'),
                                    'description' => $description,
                                    'quantity' => $savedBooking->get('quantity'),
                                    'time' => $pricing['seconds'],
                                    'price' => $pricing['price'],
                                    'rate' => $pricing['rate'],
                                    'gross' => $pricing['gross'],
                                )));
                            }
                        }

                        /* Update billing status and budget after GP change */
                        $newBills = $bookingBillManager->getBy(['bid' => $savedBooking->need('bid')], 'bbid ASC');
                        $newTotal = 0;
                        if ($newBills) {
                            foreach ($newBills as $bill) {
                                $newTotal += $bill->get('price');
                            }
                        }

                        if ($newTotal > 0 && $savedBooking->get('status_billing') === 'member') {
                            // Price increased from 0 (member) → check budget
                            $userBudget = (float) $billUser->getMeta('budget', 0);
                            $totalEur = $newTotal / 100;

                            if ($userBudget >= $totalEur) {
                                // Budget sufficient → deduct and mark paid
                                $newBudget = $userBudget - $totalEur;
                                $billUser->setMeta('budget', number_format($newBudget, 2, '.', ''));
                                $userManager2->save($billUser);
                                $savedBooking->set('status_billing', 'paid');
                                $savedBooking->setMeta('budgetpayment', 'true');
                                $savedBooking->setMeta('hasBudget', 'true');
                                $savedBooking->setMeta('budget', number_format($userBudget, 2, '.', ''));
                                $savedBooking->setMeta('newbudget', number_format($newBudget, 2, '.', ''));
                            } else {
                                // Budget insufficient → mark pending
                                $savedBooking->set('status_billing', 'pending');
                            }
                            $bookingManager->save($savedBooking);
                        } elseif ($newTotal == 0 && $savedBooking->get('status_billing') !== 'member') {
                            // Price dropped to 0 (guest removed) → refund budget if paid via budget
                            if ($savedBooking->get('status_billing') === 'paid' && $savedBooking->getMeta('budgetpayment') === 'true') {
                                $refundAmount = $oldTotal / 100;
                                $currentBudget = (float) $billUser->getMeta('budget', 0);
                                $billUser->setMeta('budget', number_format($currentBudget + $refundAmount, 2, '.', ''));
                                $userManager2->save($billUser);
                                $savedBooking->setMeta('budgetrefund', 'true');
                                $savedBooking->setMeta('refundedAmount', number_format($refundAmount, 2, '.', ''));
                            }
                            $savedBooking->set('status_billing', 'member');
                            $savedBooking->setMeta('budgetpayment', null);
                            $savedBooking->setMeta('hasBudget', null);
                            $bookingManager->save($savedBooking);
                        }
                    }

                    $bid = $savedBooking->get('bid');
                    $square = $squareManager->get($savedBooking->get('sid'));

                    if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                            $squareControlService->updateDoorCode($bid);
                    }

                    /* Detect changes and send edit notification email */

                    $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
                    $reservations = $reservationManager->getBy(['bid' => $savedBooking->need('bid')], 'date ASC', 1);
                    $currentReservation = !empty($reservations) ? current($reservations) : null;

                    $newData = array(
                        'sid' => $savedBooking->get('sid'),
                        'uid' => $savedBooking->get('uid'),
                        'status_billing' => $savedBooking->get('status_billing'),
                        'quantity' => $savedBooking->get('quantity'),
                        'notes' => $savedBooking->getMeta('notes', ''),
                        'date' => $currentReservation ? $currentReservation->get('date') : $oldData['date'],
                        'time_start' => $currentReservation ? $currentReservation->get('time_start') : $oldData['time_start'],
                        'time_end' => $currentReservation ? $currentReservation->get('time_end') : $oldData['time_end'],
                        'gp' => $savedBooking->getMeta('gp', '0'),
                    );

                    $changes = array();
                    foreach ($oldData as $key => $oldValue) {
                        if (isset($newData[$key]) && $oldValue != $newData[$key]) {
                            $changes[$key] = array('old' => $oldValue, 'new' => $newData[$key]);
                        }
                    }

                    if (!empty($changes)) {
                        try {
                            $userManager = $serviceManager->get('User\Manager\UserManager');
                            $bookingUser = $userManager->get($savedBooking->get('uid'));
                            $this->sendAdminBookingEditEmail($savedBooking, $bookingUser, $changes, $sessionUser, $squareManager);
                        } catch (\Exception $e) {
                            // Continue despite errors
                        }
                    }

                    /* Check for double-booking after save (warn but don't prevent) */
                    $updatedReservation = $reservationManager->get($d['bf-rid']);
                    $dtStart = new \DateTime($updatedReservation->get('date') . ' ' . $updatedReservation->get('time_start'));
                    $dtEnd = new \DateTime($updatedReservation->get('date') . ' ' . $updatedReservation->get('time_end'));
                    $overlapping = $reservationManager->getInRange($dtStart, $dtEnd);

                    if ($overlapping) {
                        $bookingManager->getByReservations($overlapping);
                        foreach ($overlapping as $overlapRes) {
                            $overlapBooking = $overlapRes->getExtra('booking');
                            if ($overlapBooking
                                && $overlapBooking->get('bid') != $savedBooking->get('bid')
                                && $overlapBooking->get('sid') == $savedBooking->get('sid')
                                && $overlapBooking->get('status') != 'cancelled') {
                                $this->flashMessenger()->addInfoMessage(
                                    'Warning: This time slot is also occupied by another booking (double booking)');
                                break;
                            }
                        }
                    }

                } else {

                    /* Create booking/reservation */

                    $savedBooking = $this->backendBookingCreate($d['bf-user'], $d['bf-time-start'], $d['bf-time-end'], $d['bf-date-start'], $d['bf-date-end'],
                        $d['bf-repeat'], $d['bf-sid'], $d['bf-status-billing'], $d['bf-quantity'], $d['bf-notes'], $sessionUser->get('alias'));
          
                    // Get the user object for the booking
                    $userManager = $serviceManager->get('User\Manager\UserManager');
                    $user = $userManager->get($savedBooking->get('uid'));
                    
                    // Store the admin user information and guest player meta in the booking metadata
                    $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
                    $savedBooking->setMeta('creator', $sessionUser->get('alias'));
                    $savedBooking->setMeta('created', date('Y-m-d H:i:s'));
                    $savedBooking->setMeta('admin_created', 'true');
                    $savedBooking->setMeta('gp', $d['bf-guest-player'] ? '1' : '0');
                    $savedBooking->setMeta('guestPlayer', $d['bf-guest-player'] ? '1' : '0');
                    $bookingManager->save($savedBooking);

                    /* Create bill with pricing (incl. guest player pricing) */
                    $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
                    $squarePricingManager = $serviceManager->get('Square\Manager\SquarePricingManager');
                    $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');

                    $square = $squareManager->get($savedBooking->get('sid'));
                    $squareType = $this->option('subject.square.type');
                    $squareName = $this->t($square->need('name'));
                    $dateRangeHelper = $serviceManager->get('ViewHelperManager')->get('DateRange');

                    $member = $user && $user->getMeta('member') ? 1 : 0;
                    $guestPlayer = $d['bf-guest-player'] ? true : false;

                    foreach ($reservationManager->getBy(['bid' => $savedBooking->need('bid')]) as $res) {
                        $dtStart = new \DateTime($res->get('date') . ' ' . $res->get('time_start'));
                        $dtEnd = new \DateTime($res->get('date') . ' ' . $res->get('time_end'));

                        if ($guestPlayer && $member) {
                            // Member with guest: 50% of non-member price
                            $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $square, $savedBooking->get('quantity'), 0);
                            if ($pricing) {
                                $pricing['price'] = intval($pricing['price'] / 2);
                            }
                        } elseif ($guestPlayer) {
                            // Non-member with guest: full non-member price
                            $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $square, $savedBooking->get('quantity'), 0);
                        } else {
                            // Normal booking: member or non-member price from DB
                            $pricing = $squarePricingManager->getFinalPricingInRange($dtStart, $dtEnd, $square, $savedBooking->get('quantity'), $member);
                        }

                        if ($pricing) {
                            $description = sprintf('%s %s, %s', $squareType, $squareName, $dateRangeHelper($dtStart, $dtEnd));

                            $bookingBillManager->save(new Booking\Bill(array(
                                'bid' => $savedBooking->need('bid'),
                                'description' => $description,
                                'quantity' => $savedBooking->get('quantity'),
                                'time' => $pricing['seconds'],
                                'price' => $pricing['price'],
                                'rate' => $pricing['rate'],
                                'gross' => $pricing['gross'],
                            )));
                        }
                    }

                    // Send booking creation email
                    $this->sendAdminBookingCreationEmail($savedBooking, $user);
                    
                }

                $this->flashMessenger()->addSuccessMessage('Booking has been saved');

                if ($this->params()->fromPost('bf-edit-user')) {
                    return $this->redirect()->toRoute('backend/user/edit', ['uid' => $savedBooking->get('uid')], ['query' => []]);
                } else if ($this->params()->fromPost('bf-edit-bills')) {
                    return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $savedBooking->get('bid')], ['query' => []]);
                } else {
                    return $this->redirect()->toRoute('frontend', [], ['query' => []]);
                }
            }
        } else {
            if ($booking) {
                $user = $booking->needExtra('user');

                $editForm->setData(array(
                    'bf-rid' => $reservation->get('rid'),
                    'bf-user' => $user->need('alias') . ' (' . $user->need('uid') . ')',
                    'bf-sid' => $booking->get('sid'),
                    'bf-status-billing' => $booking->get('status_billing'),
                    'bf-quantity' => $booking->get('quantity'),
                    'bf-guest-player' => $booking->getMeta('gp', '0') == '1' ? '1' : '0',
                    'bf-notes' => $booking->getMeta('notes', ''),
                ));

                if ($booking->get('status') == 'subscription' && $params['editMode'] == 'booking') {
                    $editForm->setData(array(
                        'bf-time-start' => substr($booking->getMeta('time_start', $reservation->get('time_start')), 0, 5),
                        'bf-time-end' => substr($booking->getMeta('time_end', $reservation->get('time_end')), 0, 5),
                        'bf-date-start' => $this->dateFormat($booking->getMeta('date_start', $reservation->get('date')), \IntlDateFormatter::MEDIUM),
                        'bf-date-end' => $this->dateFormat($booking->getMeta('date_end', $reservation->get('date')), \IntlDateFormatter::MEDIUM),
                        'bf-repeat' => $booking->getMeta('repeat', ''),
                    ));
                } else {
                    $editForm->setData(array(
                        'bf-time-start' => substr($reservation->get('time_start'), 0, 5),
                        'bf-time-end' => substr($reservation->get('time_end'), 0, 5),
                        'bf-date-start' => $this->dateFormat($reservation->get('date'), \IntlDateFormatter::MEDIUM),
                        'bf-date-end' => $this->dateFormat($reservation->get('date'), \IntlDateFormatter::MEDIUM),
                    ));
                }
            } else {
                $timeEnd = $params['dateTimeEnd']->format('H:i');

                if ($timeEnd == '00:00') {
                    $timeEnd = '24:00';
                }

                $editForm->setData(array(
                    'bf-sid' => $params['square']->get('sid'),
                    'bf-date-start' => $this->dateFormat($params['dateTimeStart'], \IntlDateFormatter::MEDIUM),
                    'bf-date-end' => $this->dateFormat($params['dateTimeEnd'], \IntlDateFormatter::MEDIUM),
                    'bf-time-start' => $params['dateTimeStart']->format('H:i'),
                    'bf-time-end' => $timeEnd,
                ));
            }
        }

        if ($booking && $booking->getMeta('player-names')) {
            $editForm->get('bf-quantity')->setLabel(sprintf('%s (<a href="%s">%s</a>)',
                $this->translate('Number of players'),
                $this->url()->fromRoute('backend/booking/players', ['bid' => $booking->need('bid')]),
                $this->translate('Who?')));
            $editForm->get('bf-quantity')->setLabelOption('disable_html_escape', true);

            $playerNameNotes = '';
            $playerNames = $booking->getMeta('player-names');

            if ($playerNames) {
                $playerNamesUnserialized = json_decode($booking->getMeta('player-names'), true) ?: @unserialize($booking->getMeta('player-names'));

                if ($playerNamesUnserialized && is_array($playerNamesUnserialized)) {
                    foreach ($playerNamesUnserialized as $i => $playerName) {
                        $playerNameNotes .= sprintf('<div>%s. %s</div>',
                            $i + 1, $playerName['value']);
                    }
                }
            }

            $editForm->get('bf-quantity')->setOption('notes', $playerNameNotes);
        }

        /* Extract player names for view */
        $playerNamesForView = [];
        if ($booking && $booking->getMeta('player-names')) {
            $unserialized = json_decode($booking->getMeta('player-names'), true) ?: @unserialize($booking->getMeta('player-names'));
            if (is_array($unserialized)) {
                foreach ($unserialized as $i => $entry) {
                    $playerNamesForView[$i + 2] = $entry['value'];
                }
            }
        }

        /* Calculate bill total for display (#67) */
        $billTotal = null;
        if ($booking) {
            $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
            $bills = $bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
            if ($bills) {
                $billTotal = 0;
                foreach ($bills as $bill) {
                    $billTotal += $bill->need('price');
                }
            }
        }

        if (! $sessionUser->can(['calendar.create-subscription-bookings'])) {
            return $this->ajaxViewModel(array_merge($params, array(
            'editMode' => 'no_subscr',
            'editForm' => $editForm,
            'booking' => $booking,
            'reservation' => $reservation,
            'sessionUser' => $sessionUser,
            'playerNames' => $playerNamesForView,
            'billTotal' => $billTotal,
            )));
        }

        return $this->ajaxViewModel(array_merge($params, array(
            'editForm' => $editForm,
            'booking' => $booking,
            'reservation' => $reservation,
            'sessionUser' => $sessionUser,
            'playerNames' => $playerNamesForView,
            'billTotal' => $billTotal,
        )));
    }

    public function editChoiceAction()
    {
        $params = $this->getEvent()->getRouteMatch()->getParam('params');

        return $this->ajaxViewModel($params);
    }

    public function editModeAction()
    {
        $params = $this->getEvent()->getRouteMatch()->getParam('params');

        return $this->ajaxViewModel($params);
    }

    public function editRangeAction()
    {
        $this->authorize('admin.booking, calendar.create-subscription-bookings + calendar.cancel-subscription-bookings');

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $formElementManager = $serviceManager->get('FormElementManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');

        $bid = $this->params()->fromRoute('bid');

        $booking = $bookingManager->get($bid);
        $square = $squareManager->get($booking->get('sid'));

        if ($booking->get('status') != 'subscription') {
            throw new \RuntimeException('Time and date range can only be edited on subscription bookings');
        }

        $editTimeRangeForm = $formElementManager->get('Backend\Form\Booking\Range\EditTimeRangeForm');
        $editDateRangeForm = $formElementManager->get('Backend\Form\Booking\Range\EditDateRangeForm');

        if ($this->getRequest()->isPost()) {
            $db = $serviceManager->get('Zend\Db\Adapter\Adapter');

            $mode = $this->params()->fromQuery('mode');

            if ($mode == 'time') {
                $editTimeRangeForm->setData($this->params()->fromPost());

                if ($editTimeRangeForm->isValid()) {
                    $data = $editTimeRangeForm->getData();

                    $res = $db->query(
                        sprintf('UPDATE %s SET time_start = "%s", time_end = "%s" WHERE bid = %s AND time_start = "%s" AND time_end = "%s"',
                            ReservationTable::NAME,
                            $data['bf-time-start'], $data['bf-time-end'], $bid, $booking->needMeta('time_start'), $booking->needMeta('time_end')),
                        Adapter::QUERY_MODE_EXECUTE);

                    if ($res->getAffectedRows() > 0) {
                        $booking->setMeta('time_start', $data['bf-time-start']);
                        $booking->setMeta('time_end', $data['bf-time-end']);

                        $bookingManager->save($booking);

                        if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                            $squareControlService->updateDoorCode($bid);
                        }
                    }

                    $this->flashMessenger()->addSuccessMessage('Booking has been saved');

                    return $this->redirect()->toRoute('frontend', [], ['query' => []]);
                }
            } else if ($mode == 'date') {
                $editDateRangeForm->setData($this->params()->fromPost());

                if ($editDateRangeForm->isValid()) {
                    $data = $editDateRangeForm->getData();

                    $dateStart = new \DateTime($data['bf-date-start']);
                    $dateEnd = new \DateTime($data['bf-date-end']);
                    $repeat = $data['bf-repeat'];

                    $res = $db->query(
                        sprintf('DELETE FROM %s WHERE bid = %s',
                            ReservationTable::NAME, $bid),
                        Adapter::QUERY_MODE_EXECUTE);

                    if ($res->getAffectedRows() > 0) {
                        $reservationManager->createByRange($booking, $dateStart, $dateEnd,
                            $booking->needMeta('time_start'), $booking->needMeta('time_end'), $repeat);

                        $booking->setMeta('date_start', $dateStart->format('Y-m-d'));
                        $booking->setMeta('date_end', $dateEnd->format('Y-m-d'));
                        $booking->setMeta('repeat', $repeat);

                        $bookingManager->save($booking);

                        if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                            $squareControlService->updateDoorCode($bid);
                        }
                    }

                    $this->flashMessenger()->addSuccessMessage('Booking has been saved');

                    return $this->redirect()->toRoute('frontend', [], ['query' => []]);
                }
            } else {
                throw new \RuntimeException('Invalid edit mode received');
            }
        } else {
            $editTimeRangeForm->setData(array(
                'bf-time-start' => substr($booking->needMeta('time_start'), 0, 5),
                'bf-time-end' => substr($booking->needMeta('time_end'), 0, 5),
            ));

            $editDateRangeForm->setData(array(
                'bf-date-start' => $this->dateFormat($booking->needMeta('date_start'), \IntlDateFormatter::MEDIUM),
                'bf-date-end' => $this->dateFormat($booking->needMeta('date_end'), \IntlDateFormatter::MEDIUM),
                'bf-repeat' => $booking->needMeta('repeat'),
            ));
        }

        return $this->ajaxViewModel(array(
            'booking' => $booking,
            'editTimeRangeForm' => $editTimeRangeForm,
            'editDateRangeForm' => $editDateRangeForm,
        ));
    }

    public function deleteAction()
    {
        $sessionUser = $this->authorize([
            'calendar.cancel-single-bookings', 'calendar.delete-single-bookings',
            'calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings']);

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');

        $rid = $this->params()->fromRoute('rid');
        $editMode = $this->params()->fromQuery('edit-mode');

        $reservation = $reservationManager->get($rid);
        $booking = $bookingManager->get($reservation->get('bid'));
        $square = $squareManager->get($booking->get('sid'));

        switch ($booking->get('status')) {
            case 'single':
                $this->authorize(['calendar.cancel-single-bookings', 'calendar.delete-single-bookings']);
                break;
            case 'subscription':
                $this->authorize(['calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings']);
                break;
        }

        $confirmed = $this->params()->fromPost('confirmed', $this->params()->fromQuery('confirmed'));
        $reactivateParam = $this->params()->fromPost('reactivate', $this->params()->fromQuery('reactivate'));
        $cancelParam = $this->params()->fromPost('cancel', $this->params()->fromQuery('cancel'));

        if ($confirmed == 'true') {

            /* Reactivate cancelled booking directly from booking list */
            if ($reactivateParam == 'true') {
                $this->authorize('admin.booking');

                if ($booking->get('status') == 'cancelled') {

                    // Check if time slot is still free
                    $dateTimeStart = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_start'));
                    $dateTimeEnd = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_end'));
                    $overlapping = $reservationManager->getInRange($dateTimeStart, $dateTimeEnd);

                    if ($overlapping) {
                        $conflictBookings = $bookingManager->getByReservations($overlapping);
                        $hasConflict = false;
                        foreach ($overlapping as $overlapRes) {
                            $overlapBooking = $overlapRes->getExtra('booking');
                            if ($overlapBooking
                                && $overlapBooking->get('bid') != $booking->get('bid')
                                && $overlapBooking->get('sid') == $booking->get('sid')
                                && $overlapBooking->get('status') != 'cancelled') {
                                $hasConflict = true;
                                break;
                            }
                        }
                        if ($hasConflict) {
                            $this->flashMessenger()->addErrorMessage('This time slot is already occupied by another booking');
                            return $this->redirect()->toRoute('backend/booking');
                        }
                    }

                    $booking->set('status', 'single');
                    $booking->setMeta('cancellor', null);
                    $booking->setMeta('cancelled', null);
                    $booking->setMeta('admin_cancelled', null);
                    $booking->setMeta('backend_cancelled', null);
                    $booking->setMeta('reactivated_by', $sessionUser->get('alias'));
                    $booking->setMeta('reactivated', date('Y-m-d H:i:s'));
                    $bookingManager->save($booking);

                    // Send reactivation email
                    try {
                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $bookingUser = $userManager->get($booking->get('uid'));
                        $this->sendReactivationEmail($booking, $bookingUser, $sessionUser);
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }

                    $this->flashMessenger()->addSuccessMessage('Booking has been reactivated');
                    return $this->redirect()->toRoute('frontend', [], ['query' => [
                        'date' => $reservation->get('date'),
                    ]]);
                }
            }

            if ($editMode == 'reservation') {
                $this->authorize(['calendar.delete-single-bookings', 'calendar.delete-subscription-bookings']);

                $reservationManager->delete($reservation);

                $this->flashMessenger()->addSuccessMessage('Reservation has been deleted');
            } else {

                if ($cancelParam == 'true') {
                    $this->authorize(['calendar.cancel-single-bookings', 'calendar.cancel-subscription-bookings']);

                    $booking->set('status', 'cancelled');
                    $booking->setMeta('cancellor', $sessionUser->get('alias'));
                    $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
                    $booking->setMeta('admin_cancelled', 'true');
                    $booking->setMeta('backend_cancelled', 'true');
                    $bookingManager->save($booking);

                    if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                        $squareControlService->deactivateDoorCode($booking->get('bid'));
                    }

                    # redefine user budget if status paid
                    $bookingService = $serviceManager->get('Booking\Service\BookingService');
                    $bookingService->refundBudget($booking);

                    // Send cancellation email directly
                    $userManager = $serviceManager->get('User\Manager\UserManager');
                    $user = $userManager->get($booking->get('uid'));
                    
                    try {
                        $this->sendAdminCancellationEmail($booking, $user);
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }
                    
                    $this->flashMessenger()->addSuccessMessage('Booking has been cancelled');

                    return $this->redirect()->toRoute('frontend', [], ['query' => [
                        'date' => $reservation->get('date'),
                    ]]);
                } else {
                    $this->authorize(['calendar.delete-single-bookings', 'calendar.delete-subscription-bookings']);

                    // Before deleting, let's get user info and set cancellation metadata
                    $uid = $booking->get('uid');
                    $bookingId = $booking->get('bid');
                    $squareId = $booking->get('sid');
                    
                    // Add metadata to identify this as an admin cancellation
                    $booking->setMeta('cancellor', $sessionUser->get('alias'));
                    $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
                    $booking->setMeta('admin_cancelled', 'true');
                    $booking->setMeta('backend_cancelled', 'true');
                    $booking->setMeta('admin_deleted', 'true');
                    $booking->set('status', 'cancelled');
                    $bookingManager->save($booking);
                    
                    // Get user info and send notification before deletion
                    try {
                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $user = $userManager->get($uid);
                        
                        // Send the cancellation email directly
                        try {
                            $this->sendAdminCancellationEmail($booking, $user);
                        } catch (\Exception $e) {
                            // Continue despite errors
                        }
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }

                    // Budget refund before deletion
                    $bookingService = $serviceManager->get('Booking\Service\BookingService');
                    $bookingService->refundBudget($booking);

                    // Now delete the booking
                    $bookingManager->delete($booking);

                    $this->flashMessenger()->addSuccessMessage('Booking has been deleted');
                }
            }

            return $this->redirect()->toRoute('frontend', [], ['query' => [
                'date' => $reservation->get('date'),
            ]]);
        }

        if ($editMode == 'reservation') {
            $template = 'backend/booking/delete.reservation.phtml';
        } else {
            $template = null;
        }

        return $this->ajaxViewModel(array(
            'rid' => $rid,
            'sessionUser' => $sessionUser,
        ), null, $template);
    }

    public function bulkAction()
    {
        $sessionUser = $this->authorize([
            'calendar.cancel-single-bookings', 'calendar.delete-single-bookings',
            'calendar.cancel-subscription-bookings', 'calendar.delete-subscription-bookings']);

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $bookingService = $serviceManager->get('Booking\Service\BookingService');

        $rids = $this->params()->fromPost('bulk-rids', []);
        $action = $this->params()->fromPost('bulk-action');

        if (!is_array($rids) || empty($rids) || !in_array($action, ['cancel', 'delete', 'reactivate'])) {
            return $this->redirect()->toRoute('backend/booking');
        }

        $cancelCount = 0;
        $deleteCount = 0;
        $reactivateCount = 0;
        $reactivateFailCount = 0;

        foreach ($rids as $rid) {
            try {
                $reservation = $reservationManager->get($rid);
            } catch (\Exception $e) {
                continue;
            }
            if (!$reservation) {
                continue;
            }

            try {
                $booking = $bookingManager->get($reservation->get('bid'));
            } catch (\Exception $e) {
                continue;
            }
            if (!$booking) {
                continue;
            }

            if ($action === 'cancel' && $booking->get('status') !== 'cancelled') {
                $this->authorize(['calendar.cancel-single-bookings', 'calendar.cancel-subscription-bookings']);

                $booking->set('status', 'cancelled');
                $booking->setMeta('cancellor', $sessionUser->get('alias'));
                $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
                $booking->setMeta('admin_cancelled', 'true');
                $booking->setMeta('backend_cancelled', 'true');
                $bookingManager->save($booking);

                // Door code deactivation
                $square = $squareManager->get($booking->get('sid'));
                if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                    $squareControlService->deactivateDoorCode($booking->get('bid'));
                }

                // Budget refund
                $bookingService->refundBudget($booking);

                // Send cancellation email
                try {
                    $user = $userManager->get($booking->get('uid'));
                    $this->sendAdminCancellationEmail($booking, $user);
                } catch (\Exception $e) {
                    // Continue despite errors
                }

                $cancelCount++;

            } elseif ($action === 'delete') {
                $this->authorize('admin.all');

                // If booking is still active, cancel it first (like single delete)
                if ($booking->get('status') !== 'cancelled') {
                    $booking->set('status', 'cancelled');
                    $booking->setMeta('cancellor', $sessionUser->get('alias'));
                    $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
                    $booking->setMeta('admin_cancelled', 'true');
                    $booking->setMeta('backend_cancelled', 'true');
                    $booking->setMeta('admin_deleted', 'true');
                    $bookingManager->save($booking);

                    // Door code deactivation
                    $square = $squareManager->get($booking->get('sid'));
                    if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                        $squareControlService->deactivateDoorCode($booking->get('bid'));
                    }

                    // Send cancellation email
                    try {
                        $user = $userManager->get($booking->get('uid'));
                        $this->sendAdminCancellationEmail($booking, $user);
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }
                }

                // Budget refund before deletion
                $bookingService->refundBudget($booking);

                $bookingManager->delete($booking);
                $deleteCount++;

            } elseif ($action === 'reactivate' && $booking->get('status') === 'cancelled') {
                $this->authorize('admin.booking');

                // Collision check
                $dateTimeStart = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_start'));
                $dateTimeEnd = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_end'));
                $overlapping = $reservationManager->getInRange($dateTimeStart, $dateTimeEnd);

                $hasConflict = false;
                if ($overlapping) {
                    $bookingManager->getByReservations($overlapping);
                    foreach ($overlapping as $overlapRes) {
                        $overlapBooking = $overlapRes->getExtra('booking');
                        if ($overlapBooking
                            && $overlapBooking->get('bid') != $booking->get('bid')
                            && $overlapBooking->get('sid') == $booking->get('sid')
                            && $overlapBooking->get('status') != 'cancelled') {
                            $hasConflict = true;
                            break;
                        }
                    }
                }

                if ($hasConflict) {
                    $reactivateFailCount++;
                    continue;
                }

                $booking->set('status', 'single');
                $booking->setMeta('cancellor', null);
                $booking->setMeta('cancelled', null);
                $booking->setMeta('admin_cancelled', null);
                $booking->setMeta('backend_cancelled', null);
                $booking->setMeta('reactivated_by', $sessionUser->get('alias'));
                $booking->setMeta('reactivated', date('Y-m-d H:i:s'));
                $bookingManager->save($booking);

                // Send reactivation email
                try {
                    $user = $userManager->get($booking->get('uid'));
                    $this->sendReactivationEmail($booking, $user, $sessionUser);
                } catch (\Exception $e) {
                    // Continue despite errors
                }

                $reactivateCount++;
            }
        }

        if ($cancelCount > 0) {
            $this->flashMessenger()->addSuccessMessage(
                sprintf($this->t('%d booking(s) have been cancelled'), $cancelCount));
        }
        if ($deleteCount > 0) {
            $this->flashMessenger()->addSuccessMessage(
                sprintf($this->t('%d booking(s) have been deleted'), $deleteCount));
        }
        if ($reactivateCount > 0) {
            $this->flashMessenger()->addSuccessMessage(
                sprintf($this->t('%d booking(s) have been reactivated'), $reactivateCount));
        }
        if ($reactivateFailCount > 0) {
            $this->flashMessenger()->addErrorMessage(
                sprintf($this->t('%d booking(s) could not be reactivated (time slot occupied)'), $reactivateFailCount));
        }

        return $this->redirect()->toRoute('backend/booking');
    }

    public function statsAction()
    {
        $this->authorize('admin.booking');

        $db = @$this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');

        $stats = $db->query(sprintf('SELECT status, COUNT(status) AS count FROM %s GROUP BY status', BookingTable::NAME),
            Adapter::QUERY_MODE_EXECUTE)->toArray();

        return array(
            'stats' => $stats,
        );
    }

    public function billsAction()
    {
        $this->authorize('admin.booking');

        $bid = $this->params()->fromRoute('bid');

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $bookingStatusService = $serviceManager->get('Booking\Service\BookingStatusService');
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $booking = $bookingManager->get($bid);
        $bills = $bookingBillManager->getBy(array('bid' => $bid), 'bbid ASC');
        $user = $userManager->get($booking->need('uid'));

        if ($this->getRequest()->isGet()) {
            $create = $this->params()->fromQuery('create');

            if ($create == 'default-bill') {
                $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
                $squareManager = $serviceManager->get('Square\Manager\SquareManager');
                $squarePricingManager = $serviceManager->get('Square\Manager\SquarePricingManager');

                $square = $squareManager->get($booking->get('sid'));
                $squareType = $this->option('subject.square.type');
                $squareName = $this->t($square->need('name'));

                $dateRangeHelper = $serviceManager->get('ViewHelperManager')->get('DateRange');

                $created = false;

                foreach ($reservationManager->getBy(['bid' => $bid]) as $reservation) {

                    $dateTimeStart = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_start'));
                    $dateTimeEnd = new \DateTime($reservation->get('date') . ' ' . $reservation->get('time_end'));

                    $member = 0;
                    if ($user != null && $user->getMeta('member') != null) {
                       $member = $user->getMeta('member');
                    }

                    $pricing = $squarePricingManager->getFinalPricingInRange($dateTimeStart, $dateTimeEnd, $square, $booking->get('quantity'), $member);

                    if ($pricing) {

                        $description = sprintf('%s %s, %s',
                            $squareType, $squareName,
                            $dateRangeHelper($dateTimeStart, $dateTimeEnd));

                        $bookingBillManager->save(new Booking\Bill(array(
                            'bid' => $bid,
                            'description' => $description,
                            'quantity' => $booking->get('quantity'),
                            'time' => $pricing['seconds'],
                            'price' => $pricing['price'],
                            'rate' => $pricing['rate'],
                            'gross' => $pricing['gross'],
                        )));

                        $created = true;
                    }
                }

                if ($created) {
                    $this->flashMessenger()->addSuccessMessage('Booking-Bill position has been created');
                } else {
                    $this->flashMessenger()->addErrorMessage('No Booking-Bill position has been created');
                }

                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid], ['query' => []]);
            }

            $delete = $this->params()->fromQuery('delete');

            if ($delete && is_numeric($delete) && isset($bills[$delete])) {
                $bookingBillManager->delete($delete);

                $this->flashMessenger()->addSuccessMessage('Booking-Bill position has been deleted');
                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid], ['query' => []]);
            }
        }

        if ($this->getRequest()->isPost()) {

            /* Check and save billing status */

            $billingStatus = $this->params()->fromPost('ebf-status');

            if ($bookingStatusService->checkStatus($billingStatus)) {
                $booking->set('status_billing', $billingStatus);
                $bookingManager->save($booking);
            } else {
                $this->flashMessenger()->addErrorMessage('Invalid billing status selected');
            }

            /* Check and save known (and new) bills */

            $bills[] = new Booking\Bill(['bid' => $bid]);

            foreach ($bills as $bill) {

                $bbid = $bill->get('bbid', 'new');

                $description = $this->params()->fromPost('ebf-' . $bbid . '-description');
                $description = trim(strip_tags($description));

                if ($description) {
                    $bill->set('description', $description);
                }

                $time = $this->params()->fromPost('ebf-' . $bbid . '-time');

                if ($time && is_numeric($time)) {
                    $bill->set('time', $time * 60);
                }

                $quantity = $this->params()->fromPost('ebf-' . $bbid . '-quantity');

                if ($quantity && is_numeric($quantity)) {
                    $bill->set('quantity', $quantity);
                }

                $price = $this->params()->fromPost('ebf-' . $bbid . '-price');

                if ($price && is_numeric($price)) {
                    $bill->set('price', $price);
                }

                $vatGross = $this->params()->fromPost('ebf-' . $bbid . '-vat-gross');
                $vatRate = $this->params()->fromPost('ebf-' . $bbid . '-vat-rate');

                if (is_numeric($vatGross) && is_numeric($vatRate)) {
                    $bill->set('gross', $vatGross);
                    $bill->set('rate', $vatRate);
                }

                if ($description && $price && is_numeric($vatRate) && is_numeric($vatGross)) {
                    $bookingBillManager->save($bill);
                }
            }

            $save = $this->params()->fromPost('ebf-save');
            $saveAndBack = $this->params()->fromPost('ebf-save-and-back');

            $this->flashMessenger()->addSuccessMessage('Booking-Bill has been saved');

            if ($save) {
                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid], ['query' => []]);
            } else if ($saveAndBack) {
                return $this->redirect()->toRoute('user/bookings/bills', ['bid' => $bid], ['query' => []]);
            }
        }

        return array(
            'booking' => $booking,
            'bookingStatusService' => $bookingStatusService,
            'bills' => $bills,
            'user' => $user,
        );
    }

    public function playersAction()
    {
        $this->authorize('admin.booking, calendar.see-data');

        $bid = $this->params()->fromRoute('bid');

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $userManager = $serviceManager->get('User\Manager\UserManager');

        $booking = $bookingManager->get($bid);
        $user = $userManager->get($booking->need('uid'));

        $playerNames = $booking->getMeta('player-names');

        if (! $playerNames) {
            throw new \RuntimeException('This booking has no additional player names');
        }

        $playerNames = json_decode($booking->getMeta('player-names'), true) ?: @unserialize($booking->getMeta('player-names'));

        if (! $playerNames) {
            throw new \RuntimeException('Invalid player names data stored in database');
        }

        $players = array();

        foreach ($playerNames as $playerData) {
            $nameData = explode('-', $playerData['name']);
            $playerNumber = $nameData[count($nameData) - 1];

            if (! isset($players[$playerNumber])) {
                $players[$playerNumber] = array();
            }

            $playerDataKey = $nameData[count($nameData) - 2];
            $playerDataValue = $playerData['value'];

            if ($playerDataKey == 'email') {
                $respectiveUser = $userManager->getBy(['email' => $playerDataValue]);

                if ($respectiveUser) {
                    $players[$playerNumber]['user'] = current($respectiveUser);
                    $players[$playerNumber]['userMatch'] = $playerDataKey;
                }
            }

            if ($playerDataKey == 'phone') {
                $respectiveUser = $userManager->getByPhoneNumber($playerDataValue);

                if ($respectiveUser) {
                    $players[$playerNumber]['user'] = $respectiveUser;
                    $players[$playerNumber]['userMatch'] = $playerDataKey;
                }
            }

            $players[$playerNumber][$playerDataKey] = $playerDataValue;
        }

        return array(
            'booking' => $booking,
            'user' => $user,
            'players' => $players,
        );
    }

    public function webhookAction()
    {
        // $this->authorize('admin.booking'); 
        // authorize is done via stripe webhook secret

        $serviceManager = @$this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');

        // $bookingService = $serviceManager->get('Booking\Service\BookingService');

        $squareControlService->removeInactiveDoorCodes(); 

        $payload = @file_get_contents('php://input');
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $event = null;

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload, $sig_header, $this->config('stripeWebhookSecret')
            );
        } catch(\UnexpectedValueException $e) {
            // Invalid payload
            // syslog(LOG_EMERG, '|UnexpectedValueException|');
            http_response_code(400);
            return false;
        } catch(\Stripe\Exception\SignatureVerificationException $e) {
            // Invalid signature
            // syslog(LOG_EMERG, '|invalid signature|');
            http_response_code(400);
            return false;
        }

        // syslog(LOG_EMERG, '|'.$event.'|');

        $bid = -1;
        $intent = null;

        if ($event->type == "payment_intent.succeeded" || $event->type == "payment_intent.payment_failed" || $event->type == "payment_intent.canceled") {
            $intent = $event->data->object;
            $bid = $intent->metadata->bid;
        }
        else {
            http_response_code(400);
            return false;
        }

        // test
        // $bid='1443';
        // $event->type="payment_intent.payment_failed";
        // end test

        // syslog(LOG_EMERG, '|'.$bid.'|');  

        if (! (is_numeric($bid) && $bid > 0)) {
            // syslog(LOG_EMERG, 'This bid does not exist');
            http_response_code(400);
            return false;
        }

        try {
            $booking = $bookingManager->get($bid);
            $square = $squareManager->get($booking->get('sid'));
            $notes = $booking->getMeta('notes', '');

            if ($booking->getMeta('directpay_pending') == true && $booking->getMeta('paymentMethod') == 'stripe') {

            $notes = $notes . " " . " -> via webhook "; 

            if ($event->type == "payment_intent.succeeded") {
                // syslog(LOG_EMERG, "Succeeded paymentIntent");
                $notes = $notes . " " . "-> paymentIntent succeded";
                $booking->set('status_billing', 'paid');
                $booking->setMeta('paidAt', date('Y-m-d H:i:s'));
                $booking->setMeta('directpay_pending', false);
                $booking->setMeta('directpay', true);

            } elseif ($event->type == "payment_intent.payment_failed" || $event->type == "payment_intent.canceled") {
                // syslog(LOG_EMERG, "Failed or canceled paymentIntent");
                $notes = $notes . " " . "-> paymentIntent failed or canceled";
                $error_message = $intent->last_payment_error ? $intent->last_payment_error->message : "";
                $notes = $notes . " -  " . $error_message;
                
                // deactivate door code
                if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                    $squareControlService->deactivateDoorCode($bid);
                }
                
                // maybe if booking is not outdated cancel single bookings
                $cancellable = false;
                $reservations = $reservationManager->getBy(array('bid' => $bid), 'date ASC, time_start ASC');
                $reservation = current($reservations);
                if ($reservation) { 
                    $reservationStartDate = new DateTime($reservation->need('date') . ' ' . $reservation->need('time_start'));
                    $reservationCancelDate = new DateTime();
                    if ($reservationStartDate > $reservationCancelDate) { $cancellable = true; }
                }

                if ($booking->get('status') == 'single' && $cancellable && $this->config('stripeWebhookCancel') == true) {
                    $booking->set('status', 'cancelled');
                    $booking->setMeta('cancellor', 'stripe');
                    $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
                }
            }

            $booking->setMeta('notes', $notes);
            $bookingManager->save($booking);
            http_response_code(200);
            return true;

            } 

        } catch(RuntimeException $e) {
            syslog(LOG_EMERG, $e->getMessage());
            http_response_code(400);
            return false;
        }

        return false;
    }

    /**
     * Send a cancellation email notification when an admin cancels a booking
     * 
     * @param Booking $booking
     * @param User $user
     * @return boolean
     */
    public function sendAdminCancellationEmail(Booking $booking, User $user)
    {
        // Only send email if user has an email address
        if (empty($user->need('email'))) {
            return false;
        }

        try {
            // Daten aus den Reservierungen holen
            $square = null;
            $squareName = 'nicht spezifiziert';
            $formattedDate = '[Datum nicht verfügbar]';
            $formattedTime = '[Startzeit nicht verfügbar]';
            $formattedEndTime = '[Endzeit nicht verfügbar]';
            $reservation = null;
            
            try {
                // Direkter Zugriff auf Services
                $squareManager = $this->serviceLocator->get('Square\Manager\SquareManager');
                
                // Square über sid abrufen (nicht s)
                if ($booking->get('sid')) {
                    $square = $squareManager->get($booking->need('sid'));
                    $squareName = $square->need('name');
                }
            } catch (\Exception $e) {
                // Fallback wenn Square nicht gefunden wird
            }

            try {
                // Reservierungsdaten für die Buchung abrufen
                $reservationManager = $this->serviceLocator->get('Booking\Manager\ReservationManager');
                $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);

                if (!empty($reservations)) {
                    $reservation = current($reservations);

                    // Datum und Zeiten aus der Reservierung extrahieren mit modernem DateTime
                    if ($reservation->get('date')) {
                        $date = new \DateTime($reservation->need('date'));
                        $formattedDate = $date->format('d.m.Y');
                    }

                    if ($reservation->get('time_start')) {
                        $formattedTime = $reservation->get('time_start');
                    }

                    if ($reservation->get('time_end')) {
                        $formattedEndTime = $reservation->get('time_end');
                    }
                }
            } catch (\Exception $e) {
                // Continue despite errors
            }

            // Personalisierte Anrede
            $anrede = 'Hallo';
            if ($user->getMeta('gender') == 'male') {
                $anrede = 'Sehr geehrter Herr';
            } elseif ($user->getMeta('gender') == 'female') {
                $anrede = 'Sehr geehrte Frau';
            }

            if ($user->getMeta('lastname')) {
                $anrede .= ' ' . $user->getMeta('lastname');
            } else {
                $anrede .= ' ' . $user->need('alias');
            }

            $subject = sprintf($this->t('%s\'s Platz-Buchung wurde storniert'), $user->need('alias'));
            
            // Get client contact email and website for potential inquiries
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');
            
            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('Diese Nachricht wurde automatisch gesendet. Sollten Sie noch Fragen bzw. Anregungen haben, informieren Sie bitte unser Supportteam');
                
                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf($this->t(' unter %s'), $contactEmail);
                }
                
                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= $this->t(' oder');
                    }
                    $contactInfo .= sprintf($this->t(' auf unserer Website %s'), $clientWebsite);
                }
                $contactInfo .= '.';
            }
            
            $clientName = $this->option('client.name.full', 'Online-Platzbuchung');
            
            // Strukturierte Darstellung der Buchungsdetails
            $buchungsDetails = sprintf(
                $this->t("Stornierte Buchungsdetails:\n\n- Platz: %s\n\n- Datum: %s\n\n- Zeit: %s - %s Uhr\n\n- Buchungs-Nr: %s"),
                $squareName,
                $formattedDate,
                $formattedTime,
                $formattedEndTime,
                $booking->need('bid')
            );
            
            $stornierungsBedingungen = '';
            $paypalInfo = '';
            if ($booking->get('status_billing') === 'paid') {
                $paypalInfo = $this->t('Die gezahlten PayPal-Beträge wurden als Budget Ihrem Konto gutgeschrieben.');
            }
            
            // Backend MailService verwenden, falls verfügbar
            try {
                // Prüfen, ob der Backend\Service\MailService verfügbar ist
                if ($this->serviceLocator->has('Backend\Service\MailService')) {
                    // Vollständigen Text für die E-Mail erstellen
                    $emailText = sprintf(
                        "%s,\n\nwir haben Ihre Platz-Buchung storniert.\n\n%s\n\n%s",
                        $anrede,
                        $buchungsDetails,
                        !empty($paypalInfo) ? "\n\n" . $paypalInfo : ""
                    );
                    
                    $backendMailService = $this->serviceLocator->get('Backend\Service\MailService');
                    
                    // Benutzerdefinierte E-Mail an den Kunden senden
                    $backendMailService->sendCustomEmail(
                        $subject,
                        $emailText,
                        $user->need('email'),
                        $user->need('alias'),
                        [],   // keine Anhänge
                        $contactInfo,  // zusätzliche Information als Nachsatz
                        false  // skipCopy = false, um Admin-Kopien zu senden
                    );

                    // Admin-Kopie mit zusätzlichen Informationen über den Admin, der storniert hat
                    // Holen des aktuellen Admin-Benutzers
                    $adminUser = null;
                    $adminInfo = '';
                    
                    try {
                        // Vereinfachter Code zur Erkennung des Admin-Benutzers
                        $adminUser = null;
                        
                        // Primäre Methode: Benutze das 'creator' oder 'cancellor'-Feld aus den Metadaten
                        if ($booking->getMeta('creator', null)) {
                            try {
                                $userManager = $this->serviceLocator->get('User\Manager\UserManager');
                                $adminUsers = $userManager->getBy(['alias' => $booking->getMeta('creator')]);
                                if (!empty($adminUsers)) {
                                    $adminUser = current($adminUsers);
                                } else {
                                    // Fallback für den Fall, dass der Benutzer nicht gefunden wird
                                    $adminUser = new \stdClass();
                                    $adminUser->alias = $booking->getMeta('creator');
                                    $adminUser->email = 'admin@system.local';
                                }
                            } catch (\Exception $e) {
                                // Einfacher Fallback bei Fehlern
                                $adminUser = new \stdClass();
                                $adminUser->alias = $booking->getMeta('creator');
                                $adminUser->email = 'admin@system.local';
                            }
                        }
                        // Alternative: Benutze 'cancellor' Feld für Stornierungen
                        elseif ($booking->getMeta('cancellor', null)) {
                            try {
                                $userManager = $this->serviceLocator->get('User\Manager\UserManager');
                                $adminUsers = $userManager->getBy(['alias' => $booking->getMeta('cancellor')]);
                                if (!empty($adminUsers)) {
                                    $adminUser = current($adminUsers);
                                } else {
                                    // Fallback für den Fall, dass der Benutzer nicht gefunden wird
                                    $adminUser = new \stdClass();
                                    $adminUser->alias = $booking->getMeta('cancellor');
                                    $adminUser->email = 'admin@system.local';
                                }
                            } catch (\Exception $e) {
                                // Einfacher Fallback bei Fehlern
                                $adminUser = new \stdClass();
                                $adminUser->alias = $booking->getMeta('cancellor');
                                $adminUser->email = 'admin@system.local';
                            }
                        }
                        // Fallback: Aktuelle Authentifizierung
                        elseif ($this->serviceLocator->has('Zend\Authentication\AuthenticationService')) {
                            $authService = $this->serviceLocator->get('Zend\Authentication\AuthenticationService');
                            if ($authService->hasIdentity()) {
                                $adminUser = $authService->getIdentity();
                            }
                        }
                    } catch (\Exception $e) {
                        // Fehler beim Ermitteln des Admin-Benutzers
                    }
                    
                    // Admin-Info für die Kopie-E-Mail
                    if ($adminUser) {
                        // Extrahiere die Admin-Daten
                        $adminName = method_exists($adminUser, 'need') ? $adminUser->need('alias') : 
                                     (isset($adminUser->alias) ? $adminUser->alias : 'Unbekannt');
                        $adminEmail = method_exists($adminUser, 'need') ? $adminUser->need('email') : 
                                     (isset($adminUser->email) ? $adminUser->email : 'keine@email.de');
                        
                        $adminInfo = sprintf(
                            "\n\n\n==================================================\nINTERNE ADMIN-INFORMATION:\n--------------------------------------------------\nDiese Buchung wurde STORNIERT von: %s (%s)\nZeitpunkt der Stornierung: %s\n==================================================\n\n",
                            $adminName,
                            $adminEmail,
                            date('d.m.Y H:i:s')
                        );
                    } else {
                        // Fallback-Information wenn kein Admin-Benutzer ermittelt werden konnte
                        $adminInfo = sprintf(
                            "\n\n\n==================================================\nINTERNE ADMIN-INFORMATION:\n--------------------------------------------------\nDiese Buchung wurde STORNIERT\nZeitpunkt der Stornierung: %s\n(Admin-Benutzer konnte nicht ermittelt werden)\n==================================================\n\n",
                            date('d.m.Y H:i:s')
                        );
                    }
                    
                    // Kontakt-E-Mail-Adresse aus den Einstellungen abrufen
                    $contactEmail = $this->option('client.contact.email', '');

                    
                    // Falls konfiguriert, System-E-Mail auch verwenden
                    //$systemEmail = $this->option('client.system.email', '');
                   // $systemEmail = 'system@platzbuchung.tcn-kail.de';
                    // Admin-Kopie mit den zusätzlichen Informationen senden
                    if (!empty($contactEmail)) {
                        $adminEmailText = $emailText . $adminInfo;
                        // Get the client contact email to avoid duplication
                        $clientContactEmail = $this->option('client.contact.email', '');

                        if (!empty($clientContactEmail)) {


                        $backendMailService->sendCustomEmail(
                            '[ADMIN-KOPIE] ' . $subject,
                            $adminEmailText,
                            $contactEmail,
                            'Administrator',
                            [],   // keine Anhänge
                            $contactInfo,  // zusätzliche Information als Nachsatz
                            true  // skipCopy = true, um doppelte Emails zu vermeiden
                        );
                    }
                    }
                    // Zweite Admin-E-Mail versenden, falls konfiguriert und unterschiedlich
                    //if (!empty($systemEmail) && $systemEmail !== $contactEmail) {
                    //    $adminEmailText = $emailText . $adminInfo;
                    //    $backendMailService->sendCustomEmail(
                    //        '[ADMIN-KOPIE] ' . $subject,
                    //        $adminEmailText,
                    //        $systemEmail,
                    //        'System-Administrator',
                    //        [],   // keine Anhänge
                    //        $contactInfo  // zusätzliche Information als Nachsatz
                    //    );
                    //}
                    
                    return true;
                } else {
                    // Fallback auf die alte Methode, wenn Backend\Service\MailService nicht verfügbar ist
                    $this->sendAdminCancellationEmailFallback($booking, $user, $subject, $buchungsDetails, $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $anrede);
                }
            } catch (\Exception $e) {
                // Fallback auf die alte Methode
                $this->sendAdminCancellationEmailFallback($booking, $user, $subject, $buchungsDetails, $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $anrede);
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fallback-Methode zum Senden der Stornierungsemail, wenn Backend\Service\MailService nicht verfügbar ist
     */
    protected function sendAdminCancellationEmailFallback($booking, $user, $subject, $buchungsDetails, $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $anrede)
    {
        try {
            // Versuche den Mail-Service zu erhalten, mit robuster Fehlerbehandlung
            if (!$this->serviceLocator->has('Base\Service\MailService')) {
                throw new \Exception("MailService ist nicht als Service registriert");
            }
            
            $mailService = $this->serviceLocator->get('Base\Service\MailService');
            if (!$mailService) {
                throw new \Exception("MailService konnte nicht initialisiert werden");
            }
            
            // Verwende Konfigurationswerte aus den Client-Einstellungen
            $fromAddress = $this->option('client.website.contact', 'noreply@example.com');
            if (strpos($fromAddress, 'mailto:') === 0) {
                $fromAddress = substr($fromAddress, 7); // Entferne "mailto:"
            }

            $fromName = sprintf('%s %s',
                $this->option('client.name.short', 'BS'),
                $this->option('service.name.full', 'Buchungssystem')
            );

            // Vollständigen E-Mail-Text zusammenbauen
            $body = sprintf(
                "%s,\n\nwir haben Ihre Platz-Buchung storniert.\n\n%s\n\n%s",
                $anrede,
                $buchungsDetails,
                !empty($paypalInfo) ? "\n\n" . $paypalInfo : ""
            );

            // Send to user
            $mailService->sendPlain(
                $fromAddress,         // fromAddress
                $fromName,            // fromName
                $fromAddress,         // replyToAddress
                $fromName,            // replyToName
                $user->need('email'), // toAddress
                $user->need('alias'), // toName
                $subject,             // subject
                $body                 // text
            );
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Send reactivation email to user and admin when a cancelled booking is reactivated
     */
    public function sendReactivationEmail(Booking $booking, User $user, $sessionUser)
    {
        if (empty($user->need('email'))) {
            return false;
        }

        try {
            $squareName = 'nicht spezifiziert';
            $formattedDate = '[Datum nicht verfügbar]';
            $formattedTime = '[Startzeit nicht verfügbar]';
            $formattedEndTime = '[Endzeit nicht verfügbar]';

            try {
                $squareManager = $this->serviceLocator->get('Square\Manager\SquareManager');
                if ($booking->get('sid')) {
                    $square = $squareManager->get($booking->need('sid'));
                    $squareName = $square->need('name');
                }
            } catch (\Exception $e) {
                // Continue despite errors
            }

            try {
                $reservationManager = $this->serviceLocator->get('Booking\Manager\ReservationManager');
                $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);

                if (!empty($reservations)) {
                    $reservation = current($reservations);

                    if ($reservation->get('date')) {
                        $date = new \DateTime($reservation->need('date'));
                        $formattedDate = $date->format('d.m.Y');
                    }
                    if ($reservation->get('time_start')) {
                        $formattedTime = $reservation->get('time_start');
                    }
                    if ($reservation->get('time_end')) {
                        $formattedEndTime = $reservation->get('time_end');
                    }
                }
            } catch (\Exception $e) {
                // Continue despite errors
            }

            // Personalisierte Anrede
            $anrede = 'Hallo';
            if ($user->getMeta('gender') == 'male') {
                $anrede = 'Sehr geehrter Herr';
            } elseif ($user->getMeta('gender') == 'female') {
                $anrede = 'Sehr geehrte Frau';
            }

            if ($user->getMeta('lastname')) {
                $anrede .= ' ' . $user->getMeta('lastname');
            } else {
                $anrede .= ' ' . $user->need('alias');
            }

            $subject = sprintf($this->t('%s\'s Platz-Buchung wurde reaktiviert'), $user->need('alias'));

            $buchungsDetails = sprintf(
                $this->t("Reaktivierte Buchungsdetails:\n\n- Platz: %s\n\n- Datum: %s\n\n- Zeit: %s - %s Uhr\n\n- Buchungs-Nr: %s"),
                $squareName,
                $formattedDate,
                $formattedTime,
                $formattedEndTime,
                $booking->need('bid')
            );

            // Kontaktinformationen
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');

            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('Diese Nachricht wurde automatisch gesendet. Sollten Sie noch Fragen bzw. Anregungen haben, informieren Sie bitte unser Supportteam');

                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf($this->t(' unter %s'), $contactEmail);
                }

                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= $this->t(' oder');
                    }
                    $contactInfo .= sprintf($this->t(' auf unserer Website %s'), $clientWebsite);
                }
                $contactInfo .= '.';
            }

            $emailText = sprintf(
                "%s,\n\nIhre zuvor stornierte Platz-Buchung wurde reaktiviert.\n\n%s",
                $anrede,
                $buchungsDetails
            );

            if ($this->serviceLocator->has('Backend\Service\MailService')) {
                $backendMailService = $this->serviceLocator->get('Backend\Service\MailService');

                // E-Mail an den Kunden
                $backendMailService->sendCustomEmail(
                    $subject,
                    $emailText,
                    $user->need('email'),
                    $user->need('alias'),
                    [],
                    $contactInfo,
                    false
                );

                // Admin-Kopie mit zusätzlichen Informationen
                $adminName = $sessionUser->need('alias');
                $adminEmail = $sessionUser->need('email');

                $adminInfo = sprintf(
                    "\n\n\n==================================================\n%s:\n--------------------------------------------------\nDiese Buchung wurde REAKTIVIERT von: %s (%s)\nZeitpunkt der Reaktivierung: %s\n==================================================\n\n",
                    $this->t('Internal admin information'),
                    $adminName,
                    $adminEmail,
                    date('d.m.Y H:i:s')
                );

                $clientContactEmail = $this->option('client.contact.email', '');
                if (!empty($clientContactEmail)) {
                    $backendMailService->sendCustomEmail(
                        '[ADMIN-KOPIE] ' . $subject,
                        $emailText . $adminInfo,
                        $clientContactEmail,
                        'Administrator',
                        [],
                        $contactInfo,
                        true
                    );
                }
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Send a booking creation email notification when an admin creates a booking
     *
     * @param Booking $booking
     * @param User $user
     * @return boolean
     */
    public function sendAdminBookingCreationEmail(Booking $booking, User $user)
    {
        // Only send email if user has an email address
        if (empty($user->need('email'))) {
            return false;
        }

        try {
            // Daten aus den Reservierungen holen
            $square = null;
            $squareName = 'nicht spezifiziert';
            $formattedDate = '[Datum nicht verfügbar]';
            $formattedTime = '[Startzeit nicht verfügbar]';
            $formattedEndTime = '[Endzeit nicht verfügbar]';
            
            try {
                // Direkter Zugriff auf Services
                $squareManager = $this->serviceLocator->get('Square\Manager\SquareManager');
                
                // Square über sid abrufen (nicht s)
                if ($booking->get('sid')) {
                    $square = $squareManager->get($booking->need('sid'));
                    $squareName = $square->need('name');
                }
            } catch (\Exception $e) {
                // Fallback wenn Square nicht gefunden wird
            }

            try {
                // Reservierungsdaten für die Buchung abrufen
                $reservationManager = $this->serviceLocator->get('Booking\Manager\ReservationManager');
                $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);

                if (!empty($reservations)) {
                    $reservation = current($reservations);

                    // Datum und Zeiten aus der Reservierung extrahieren mit modernem DateTime
                    if ($reservation->get('date')) {
                        $date = new \DateTime($reservation->need('date'));
                        $formattedDate = $date->format('d.m.Y');
                    }

                    if ($reservation->get('time_start')) {
                        $formattedTime = $reservation->get('time_start');
                    }

                    if ($reservation->get('time_end')) {
                        $formattedEndTime = $reservation->get('time_end');
                    }
                }
            } catch (\Exception $e) {
                // Continue despite errors
            }

            // Personalisierte Anrede
            $anrede = 'Hallo';
            if ($user->getMeta('gender') == 'male') {
                $anrede = 'Sehr geehrter Herr';
            } elseif ($user->getMeta('gender') == 'female') {
                $anrede = 'Sehr geehrte Frau';
            }

            if ($user->getMeta('lastname')) {
                $anrede .= ' ' . $user->getMeta('lastname');
            } else {
                $anrede .= ' ' . $user->need('alias');
            }

            $subject = sprintf($this->t('Buchungsbestätigung: Platz %s, %s'), $squareName, $formattedDate);
            
            // Get client contact email and website for potential inquiries
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');
            
            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('Diese Nachricht wurde automatisch gesendet. Sollten Sie noch Fragen bzw. Anregungen haben, informieren Sie bitte unser Supportteam');
                
                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf($this->t(' unter %s'), $contactEmail);
                }
                
                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= $this->t(' oder');
                    }
                    $contactInfo .= sprintf($this->t(' auf unserer Website %s'), $clientWebsite);
                }
                $contactInfo .= '.';
            }
            
            $clientName = $this->option('client.name', 'Online-Platzbuchung');
            
            // Strukturierte Darstellung der Buchungsdetails
            $buchungsDetails = sprintf(
                $this->t("Buchungsdetails:\n\n- Platz: %s\n\n- Datum: %s\n\n- Zeit: %s - %s Uhr\n\n- Buchungs-Nr: %s"),
                $squareName,
                $formattedDate,
                $formattedTime,
                $formattedEndTime,
                $booking->need('bid')
            );
            
            /* Bill / price info for email */
            $rechnungsInfo = '';
            $total = 0;
            try {
                $bookingBillManager = $this->serviceLocator->get('Booking\Manager\Booking\BillManager');
                $bills = $bookingBillManager->getBy(array('bid' => $booking->need('bid')), 'bbid ASC');
                if ($bills) {
                    $rechnungsInfo .= "\n" . $this->t('Bill') . ":\n";
                    foreach ($bills as $bill) {
                        $total += $bill->get('price');
                        $rechnungsInfo .= "\n" . $bill->get('description');
                        $rechnungsInfo .= " -> " . number_format($bill->get('price') / 100, 2, ',', '.') . " €";
                    }
                    $rechnungsInfo .= "\n\n" . $this->t('Total') . ": " . number_format($total / 100, 2, ',', '.') . " €";
                }
            } catch (\Exception $e) {
                // Continue despite errors
            }

            /* Payment instructions for guest player bookings with pending billing */
            $zahlungshinweis = '';
            if ($total > 0 && $booking->get('status_billing') !== 'paid' && $booking->get('status_billing') !== 'member') {
                $zahlungshinweis .= "\n\n" . $this->t('Payment instructions:');
                $paypalEmail = $this->config('paypalEmail') ?: 'payment@your-domain.com';
                $zahlungshinweis .= "\n" . sprintf($this->t('Please transfer the amount via PayPal Friends & Family to %s or use the money letterbox at the office.'), $paypalEmail);
                $zahlungshinweis .= "\n" . $this->t('The booking is only valid after payment is completed.');
            }

            $stornierungsBedingungen = $this->t('Bitte beachten Sie unsere Stornierungsbedingungen. Stornierungen sind bis zu 2 Stunden vor Beginn kostenfrei möglich.');
            $paypalInfo = '';
            if ($booking->get('status_billing') === 'paid') {
                $paypalInfo = $this->t('Bei Stornierung werden die gezahlten PayPal-Beträge als Budget dem Konto gutgeschrieben.');
            }
            
            // iCalendar-Anhang erstellen
            $calendarAttachment = $this->createICalendarAttachment($booking, $squareName, $formattedDate, $formattedTime, $formattedEndTime, $user);
            
            // Backend MailService verwenden, falls verfügbar
            try {
                // Prüfen, ob der Backend\Service\MailService verfügbar ist
                if ($this->serviceLocator->has('Backend\Service\MailService')) {
                    // Vollständigen Text für die E-Mail erstellen
                    $emailText = sprintf(
                        "%s,\n\nwir haben den Platz für Sie gebucht.\n\n%s%s%s\n\n%s%s",
                        $anrede,
                        $buchungsDetails,
                        $rechnungsInfo,
                        $zahlungshinweis,
                        $stornierungsBedingungen,
                        !empty($paypalInfo) ? "\n\n" . $paypalInfo : ""
                    );
                    
                    $backendMailService = $this->serviceLocator->get('Backend\Service\MailService');
                    
                    // Benutzerdefinierte E-Mail an den Kunden senden
                    $backendMailService->sendCustomEmail(
                        $subject,
                        $emailText,
                        $user->need('email'),
                        $user->need('alias'),
                        $calendarAttachment ? [$calendarAttachment] : [],   // Kalender-Anhang, falls vorhanden
                        $contactInfo,  // zusätzliche Information als Nachsatz
                        false  // skipCopy = false, um Admin-Kopien zu senden
                    );

                    // Admin-Kopie mit zusätzlichen Informationen über den Admin, der die Buchung erstellt hat
                    // Holen des aktuellen Admin-Benutzers
                    $adminUser = null;
                    $adminInfo = '';
                    
                    try {
                        // Vereinfachter Code zur Erkennung des Admin-Benutzers
                        $adminUser = null;
                        
                        // Primäre Methode: Benutze 'creator' oder 'cancellor' Feld aus den Metadaten
                        if ($booking->getMeta('creator', null)) {
                            try {
                                $userManager = $this->serviceLocator->get('User\Manager\UserManager');
                                $adminUsers = $userManager->getBy(['alias' => $booking->getMeta('creator')]);
                                if (!empty($adminUsers)) {
                                    $adminUser = current($adminUsers);
                                } else {
                                    // Fallback für den Fall, dass der Benutzer nicht gefunden wird
                                    $adminUser = new \stdClass();
                                    $adminUser->alias = $booking->getMeta('creator');
                                    $adminUser->email = 'admin@system.local';
                                }
                            } catch (\Exception $e) {
                                // Einfacher Fallback bei Fehlern
                                $adminUser = new \stdClass();
                                $adminUser->alias = $booking->getMeta('creator');
                                $adminUser->email = 'admin@system.local';
                            }
                        }
                        // Alternative: Benutze 'cancellor' Feld für Stornierungen
                        elseif ($booking->getMeta('cancellor', null)) {
                            try {
                                $userManager = $this->serviceLocator->get('User\Manager\UserManager');
                                $adminUsers = $userManager->getBy(['alias' => $booking->getMeta('cancellor')]);
                                if (!empty($adminUsers)) {
                                    $adminUser = current($adminUsers);
                                } else {
                                    // Fallback für den Fall, dass der Benutzer nicht gefunden wird
                                    $adminUser = new \stdClass();
                                    $adminUser->alias = $booking->getMeta('cancellor');
                                    $adminUser->email = 'admin@system.local';
                                }
                            } catch (\Exception $e) {
                                // Einfacher Fallback bei Fehlern
                                $adminUser = new \stdClass();
                                $adminUser->alias = $booking->getMeta('cancellor');
                                $adminUser->email = 'admin@system.local';
                            }
                        }
                        // Fallback: Aktuelle Authentifizierung
                        elseif ($this->serviceLocator->has('Zend\Authentication\AuthenticationService')) {
                            $authService = $this->serviceLocator->get('Zend\Authentication\AuthenticationService');
                            if ($authService->hasIdentity()) {
                                $adminUser = $authService->getIdentity();
                            }
                        }
                    } catch (\Exception $e) {
                        // Fehler beim Ermitteln des Admin-Benutzers
                    }
                    
                    // Admin-Info für die Kopie-E-Mail
                    if ($adminUser) {
                        // Extrahiere die Admin-Daten
                        $adminName = method_exists($adminUser, 'need') ? $adminUser->need('alias') : 
                                     (isset($adminUser->alias) ? $adminUser->alias : 'Unbekannt');
                        $adminEmail = method_exists($adminUser, 'need') ? $adminUser->need('email') : 
                                     (isset($adminUser->email) ? $adminUser->email : 'keine@email.de');
                        
                        $adminInfo = sprintf(
                            "\n\n\n==================================================\nINTERNE ADMIN-INFORMATION:\n--------------------------------------------------\nDiese Buchung wurde ERSTELLT von: %s (%s)\nZeitpunkt der Buchungserstellung: %s\n==================================================\n\n",
                            $adminName,
                            $adminEmail,
                            date('d.m.Y H:i:s')
                        );
                    } else {
                        // Fallback-Information wenn kein Admin-Benutzer ermittelt werden konnte
                        $adminInfo = sprintf(
                            "\n\n\n==================================================\nINTERNE ADMIN-INFORMATION:\n--------------------------------------------------\nDiese Buchung wurde ERSTELLT\nZeitpunkt der Buchungserstellung: %s\n(Admin-Benutzer konnte nicht ermittelt werden)\n==================================================\n\n",
                            date('d.m.Y H:i:s')
                        );
                    }
                    
                    // Kontakt-E-Mail-Adresse aus den Einstellungen abrufen
                    $contactEmail = $this->option('client.contact.email', '');
                    if (strpos($contactEmail, 'mailto:') === 0) {
                        $contactEmail = substr($contactEmail, 7); // Entferne "mailto:"
                    }
                    
                    // Falls konfiguriert, System-E-Mail auch verwenden
                    //$systemEmail = $this->option('client.contact.email', '');
                    //$systemEmail = 'system@platzbuchung.tcn-kail.de';
                    
                    // Admin-Kopie mit den zusätzlichen Informationen senden
                    if (!empty($contactEmail)) {
                        $adminEmailText = $emailText . $adminInfo;
                        $backendMailService->sendCustomEmail(
                            '[ADMIN-KOPIE] ' . $subject,
                            $adminEmailText,
                            $contactEmail,
                            'Administrator',
                            $calendarAttachment ? [$calendarAttachment] : [],   // Kalender-Anhang, falls vorhanden
                            $contactInfo,  // zusätzliche Information als Nachsatz
                            true  // skipCopy = true, um doppelte Emails zu vermeiden
                        );
                    }
                    
                    // Zweite Admin-E-Mail versenden, falls konfiguriert und unterschiedlich
                    if (!empty($systemEmail) && $systemEmail !== $contactEmail) {
                        $adminEmailText = $emailText . $adminInfo;
                        $backendMailService->sendCustomEmail(
                            '[ADMIN-KOPIE] ' . $subject,
                            $adminEmailText,
                            $systemEmail,
                            'System-Administrator',
                            $calendarAttachment ? [$calendarAttachment] : [],   // Kalender-Anhang, falls vorhanden
                            $contactInfo  // zusätzliche Information als Nachsatz
                        );
                    }
                    
                    return true;
                } else {
                    // Fallback auf die alte Methode, wenn Backend\Service\MailService nicht verfügbar ist
                    $this->sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails,
                        $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede, $rechnungsInfo, $zahlungshinweis);
                }
            } catch (\Exception $e) {
                // Fallback auf die alte Methode
                $this->sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails,
                    $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede);
            }

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    /**
     * Send an edit notification email when an admin modifies a booking
     *
     * @param Booking $booking
     * @param User $user
     * @param array $changes Array of changed fields with 'old' and 'new' values
     * @param User $sessionUser The admin user who made the change
     * @param mixed $squareManager
     * @return boolean
     */
    public function sendAdminBookingEditEmail(Booking $booking, User $user, array $changes, $sessionUser, $squareManager = null)
    {
        if (empty($user->need('email'))) {
            return false;
        }

        try {
            // Resolve square manager if not passed
            if (!$squareManager) {
                $squareManager = $this->serviceLocator->get('Square\Manager\SquareManager');
            }

            // Current booking details for the email
            $squareName = $this->t('not specified');
            $formattedDate = '[Datum nicht verfügbar]';
            $formattedTime = '[Startzeit nicht verfügbar]';
            $formattedEndTime = '[Endzeit nicht verfügbar]';

            try {
                if ($booking->get('sid')) {
                    $square = $squareManager->get($booking->need('sid'));
                    $squareName = $square->need('name');
                }
            } catch (\Exception $e) {
                // Fallback
            }

            try {
                $reservationManager = $this->serviceLocator->get('Booking\Manager\ReservationManager');
                $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);
                if (!empty($reservations)) {
                    $reservation = current($reservations);
                    if ($reservation->get('date')) {
                        $date = new \DateTime($reservation->need('date'));
                        $formattedDate = $date->format('d.m.Y');
                    }
                    if ($reservation->get('time_start')) {
                        $formattedTime = substr($reservation->get('time_start'), 0, 5);
                    }
                    if ($reservation->get('time_end')) {
                        $formattedEndTime = substr($reservation->get('time_end'), 0, 5);
                    }
                }
            } catch (\Exception $e) {
                // Fallback
            }

            // Personalized greeting
            $anrede = 'Hallo';
            if ($user->getMeta('gender') == 'male') {
                $anrede = 'Sehr geehrter Herr';
            } elseif ($user->getMeta('gender') == 'female') {
                $anrede = 'Sehr geehrte Frau';
            }
            if ($user->getMeta('lastname')) {
                $anrede .= ' ' . $user->getMeta('lastname');
            } else {
                $anrede .= ' ' . $user->need('alias');
            }

            // Build changes text
            $changesText = $this->t('Changes') . ":\n";
            foreach ($changes as $field => $change) {
                $label = '';
                $oldFormatted = $change['old'];
                $newFormatted = $change['new'];

                switch ($field) {
                    case 'sid':
                        $label = $this->t('Square');
                        try {
                            $oldSquare = $squareManager->get($change['old']);
                            $oldFormatted = $oldSquare->need('name');
                        } catch (\Exception $e) {
                            $oldFormatted = $change['old'];
                        }
                        try {
                            $newSquare = $squareManager->get($change['new']);
                            $newFormatted = $newSquare->need('name');
                        } catch (\Exception $e) {
                            $newFormatted = $change['new'];
                        }
                        break;
                    case 'date':
                        $label = $this->t('Date');
                        try {
                            $oldDate = new \DateTime($change['old']);
                            $oldFormatted = $oldDate->format('d.m.Y');
                        } catch (\Exception $e) {}
                        try {
                            $newDate = new \DateTime($change['new']);
                            $newFormatted = $newDate->format('d.m.Y');
                        } catch (\Exception $e) {}
                        break;
                    case 'time_start':
                        $label = $this->t('Time (Start)');
                        $oldFormatted = substr($change['old'], 0, 5) . ' ' . $this->t('Clock');
                        $newFormatted = substr($change['new'], 0, 5) . ' ' . $this->t('Clock');
                        break;
                    case 'time_end':
                        $label = $this->t('Time (End)');
                        $oldFormatted = substr($change['old'], 0, 5) . ' ' . $this->t('Clock');
                        $newFormatted = substr($change['new'], 0, 5) . ' ' . $this->t('Clock');
                        break;
                    case 'quantity':
                        $label = $this->t('Number of players');
                        break;
                    case 'status_billing':
                        $label = $this->t('Billing status');
                        $oldFormatted = $this->t(ucfirst($change['old']));
                        $newFormatted = $this->t(ucfirst($change['new']));
                        break;
                    case 'notes':
                        $label = $this->t('Notes');
                        break;
                    case 'gp':
                        $label = $this->t('Guest player');
                        $oldFormatted = $change['old'] === '1' ? $this->t('Yes') : $this->t('No');
                        $newFormatted = $change['new'] === '1' ? $this->t('Yes') : $this->t('No');
                        break;
                    case 'uid':
                        // Skip user change in customer email
                        continue 2;
                    default:
                        $label = $field;
                }

                $changesText .= sprintf("\n- %s: %s → %s", $label, $oldFormatted, $newFormatted);
            }

            $buchungsDetails = sprintf(
                $this->t("Current booking details") . ":\n\n- %s: %s\n\n- %s: %s\n\n- %s: %s - %s %s\n\n- %s: %s",
                $this->t('Square'), $squareName,
                $this->t('Date'), $formattedDate,
                $this->t('Time'), $formattedTime, $formattedEndTime, $this->t('Clock'),
                $this->t('Booking nr.'), $booking->need('bid')
            );

            $subject = sprintf($this->t('Your booking has been modified (Booking nr. %s)'), $booking->need('bid'));

            // Contact info footer
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');
            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('This message was sent automatically. If you have questions, please contact our support team');
                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf(' %s %s', $this->t('at'), $contactEmail);
                }
                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= ' ' . $this->t('or');
                    }
                    $contactInfo .= sprintf(' %s %s %s', $this->t('on'), $this->t('our website'), $clientWebsite);
                }
                $contactInfo .= '.';
            }

            // Bill and payment info
            $rechnungsInfo = '';
            $budgetInfo = '';
            $zahlungshinweis = '';

            try {
                $bookingBillManager = $this->serviceLocator->get('Booking\Manager\Booking\BillManager');
                $bills = $bookingBillManager->getBy(['bid' => $booking->need('bid')], 'bbid ASC');
                $billTotal = 0;
                if ($bills) {
                    $rechnungsInfo .= "\n\n" . $this->t('Bill') . ":\n";
                    foreach ($bills as $bill) {
                        $billTotal += $bill->get('price');
                        $rechnungsInfo .= "\n- " . $bill->get('description');
                        $rechnungsInfo .= " → " . number_format($bill->get('price') / 100, 2, ',', '.') . " €";
                    }
                    $rechnungsInfo .= "\n\n" . $this->t('Total') . ": " . number_format($billTotal / 100, 2, ',', '.') . " €";
                    $rechnungsInfo .= "\n" . $this->t('Billing status') . ": " . $this->t(ucfirst($booking->get('status_billing')));
                }

                // Budget deduction info
                if ($booking->getMeta('budgetpayment') === 'true') {
                    $budgetInfo = "\n\n" . sprintf(
                        $this->t('The amount of %s € has been deducted from your budget. Remaining budget: %s €'),
                        number_format($billTotal / 100, 2, ',', '.'),
                        $booking->getMeta('newbudget', '0.00')
                    );
                }

                // Budget refund info
                if ($booking->getMeta('budgetrefund') === 'true') {
                    $refundedAmount = $booking->getMeta('refundedAmount', '0.00');
                    $budgetInfo = "\n\n" . sprintf(
                        $this->t('A refund of %s € has been credited to your budget.'),
                        $refundedAmount
                    );
                }

                // Payment instructions when pending
                if ($billTotal > 0 && $booking->get('status_billing') === 'pending') {
                    $paypalEmail = $this->config('paypalEmail') ?: 'payment@your-domain.com';
                    $zahlungshinweis = "\n\n" . $this->t('Payment instructions:')
                        . "\n" . sprintf($this->t('Please transfer the amount via PayPal Friends & Family to %s or use the money letterbox at the office.'), $paypalEmail)
                        . "\n" . $this->t('The booking is only valid after payment is completed.');
                }
            } catch (\Exception $e) {
                // Bill info not available
            }

            $emailText = sprintf(
                "%s,\n\n%s\n\n%s\n\n%s%s%s%s",
                $anrede,
                $this->t('Your booking has been modified by our team.'),
                $changesText,
                $buchungsDetails,
                $rechnungsInfo,
                $budgetInfo,
                $zahlungshinweis
            );

            // Send via Backend MailService
            if ($this->serviceLocator->has('Backend\Service\MailService')) {
                $backendMailService = $this->serviceLocator->get('Backend\Service\MailService');

                $backendMailService->sendCustomEmail(
                    $subject,
                    $emailText,
                    $user->need('email'),
                    $user->need('alias'),
                    [],
                    $contactInfo,
                    false
                );

                // Admin copy
                $adminName = method_exists($sessionUser, 'need') ? $sessionUser->need('alias') : 'Unbekannt';
                $adminEmail = method_exists($sessionUser, 'need') ? $sessionUser->need('email') : '';

                $adminInfo = sprintf(
                    "\n\n\n==================================================\n%s:\n--------------------------------------------------\n%s: %s (%s)\n%s: %s\n==================================================\n\n",
                    $this->t('Internal admin information'),
                    $this->t('Booking modified by'),
                    $adminName,
                    $adminEmail,
                    $this->t('Time of modification'),
                    date('d.m.Y H:i:s')
                );

                $clientContactEmail = $this->option('client.contact.email', '');
                if (!empty($clientContactEmail)) {
                    $backendMailService->sendCustomEmail(
                        '[ADMIN-KOPIE] ' . $subject,
                        $emailText . $adminInfo,
                        $clientContactEmail,
                        'Administrator',
                        [],
                        $contactInfo,
                        true
                    );
                }

                return true;
            }

            return false;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Fallback-Methode zum Senden der Buchungsbestätigungsemail, wenn Backend\Service\MailService nicht verfügbar ist
     */
    protected function sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails,
        $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede, $rechnungsInfo = '', $zahlungshinweis = '')
    {
        try {
            // Versuche den Mail-Service zu erhalten, mit robuster Fehlerbehandlung
            if (!$this->serviceLocator->has('Base\Service\MailService')) {
                throw new \Exception("MailService ist nicht als Service registriert");
            }
            
            $mailService = $this->serviceLocator->get('Base\Service\MailService');
            if (!$mailService) {
                throw new \Exception("MailService konnte nicht initialisiert werden");
            }
            
            // Verwende Konfigurationswerte aus den Client-Einstellungen
            $fromAddress = $this->option('client.website.contact', 'noreply@example.com');
            if (strpos($fromAddress, 'mailto:') === 0) {
                $fromAddress = substr($fromAddress, 7); // Entferne "mailto:"
            }
            
            $fromName = sprintf('%s %s', 
                $this->option('client.name.short', 'BS'),
                $this->option('service.name.full', 'Buchungssystem')
            );
            
            // Vollständigen E-Mail-Text zusammenbauen
            $body = sprintf(
                "%s,\n\nwir haben den Platz für Sie gebucht.\n\n%s%s%s\n\n%s%s",
                $anrede,
                $buchungsDetails,
                $rechnungsInfo,
                $zahlungshinweis,
                $stornierungsBedingungen,
                !empty($paypalInfo) ? "\n\n" . $paypalInfo : ""
            );
            
            // Send to user - ACHTUNG: sendPlain statt sendTextMail verwenden
            // Wenn ICS-Anhang verfügbar, diesen hinzufügen
            if ($calendarAttachment) {
                $mailService->sendPlain(
                    $fromAddress,         // fromAddress
                    $fromName,            // fromName
                    $fromAddress,         // replyToAddress
                    $fromName,            // replyToName
                    $user->need('email'), // toAddress
                    $user->need('alias'), // toName
                    $subject,             // subject
                    $body,                // text
                    [$calendarAttachment] // attachments - ICS-Anhang
                );
            } else {
                // Keine Anhänge notwendig
                $mailService->sendPlain(
                    $fromAddress,         // fromAddress
                    $fromName,            // fromName
                    $fromAddress,         // replyToAddress
                    $fromName,            // replyToName
                    $user->need('email'), // toAddress
                    $user->need('alias'), // toName
                    $subject,             // subject
                    $body                 // text
                );
            }
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Prüft, ob ein reservierter Platz einen Zugangscode hat
     *
     * @param Square $square Der Platz
     * @return string|null Der Zugangscode oder null, wenn keiner vorhanden
     */
    protected function checkReservedSquareHasDoorCode($square)
    {
        if (!$square) {
            return null;
        }
        
        try {
            // Prüfen, ob ein Zugangscode existiert
            $doorCode = $square->getMeta('door-code', '');
            if (!empty($doorCode)) {
                return $doorCode;
            }
        } catch (\Exception $e) {
            // Continue despite errors
        }

        return null;
    }

    /**
     * Erstellt einen iCalendar-Anhang für eine Buchung
     *
     * @param \Booking\Entity\Booking $booking
     * @param string $squareName
     * @param string $formattedDate
     * @param string $formattedTime
     * @param string $formattedEndTime
     * @param User $user
     * @return array|null Der Anhang oder null bei Fehlern
     */
    protected function createICalendarAttachment($booking, $squareName, $formattedDate, $formattedTime, $formattedEndTime, $user = null)
    {
        try {
            // Lokale Zeitzone setzen
            $timezone = new \DateTimeZone('Europe/Berlin');
            
            // Datum und Zeit-Angaben aus der Reservierung extrahieren
            $startDate = \DateTime::createFromFormat('d.m.Y H:i:s', $formattedDate . ' ' . $formattedTime, $timezone);
            if (!$startDate) {
                // Fallback, falls das Format nicht passt
                $startDate = new \DateTime($formattedDate . ' ' . $formattedTime, $timezone);
            }
            
            // Endzeit, falls vorhanden
            $endDate = clone $startDate;
            if ($formattedEndTime) {
                $endTime = explode(':', $formattedEndTime);
                $endHour = isset($endTime[0]) ? (int)$endTime[0] : 0;
                $endMinute = isset($endTime[1]) ? (int)$endTime[1] : 0;
                
                $endDate->setTime($endHour, $endMinute);
            }
            
            $clientName = $this->option('client.name', 'Online-Platzbuchung');
            $locationDetails = sprintf("%s (%s)", 
                $squareName, 
                $this->option('client.name.full', 'Platzbuchungssystem'));
            
            // iCalendar erstellen
            $ics = "BEGIN:VCALENDAR\r\n";
            $ics .= "VERSION:2.0\r\n";
            $ics .= "PRODID:-//" . $clientName . "//DE\r\n";
            $ics .= "CALSCALE:GREGORIAN\r\n";
            $ics .= "METHOD:REQUEST\r\n";
            $ics .= "BEGIN:VEVENT\r\n";
            $ics .= "UID:" . md5($booking->need('bid') . time()) . "\r\n";
            $ics .= "DTSTAMP:" . gmdate('Ymd\THis\Z') . "\r\n";
            
            // Korrekte Zeitumwandlung für iCal (von lokal nach UTC)
            $utcTimezone = new \DateTimeZone('UTC');
            $startDateUTC = clone $startDate;
            $startDateUTC->setTimezone($utcTimezone);
            $endDateUTC = clone $endDate;
            $endDateUTC->setTimezone($utcTimezone);
            
            $ics .= "DTSTART:" . $startDateUTC->format('Ymd\THis\Z') . "\r\n";
            $ics .= "DTEND:" . $endDateUTC->format('Ymd\THis\Z') . "\r\n";
            
            // Lokale Zeitzone definieren (für Clients, die VTIMEZONE unterstützen)
            $ics .= "BEGIN:VTIMEZONE\r\n";
            $ics .= "TZID:Europe/Berlin\r\n";
            $ics .= "BEGIN:STANDARD\r\n";
            $ics .= "DTSTART:20231029T030000\r\n";
            $ics .= "TZOFFSETFROM:+0200\r\n";
            $ics .= "TZOFFSETTO:+0100\r\n";
            $ics .= "RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU\r\n";
            $ics .= "END:STANDARD\r\n";
            $ics .= "BEGIN:DAYLIGHT\r\n";
            $ics .= "DTSTART:20240331T020000\r\n";
            $ics .= "TZOFFSETFROM:+0100\r\n";
            $ics .= "TZOFFSETTO:+0200\r\n";
            $ics .= "RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU\r\n";
            $ics .= "END:DAYLIGHT\r\n";
            $ics .= "END:VTIMEZONE\r\n";
            
            // Verbesserte Informationen zum Platz
            $ics .= "SUMMARY:Platzbuchung: " . $squareName . "\r\n";
            $ics .= "DESCRIPTION:Ihre Platzbuchung bei " . $clientName . "\\n\\n";
            $ics .= "Platz: " . $squareName . "\\n";
            $ics .= "Datum: " . $formattedDate . "\\n";
            $ics .= "Zeit: " . $formattedTime . " - " . $formattedEndTime . " Uhr\\n";
            $ics .= "Buchungs-Nr: " . $booking->need('bid') . "\\n\\n";
            
            // Zusätzliche Informationen wie einen Zugangscode einfügen, falls vorhanden
            $squareId = $booking->get('sid');
            if ($squareId) {
                // Hole Square über den SquareManager
                $square = null;
                if ($this->serviceLocator->has('Square\Manager\SquareManager')) {
                    $squareManager = $this->serviceLocator->get('Square\Manager\SquareManager');
                    try {
                        $square = $squareManager->get($squareId);
                        if ($square) {
                            $doorCode = $this->checkReservedSquareHasDoorCode($square);
                            if ($doorCode) {
                                $ics .= "Zugangscode: " . $doorCode . "\\n";
                            }
                        }
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }
                }
            }
            
            $ics .= "LOCATION:" . $locationDetails . "\r\n";
            $ics .= "STATUS:CONFIRMED\r\n";
            $contactEmail = $this->option('client.website.contact', '');
            if (strpos($contactEmail, 'mailto:') === 0) {
                $contactEmail = substr($contactEmail, 7); // Entferne "mailto:"
            }
            $ics .= "ORGANIZER;CN=\"" . $clientName . "\":MAILTO:" . $contactEmail . "\r\n";
            
            // Füge Teilnehmer hinzu, wenn Benutzer übergeben wurde
            if ($user) {
                $ics .= "ATTENDEE;CN=\"" . $user->need('alias') . "\":MAILTO:" . $user->need('email') . "\r\n";
            }
            
            $ics .= "END:VEVENT\r\n";
            $ics .= "END:VCALENDAR\r\n";
            
            // Anhang als Array zurückgeben
            return array(
                'content' => $ics,
                'filename' => 'buchung_' . $booking->need('bid') . '_' . $squareName . '.ics',
                'name' => 'buchung_' . $booking->need('bid') . '_' . $squareName . '.ics',
                'type' => 'text/calendar'
            );
        } catch (\Exception $e) {
            return null;
        }
    }
}
