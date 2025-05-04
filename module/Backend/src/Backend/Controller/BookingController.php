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
                    
                    // Debug logs - Check for needed directories
                    $logDir = '/var/www/html/data/log';
                    
                    try {
                        // Debug the email process
                        error_log('[' . date('Y-m-d H:i:s') . '] ADMIN BOOKING CREATED - email process for booking ID: ' . $savedBooking->need('bid'));
                        
                        // Only send email if user has an email address
                        if ($user && $user->get('email')) {
                            // Send admin booking creation email
                            $this->sendAdminBookingCreationEmail($savedBooking, $user);
                        } else {
                            error_log('[' . date('Y-m-d H:i:s') . '] NOT SENDING EMAIL - User has no email address. User ID: ' . $user->get('uid'));
                        }
                    } catch (\Exception $e) {
                        error_log('Exception sending email: ' . $e->getMessage());
                    }
                }

                $this->flashMessenger()->addSuccessMessage('Booking has been saved');

                if ($this->params()->fromPost('bf-edit-user')) {
                    return $this->redirect()->toRoute('backend/user/edit', ['uid' => $savedBooking->get('uid')]);
                } else if ($this->params()->fromPost('bf-edit-bills')) {
                    return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $savedBooking->get('bid')]);
                } else {
                    return $this->redirect()->toRoute('frontend');
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
                    'bf-notes' => $booking->getMeta('notes'),
                ));

                if ($booking->get('status') == 'subscription' && $params['editMode'] == 'booking') {
                    $editForm->setData(array(
                        'bf-time-start' => substr($booking->getMeta('time_start', $reservation->get('time_start')), 0, 5),
                        'bf-time-end' => substr($booking->getMeta('time_end', $reservation->get('time_end')), 0, 5),
                        'bf-date-start' => $this->dateFormat($booking->getMeta('date_start', $reservation->get('date')), \IntlDateFormatter::MEDIUM),
                        'bf-date-end' => $this->dateFormat($booking->getMeta('date_end', $reservation->get('date')), \IntlDateFormatter::MEDIUM),
                        'bf-repeat' => $booking->getMeta('repeat'),
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

                    return $this->redirect()->toRoute('frontend');
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

                    return $this->redirect()->toRoute('frontend');
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
                    
                    $this->sendAdminCancellationEmail($booking, $user);
                    
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
                    
                    error_log('Admin is deleting booking ID: ' . $bookingId);
                    
                    // Get user info and send notification before deletion
                    try {
                        $userManager = $serviceManager->get('User\Manager\UserManager');
                        $user = $userManager->get($uid);
                        
                        // Send the cancellation email directly
                        $this->sendAdminCancellationEmail($booking, $user);
                        
                    } catch (\Exception $e) {
                        error_log('Error sending cancellation email before deletion: ' . $e->getMessage());
                    }
                    
                    // Now delete the booking
                    $bookingManager->delete($booking);

                    $this->flashMessenger()->addSuccessMessage('Booking has been deleted');
                }
            }

            return $this->redirect()->toRoute('frontend');
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

                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid]);
            }

            $delete = $this->params()->fromQuery('delete');

            if ($delete && is_numeric($delete) && isset($bills[$delete])) {
                $bookingBillManager->delete($delete);

                $this->flashMessenger()->addSuccessMessage('Booking-Bill position has been deleted');
                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid]);
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
                return $this->redirect()->toRoute('backend/booking/bills', ['bid' => $bid]);
            } else if ($saveAndBack) {
                return $this->redirect()->toRoute('user/bookings/bills', ['bid' => $bid]);
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
            $notes = $booking->getMeta('notes');

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
        error_log('---------- ADMIN CANCELLATION EMAIL DEBUG START ----------');
        error_log('sendAdminCancellationEmail called for booking ID: ' . $booking->need('bid'));
        file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                          '[' . date('Y-m-d H:i:s') . '] ADMIN BOOKING CREATED - email process for booking ID: ' . $booking->need('bid') . PHP_EOL . 
                          'User ID: ' . $booking->get('uid') . ', User alias: ' . $user->get('alias') . PHP_EOL,
                          FILE_APPEND);
        
        try {
            // Get the service manager and services
            $serviceManager = $this->getServiceLocator();
            
            // Get the mail service
            $mailService = $serviceManager->get('Base\Service\MailService');
            if (!$mailService) {
                error_log('ERROR: Mail service not available in BackendController');
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'ERROR: Mail service not available in BackendController' . PHP_EOL, 
                                  FILE_APPEND);
                return false;
            }
            
            // Get square details
            $squareManager = $serviceManager->get('Square\Manager\SquareManager');
            $square = $squareManager->get($booking->need('sid'));
            $squareName = $square->need('name');
            
            // Format booking time
            $bookingTime = $booking->get('time_start');
            if (!$bookingTime) {
                $bookingTime = time();
            }
            
            $formattedDate = date('d.m.Y', $bookingTime);
            $formattedTime = date('H:i', $bookingTime);
            $timeEnd = $booking->get('time_end');
            // Default to 1 hour later if end time is not set
            if (!$timeEnd) {
                $timeEnd = $bookingTime + 3600; // Default to 1 hour duration
            }
            $formattedEndTime = date('H:i', $timeEnd);
            
            // Calculate refund if applicable
            $refundMessage = '';
            if ($booking->get('status_billing') == 'paid') {
                $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
                $bills = $bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
                $total = 0;
                if ($bills) {
                    foreach ($bills as $bill) {
                        $total += $bill->need('price');
                    }
                }
                
                if ($total > 0 && $booking->getMeta('refunded') == 'true') {
                    $refundAmount = number_format($total / 100, 2);
                    $refundMessage = sprintf($this->t("\n\nA refund of %s has been processed to your account."), $refundAmount);
                }
            }
            
            // Set email content - German format
            $subject = sprintf('%s\'s Platz-Buchung wurde storniert', $user->need('alias'));
            $body = sprintf(
                "Hallo,\n\nwir haben Ihre Buchung für den Platz \"%s\", %s, %s bis %s Uhr storniert (Buchungs-Nr: %s).%s\n\nDiese Nachricht wurde automatisch gesendet. Ursprünglich gesendet an %s (%s).\n\nViele Grüße,\nIhr %s Online-Platzbuchung\n%s",
                $squareName,
                $formattedDate,
                $formattedTime,
                $formattedEndTime,
                $booking->need('bid'),
                $refundMessage,
                $user->need('alias'),
                $user->need('email'),
                $this->option('client.name') ?: 'TCN', // Fallback to TCN if client.name is not set
                $this->getRequest()->getUri()->getScheme() . '://' . $this->getRequest()->getUri()->getHost()
            );
            
            // Get email settings from config
            $fromAddress = $this->option('client.contact.email');
            $clientName = $this->option('client.name') ?: 'TCN'; // Fallback to TCN if client.name is not set
            $fromName = $clientName . ' Online-Platzbuchung';
            $toAddress = $user->need('email');
            $toName = $user->need('alias');
            
            // Fallback if client.contact.email is not set
            if (empty($fromAddress)) {
                $fromAddress = 'noreply@example.com';
                $fromName = 'Booking System';
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'WARNING: Using fallback for fromAddress: ' . $fromAddress . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // Add debug logging
            $logMessage = 'Email details:' . PHP_EOL;
            $logMessage .= 'From: ' . $fromAddress . ' (' . $fromName . ')' . PHP_EOL;
            $logMessage .= 'To: ' . $toAddress . ' (' . $toName . ')' . PHP_EOL;
            $logMessage .= 'Subject: ' . $subject . PHP_EOL;
            $logMessage .= 'Body: ' . $body . PHP_EOL;
            
            error_log($logMessage);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', $logMessage, FILE_APPEND);
            
            // Let's also try BOTH mail methods to ensure one works
            
            // 1. Direct PHP mail function (as a fallback)
            $headers = "From: $fromName <$fromAddress>\r\n";
            $headers .= "Reply-To: $fromName <$fromAddress>\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            $mailResult = mail($toAddress, $subject, $body, $headers);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              'PHP mail() result: ' . ($mailResult ? 'success' : 'failed') . PHP_EOL, 
                              FILE_APPEND);
            
            // 2. Directly send the email using MailService
            try {
                $result = $mailService->sendPlain(
                    $fromAddress,    // fromAddress
                    $fromName,       // fromName
                    $fromAddress,    // replyToAddress
                    $fromName,       // replyToName
                    $toAddress,      // toAddress
                    $toName,         // toName
                    $subject,        // subject
                    $body,           // text
                    []               // attachments (empty array)
                );
                
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'MailService result: ' . ($result ? 'success' : 'failed') . PHP_EOL, 
                                  FILE_APPEND);
            } catch (\Exception $e) {
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'MailService exception: ' . $e->getMessage() . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // 3. Try the Square\Controller\BookingController method directly
            try {
                $squareController = $serviceManager->get('ControllerManager')->get('Square\Controller\BookingController');
                if ($squareController) {
                    file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                      'Trying Square BookingController sendCancellationEmail method' . PHP_EOL, 
                                      FILE_APPEND);
                    $squareController->sendCancellationEmail($booking, $user, $total ?? 0);
                }
            } catch (\Exception $e) {
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'Square controller exception: ' . $e->getMessage() . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // Mark that notification was sent
            $booking->setMeta('cancellation_notification_sent', date('Y-m-d H:i:s'));
            $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
            $bookingManager->save($booking);
            
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              'Successfully attempted to send cancellation email to: ' . $toAddress . PHP_EOL . 
                              '---------- ADMIN CANCELLATION EMAIL DEBUG END ----------' . PHP_EOL, 
                              FILE_APPEND);
            
            return true;
        } catch (\Exception $e) {
            // Log the error but don't disrupt the cancellation process
            $errorMessage = 'ERROR in sendAdminCancellationEmail: ' . $e->getMessage() . PHP_EOL . 
                           'Exception trace: ' . $e->getTraceAsString();
            error_log($errorMessage);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              $errorMessage . PHP_EOL . 
                              '---------- ADMIN CANCELLATION EMAIL DEBUG END ----------' . PHP_EOL, 
                              FILE_APPEND);
            
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
        error_log('---------- ADMIN BOOKING CREATION EMAIL DEBUG START ----------');
        error_log('sendAdminBookingCreationEmail called for booking ID: ' . $booking->need('bid'));
        file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                          '[' . date('Y-m-d H:i:s') . '] ADMIN BOOKING CREATED - email process for booking ID: ' . $booking->need('bid') . PHP_EOL . 
                          'User ID: ' . $booking->get('uid') . ', User alias: ' . $user->get('alias') . PHP_EOL,
                          FILE_APPEND);
        
        try {
            // Get the service manager and services
            $serviceManager = $this->getServiceLocator();
            
            // Get the mail service
            $mailService = $serviceManager->get('Base\Service\MailService');
            if (!$mailService) {
                error_log('ERROR: Mail service not available in BackendController');
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'ERROR: Mail service not available in BackendController' . PHP_EOL, 
                                  FILE_APPEND);
                return false;
            }
            
            // Get square details
            $squareManager = $serviceManager->get('Square\Manager\SquareManager');
            $square = $squareManager->get($booking->need('sid'));
            $squareName = $square->need('name');
            
            // Format booking time
            $bookingTime = $booking->get('time_start');
            if (!$bookingTime) {
                $bookingTime = time();
            }
            
            $formattedDate = date('d.m.Y', $bookingTime);
            $formattedTime = date('H:i', $bookingTime);
            $timeEnd = $booking->get('time_end');
            // Default to 1 hour later if end time is not set
            if (!$timeEnd) {
                $timeEnd = $bookingTime + 3600; // Default to 1 hour duration
            }
            $formattedEndTime = date('H:i', $timeEnd);
            
            // Get door code if available
            $doorCode = $booking->getMeta('door_code');
            $doorCodeMessage = '';
            if ($doorCode) {
                $doorCodeMessage = sprintf("\n\nTür code: %s", $doorCode);
            }
            
            // Set email content - German format
            $subject = sprintf('%s\'s Platz-Buchung wurde erstellt', $user->need('alias'));
            $body = sprintf(
                "Hallo,\n\nwir haben den Platz \"%s\" am %s, %s bis %s Uhr für Sie gebucht (Buchungs-Nr: %s).%s\n\nDiese Nachricht wurde automatisch gesendet. Ursprünglich gesendet an %s (%s).\n\nViele Grüße,\nIhr %s Online-Platzbuchung\n%s",
                $squareName,
                $formattedDate,
                $formattedTime,
                $formattedEndTime,
                $booking->need('bid'),
                $doorCodeMessage,
                $user->need('alias'),
                $user->need('email'),
                $this->option('client.name') ?: 'TCN', // Fallback to TCN if client.name is not set
                $this->getRequest()->getUri()->getScheme() . '://' . $this->getRequest()->getUri()->getHost()
            );
            
            // Get email settings from config
            $fromAddress = $this->option('client.contact.email');
            $clientName = $this->option('client.name') ?: 'TCN'; // Fallback to TCN if client.name is not set
            $fromName = $clientName . ' Online-Platzbuchung';
            $toAddress = $user->need('email');
            $toName = $user->need('alias');
            
            // Fallback if client.contact.email is not set
            if (empty($fromAddress)) {
                $fromAddress = 'noreply@example.com';
                $fromName = 'Booking System';
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'WARNING: Using fallback for fromAddress: ' . $fromAddress . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // Add debug logging
            $logMessage = 'Email details:' . PHP_EOL;
            $logMessage .= 'From: ' . $fromAddress . ' (' . $fromName . ')' . PHP_EOL;
            $logMessage .= 'To: ' . $toAddress . ' (' . $toName . ')' . PHP_EOL;
            $logMessage .= 'Subject: ' . $subject . PHP_EOL;
            $logMessage .= 'Body: ' . $body . PHP_EOL;
            
            error_log($logMessage);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', $logMessage, FILE_APPEND);
            
            // Let's also try BOTH mail methods to ensure one works
            
            // 1. Direct PHP mail function (as a fallback)
            $headers = "From: $fromName <$fromAddress>\r\n";
            $headers .= "Reply-To: $fromName <$fromAddress>\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            $mailResult = mail($toAddress, $subject, $body, $headers);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              'PHP mail() result: ' . ($mailResult ? 'success' : 'failed') . PHP_EOL, 
                              FILE_APPEND);
            
            // 2. Directly send the email using MailService
            try {
                $result = $mailService->sendPlain(
                    $fromAddress,    // fromAddress
                    $fromName,       // fromName
                    $fromAddress,    // replyToAddress
                    $fromName,       // replyToName
                    $toAddress,      // toAddress
                    $toName,         // toName
                    $subject,        // subject
                    $body,           // text
                    []               // attachments (empty array)
                );
                
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'MailService result: ' . ($result ? 'success' : 'failed') . PHP_EOL, 
                                  FILE_APPEND);
            } catch (\Exception $e) {
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'MailService exception: ' . $e->getMessage() . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // 3. Try the Square\Controller\BookingController method directly
            try {
                $squareController = $serviceManager->get('ControllerManager')->get('Square\Controller\BookingController');
                if ($squareController) {
                    file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                      'Trying Square BookingController sendConfirmationEmail method' . PHP_EOL, 
                                      FILE_APPEND);
                    // Try to call the confirmation email method if it exists
                    if (method_exists($squareController, 'sendConfirmationEmail')) {
                        $squareController->sendConfirmationEmail($booking, $user);
                    }
                }
            } catch (\Exception $e) {
                file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                                  'Square controller exception: ' . $e->getMessage() . PHP_EOL, 
                                  FILE_APPEND);
            }
            
            // Mark that notification was sent
            $booking->setMeta('creation_notification_sent', date('Y-m-d H:i:s'));
            $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
            $bookingManager->save($booking);
            
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              'Successfully attempted to send booking creation email to: ' . $toAddress . PHP_EOL . 
                              '---------- ADMIN BOOKING CREATION EMAIL DEBUG END ----------' . PHP_EOL, 
                              FILE_APPEND);
            
            return true;
        } catch (\Exception $e) {
            // Log the error but don't disrupt the booking process
            $errorMessage = 'ERROR in sendAdminBookingCreationEmail: ' . $e->getMessage() . PHP_EOL . 
                           'Exception trace: ' . $e->getTraceAsString();
            error_log($errorMessage);
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              $errorMessage . PHP_EOL . 
                              '---------- ADMIN BOOKING CREATION EMAIL DEBUG END ----------' . PHP_EOL, 
                              FILE_APPEND);
            
            return false;
        } finally {
            file_put_contents('/Users/sebastian.heim/Documents/git/ep3-bs/tmp/email_debug.log', 
                              '---------- ADMIN BOOKING CREATION EMAIL DEBUG END ----------' . PHP_EOL, 
                              FILE_APPEND);
        }
    }
}
