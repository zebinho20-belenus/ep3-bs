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
        $this->authorize('admin.booking');

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

                /* Process form (note, that reservation and booking are not available here) */

                if ($d['bf-rid']) {

                    /* Update booking/reservation */

                    $savedBooking = $this->backendBookingUpdate($d['bf-rid'], $d['bf-user'], $d['bf-time-start'], $d['bf-time-end'], $d['bf-date-start'],
                        $d['bf-sid'], $d['bf-status-billing'], $d['bf-quantity'], $d['bf-notes'], $params['editMode']);

                    $bid = $savedBooking->get('bid');
                    $square = $squareManager->get($savedBooking->get('sid'));

                    if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                            $squareControlService->updateDoorCode($bid);
                    }

                } else {

                    /* Create booking/reservation */

                    $savedBooking = $this->backendBookingCreate($d['bf-user'], $d['bf-time-start'], $d['bf-time-end'], $d['bf-date-start'], $d['bf-date-end'],
                        $d['bf-repeat'], $d['bf-sid'], $d['bf-status-billing'], $d['bf-quantity'], $d['bf-notes'], $sessionUser->get('alias'));
          
                    // Get the user object for the booking
                    $userManager = $serviceManager->get('User\Manager\UserManager');
                    $user = $userManager->get($savedBooking->get('uid'));
                    
                    // Store the admin user information in the booking metadata
                    $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
                    $savedBooking->setMeta('creator', $sessionUser->get('alias'));
                    $savedBooking->setMeta('created', date('Y-m-d H:i:s'));
                    $savedBooking->setMeta('admin_created', 'true');
                    $bookingManager->save($savedBooking);
                    
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
                $playerNamesUnserialized = @unserialize($booking->getMeta('player-names'));

                if ($playerNamesUnserialized && is_array($playerNamesUnserialized)) {
                    foreach ($playerNamesUnserialized as $i => $playerName) {
                        $playerNameNotes .= sprintf('<div>%s. %s</div>',
                            $i + 1, $playerName['value']);
                    }
                }
            }

            $editForm->get('bf-quantity')->setOption('notes', $playerNameNotes);
        }

        if (! $sessionUser->can(['calendar.create-subscription-bookings'])) {
            return $this->ajaxViewModel(array_merge($params, array(
            'editMode' => 'no_subscr',
            'editForm' => $editForm,
            'booking' => $booking,
            'reservation' => $reservation,
            'sessionUser' => $sessionUser,
            )));
        }

        return $this->ajaxViewModel(array_merge($params, array(
            'editForm' => $editForm,
            'booking' => $booking,
            'reservation' => $reservation,
            'sessionUser' => $sessionUser,
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

        if ($this->params()->fromQuery('confirmed') == 'true') {

            if ($editMode == 'reservation') {
                $this->authorize(['calendar.delete-single-bookings', 'calendar.delete-subscription-bookings']);

                $reservationManager->delete($reservation);

                $this->flashMessenger()->addSuccessMessage('Reservation has been deleted');
            } else {

                if ($this->params()->fromQuery('cancel') == 'true') {
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
                    if ($booking->need('status') == 'cancelled' && $booking->get('status_billing') == 'paid' && !$booking->getMeta('refunded') == 'true') {
                        $booking->setMeta('refunded', 'true');
                        $bookingManager->save($booking);

                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $user = $userManager->get($booking->get('uid'));

                        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager'); 

                        $bills = $bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
                        $total = 0;
                        if ($bills) {
                            foreach ($bills as $bill) {
                                $total += $bill->need('price');
                            }
                        }

                        $olduserbudget = $user->getMeta('budget');
                        if ($olduserbudget == null || $olduserbudget == '') {
                            $olduserbudget = 0;
                        }

                        $newbudget = ($olduserbudget*100+$total)/100;

                        $user->setMeta('budget', $newbudget);
                        $userManager->save($user);
                    }
                    
                    // Send cancellation email directly
                    $userManager = $serviceManager->get('User\Manager\UserManager');
                    $user = $userManager->get($booking->get('uid'));
                    
                    try {
                        $this->sendAdminCancellationEmail($booking, $user);
                    } catch (\Exception $e) {
                        // Continue despite errors
                    }
                    
                    $this->flashMessenger()->addSuccessMessage('Booking has been cancelled');
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
                    
                    // Now delete the booking
                    $bookingManager->delete($booking);

                    $this->flashMessenger()->addSuccessMessage('Booking has been deleted');
                }
            }

            return $this->redirect()->toRoute('frontend', [], ['query' => []]);
        }

        if ($editMode == 'reservation') {
            $template = 'backend/booking/delete.reservation.phtml';
        } else {
            $template = null;
        }

        return $this->ajaxViewModel(array(
            'rid' => $rid,
        ), null, $template);
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

        $playerNames = @unserialize($booking->getMeta('player-names'));

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
                    error_log(sprintf("Square für Buchung %s gefunden: %s", $booking->get('bid'), $squareName));
                }
            } catch (\Exception $e) {
                // Fallback wenn Square nicht gefunden wird
                error_log(sprintf("Square für Buchung %s nicht gefunden: %s", $booking->get('bid'), $e->getMessage()));
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
                        error_log(sprintf("Formatiertes Datum für Buchung %s: %s", $booking->get('bid'), $formattedDate));
                    }
                    
                    if ($reservation->get('time_start')) {
                        $formattedTime = $reservation->get('time_start');
                    }
                    
                    if ($reservation->get('time_end')) {
                        $formattedEndTime = $reservation->get('time_end');
                    }
                } else {
                    error_log(sprintf("Keine Reservierungen für Buchung %s gefunden", $booking->get('bid')));
                }
            } catch (\Exception $e) {
                error_log(sprintf("Fehler beim Abrufen der Reservierungen für Buchung %s: %s", $booking->get('bid'), $e->getMessage()));
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
            
            $clientName = $this->option('client.name', 'Online-Platzbuchung');
            
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
                        $contactInfo  // zusätzliche Information als Nachsatz
                    );
                    
                    error_log(sprintf("Stornierungsemail über Backend\\Service\\MailService an %s gesendet", $user->need('email')));
                    
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
                    $contactEmail = $this->option('client.website.contact', '');
                    if (strpos($contactEmail, 'mailto:') === 0) {
                        $contactEmail = substr($contactEmail, 7); // Entferne "mailto:"
                    }
                    
                    // Falls konfiguriert, System-E-Mail auch verwenden
                    //$systemEmail = $this->option('client.system.email', '');
                    $systemEmail = 'system@platzbuchung.tcn-kail.de';

                    // Admin-Kopie mit den zusätzlichen Informationen senden
                    if (!empty($contactEmail)) {
                        $adminEmailText = $emailText . $adminInfo;
                        $backendMailService->sendCustomEmail(
                            '[ADMIN-KOPIE] ' . $subject,
                            $adminEmailText,
                            $contactEmail,
                            'Administrator',
                            [],   // keine Anhänge
                            $contactInfo  // zusätzliche Information als Nachsatz
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
                            [],   // keine Anhänge
                            $contactInfo  // zusätzliche Information als Nachsatz
                        );
                    }
                    
                    return true;
                } else {
                    // Fallback auf die alte Methode, wenn Backend\Service\MailService nicht verfügbar ist
                    error_log("Backend\\Service\\MailService nicht verfügbar, verwende Fallback-Methode");
                    $this->sendAdminCancellationEmailFallback($booking, $user, $subject, $buchungsDetails, $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $anrede);
                }
            } catch (\Exception $e) {
                error_log(sprintf("Fehler bei Verwendung von Backend\\Service\\MailService: %s", $e->getMessage()));
                // Fallback auf die alte Methode
                $this->sendAdminCancellationEmailFallback($booking, $user, $subject, $buchungsDetails, $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $anrede);
            }
            
            return true;
        } catch (\Exception $e) {
            // Log the exception for debugging
            error_log(sprintf("Fehler beim Senden der Stornierungsemail: %s", $e->getMessage()));
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
            
            // Debug-Log für Fehleranalyse
            error_log(sprintf("Sende Stornierungsemail an Benutzer %s (%s)", $user->need('alias'), $user->need('email')));
            
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
            error_log(sprintf("Fehler beim Senden der E-Mail (Fallback): %s", $e->getMessage()));
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
                    error_log(sprintf("Square für Buchung %s gefunden: %s", $booking->get('bid'), $squareName));
                }
            } catch (\Exception $e) {
                // Fallback wenn Square nicht gefunden wird
                error_log(sprintf("Square für Buchung %s nicht gefunden: %s", $booking->get('bid'), $e->getMessage()));
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
                        error_log(sprintf("Formatiertes Datum für Buchung %s: %s", $booking->get('bid'), $formattedDate));
                    }
                    
                    if ($reservation->get('time_start')) {
                        $formattedTime = $reservation->get('time_start');
                    }
                    
                    if ($reservation->get('time_end')) {
                        $formattedEndTime = $reservation->get('time_end');
                    }
                } else {
                    error_log(sprintf("Keine Reservierungen für Buchung %s gefunden", $booking->get('bid')));
                }
            } catch (\Exception $e) {
                error_log(sprintf("Fehler beim Abrufen der Reservierungen für Buchung %s: %s", $booking->get('bid'), $e->getMessage()));
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
                        "%s,\n\nwir haben den Platz für Sie gebucht.\n\n%s\n\n%s\n\n%s",
                        $anrede,
                        $buchungsDetails,
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
                        $contactInfo  // zusätzliche Information als Nachsatz
                    );
                    
                    error_log(sprintf("Buchungsbestätigungsemail über Backend\\Service\\MailService an %s gesendet", $user->need('email')));
                    
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
                    $contactEmail = $this->option('client.website.contact', '');
                    if (strpos($contactEmail, 'mailto:') === 0) {
                        $contactEmail = substr($contactEmail, 7); // Entferne "mailto:"
                    }
                    
                    // Falls konfiguriert, System-E-Mail auch verwenden
                    //$systemEmail = $this->option('client.contact.email', '');
                    $systemEmail = 'system@platzbuchung.tcn-kail.de';
                    
                    // Admin-Kopie mit den zusätzlichen Informationen senden
                    if (!empty($contactEmail)) {
                        $adminEmailText = $emailText . $adminInfo;
                        $backendMailService->sendCustomEmail(
                            '[ADMIN-KOPIE] ' . $subject,
                            $adminEmailText,
                            $contactEmail,
                            'Administrator',
                            $calendarAttachment ? [$calendarAttachment] : [],   // Kalender-Anhang, falls vorhanden
                            $contactInfo  // zusätzliche Information als Nachsatz
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
                    error_log("Backend\\Service\\MailService nicht verfügbar, verwende Fallback-Methode");
                    $this->sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails, 
                        $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede);
                }
            } catch (\Exception $e) {
                error_log(sprintf("Fehler bei Verwendung von Backend\\Service\\MailService: %s", $e->getMessage()));
                // Fallback auf die alte Methode
                $this->sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails, 
                    $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede);
            }
            
            return true;
        } catch (\Exception $e) {
            // Log the exception for debugging
            error_log(sprintf("Fehler beim Senden der Buchungsbestätigungsemail: %s", $e->getMessage()));
            return false;
        }
    }
    
    /**
     * Fallback-Methode zum Senden der Buchungsbestätigungsemail, wenn Backend\Service\MailService nicht verfügbar ist
     */
    protected function sendAdminBookingCreationEmailFallback($booking, $user, $subject, $buchungsDetails, 
        $stornierungsBedingungen, $paypalInfo, $contactInfo, $clientName, $calendarAttachment, $anrede)
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
            
            // Debug-Log für Fehleranalyse
            error_log(sprintf("Sende Buchungsbestätigungsemail an Benutzer %s (%s)", $user->need('alias'), $user->need('email')));
            
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
                "%s,\n\nwir haben den Platz für Sie gebucht.\n\n%s\n\n%s\n\n%s",
                $anrede,
                $buchungsDetails,
                $stornierungsBedingungen,
                !empty($paypalInfo) ? "\n\n" . $paypalInfo : ""
            );
            
            // Protokollieren des E-Mail-Inhalts zur Fehleranalyse
            error_log(sprintf("E-Mail-Inhalt für Buchung %s: %s", $booking->get('bid'), str_replace("\n", " ", $body)));
            
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
            error_log(sprintf("Fehler beim Senden der E-Mail (Fallback): %s", $e->getMessage()));
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
            error_log(sprintf("Fehler beim Abrufen des Zugangscodes: %s", $e->getMessage()));
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
            
            // Debug-Log für Zeitinformationen
            error_log(sprintf("iCalendar-Zeitangaben: Start=%s, End=%s", 
                $startDate->format('Y-m-d H:i:s'), 
                $endDate->format('Y-m-d H:i:s')));
                
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
                        error_log(sprintf("Fehler beim Abrufen des Squares für Buchung %s: %s", 
                            $booking->get('bid'), $e->getMessage()));
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
            error_log(sprintf("Fehler beim Erstellen des iCalendar-Anhangs: %s", $e->getMessage()));
            return null;
        }
    }
}
