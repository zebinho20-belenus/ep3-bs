<?php

namespace Booking\Service;

use Base\Manager\OptionManager;
use Base\Manager\ConfigManager;
use Base\Service\AbstractService;
use Base\Service\MailService;
use Base\View\Helper\DateRange;
use Booking\Entity\Booking;
use Booking\Entity\Booking\Bill;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\BookingManager;
use Booking\Manager\ReservationManager;
use DateTime;
use Exception;
use RuntimeException;
use Square\Entity\Square;
use Square\Manager\SquarePricingManager;
use User\Entity\User;
use Zend\Db\Adapter\Driver\ConnectionInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class BookingService extends AbstractService
{

    protected $optionManager;
    protected $configManager;
    protected $bookingManager;
    protected $billManager;
    protected $reservationManager;
    protected $squarePricingManager;
    protected $viewHelperManager;
    protected $connection;
    protected $mailService;
    protected $serviceManager;

    public function __construct(
        OptionManager $optionManager,
        ConfigManager $configManager,
        BookingManager $bookingManager,
        BillManager $billManager,
        ReservationManager $reservationManager,
        SquarePricingManager $squarePricingManager,
        ServiceLocatorInterface $viewHelperManager,
        ConnectionInterface $connection,
        MailService $mailService,
        ServiceLocatorInterface $serviceManager)
    {
        $this->optionManager = $optionManager;
        $this->configManager = $configManager;
        $this->bookingManager = $bookingManager;
        $this->billManager = $billManager;
        $this->reservationManager = $reservationManager;
        $this->squarePricingManager = $squarePricingManager;
        $this->viewHelperManager = $viewHelperManager;
        $this->connection = $connection;
        $this->mailService = $mailService;
        $this->serviceManager = $serviceManager;
    }

    public function createSingle(User $user, Square $square, $quantity, DateTime $dateTimeStart, DateTime $dateTimeEnd, array $bills = array(), array $meta = array())
    {
        // syslog(LOG_EMERG, 'createSingle');        
        
        if (! $this->connection->inTransaction()) {
            $this->connection->beginTransaction();
            $transaction = true;
        } else {
            $transaction = false;
        }

//        try {
//            // Determine if the user is a member
//            $member = $user ? $user->getMeta('member') : 0;
//
//            // Set status_billing to 'paid' if a member is booking
//            $statusBilling = $member ? 'paid' : 'pending';

        //zebinho20 member status_billing to paid if played with guest status_billing to pending
        try {
        // Determine if the user is a member
        $member = $user ? $user->getMeta('member') : 0;

        // Get guest parameter value
        $guestParam = isset($_GET['gp']) ? $_GET['gp'] : '0';

        // Set status_billing to 'member' if a member is booking without a guest
        // Otherwise set to 'pending' (non-member or member with guest)
        $statusBilling = ($member && $guestParam !== '1') ? 'member' : 'pending';

            // Create a new booking
            $booking = new Booking(array(
                'uid' => $user->need('uid'),
                'sid' => $square->need('sid'),
                'status' => 'single',
                'status_billing' => $statusBilling,
                'visibility' => 'public',
                'quantity' => $quantity,
            ), $meta);

            if ($this->configManager->get('genDoorCode') != null && $this->configManager->get('genDoorCode') == true && $square->getMeta('square_control') == true) {
               $booking->setMeta('doorCode', rand(100000,999999));
            }

            $this->bookingManager->save($booking);

            $reservations = $this->reservationManager->createInRange($booking, $dateTimeStart, $dateTimeEnd);

            $booking->setExtra('reservations', $reservations);

            $member = 0;
            if ($user != null && $user->getMeta('member') != null) {
               $member = $user->getMeta('member');
            }

            $pricing = $this->squarePricingManager->getFinalPricingInRange($dateTimeStart, $dateTimeEnd, $square, $quantity, $member);

            if ($pricing) {
                $squareType = $this->optionManager->need('subject.square.type');
                $squareName = $this->t($square->need('name'));

                /** @var $dateRangeHelper DateRange  */
                $dateRangeHelper = $this->viewHelperManager->get('DateRange');

                $description = sprintf('%s %s, %s',
                    $squareType, $squareName,
                    $dateRangeHelper($dateTimeStart, $dateTimeEnd));

                $bookingBill = new Bill(array(
                    'description' => $description,
                    'quantity' => $quantity,
                    'time' => $pricing['seconds'],
                    'price' => $pricing['price'],
                    'rate' => $pricing['rate'],
                    'gross' => $pricing['gross'],
                ));

                array_unshift($bills, $bookingBill);
            }

            if ($bills) {
                $extraBills = array();

                foreach ($bills as $bill) {
                    if (! ($bill instanceof Bill)) {
                        throw new RuntimeException('Invalid bills array passed');
                    }

                    $bill->set('bid', $booking->need('bid'));

                    $this->billManager->save($bill);

                    $extraBills[$bill->need('bid')] = $bill;
                }

                $booking->setExtra('bills', $extraBills);
            }

            if ($transaction) {
                $this->connection->commit();
                $transaction = false;
            }

            $this->getEventManager()->trigger('create.single', $booking);
            
            // Determine if this booking was created by an admin
            // Check for specific meta tags or request attributes that would indicate admin creation
            $isAdminCreated = false;
            
            // Check if 'admin_created' meta is set explicitly
            if ($booking->getMeta('admin_created') == 'true') {
                $isAdminCreated = true;
            }
            
            // Check if there's an admin creator name in the meta
            if (!empty($booking->getMeta('creator')) && $booking->getMeta('creator') != $user->need('alias')) {
                $isAdminCreated = true;
            }
            
            // Detect if we're in a backend context by checking the request URI if available
            if (isset($_SERVER['REQUEST_URI']) && strpos($_SERVER['REQUEST_URI'], '/backend/') !== false) {
                $isAdminCreated = true;
                // Set the admin_created flag so we can check for it later
                $booking->setMeta('admin_created', 'true');
                $this->bookingManager->save($booking);
            }
            
            // Add debug log
            error_log('Booking created with ID: ' . $booking->need('bid') . ', Admin created: ' . ($isAdminCreated ? 'Yes' : 'No'));
            
            // If this is an admin-created booking, send email notification
            if ($isAdminCreated) {
                $this->sendAdminBookingNotification($booking, $user, $square, $dateTimeStart, $dateTimeEnd);
            }

            return $booking;

        } catch (Exception $e) {
            if ($transaction) {
                $this->connection->rollback();
            }
            
            // Log any exceptions that occur
            error_log('Exception in createSingle: ' . $e->getMessage());

            throw $e;
        }
    }

    public function updatePaymentSingle(Booking $booking)
    {
        // syslog(LOG_EMERG, 'updatePaymentSingle');        
        
        $this->bookingManager->save($booking);

        $this->getEventManager()->trigger('create.single', $booking);

        return $booking;
    }

    public function cancelSingle(Booking $booking)
    {
        error_log('---------- CANCELLATION DEBUG START ----------');
        error_log('cancelSingle called for booking ID: ' . $booking->need('bid'));
        error_log('Current booking status: ' . $booking->get('status'));
        
        // Log metadata differently (without using getAllMeta which doesn't exist)
        error_log('Booking metadata (partial):');
        // Just log some common meta keys we're interested in
        $metaKeys = ['cancelled', 'cancellor', 'self_cancelled', 'admin_cancelled', 'backend_cancelled'];
        foreach ($metaKeys as $key) {
            $value = $booking->getMeta($key);
            if ($value) {
                error_log("  $key: $value");
            }
        }
        
        $booking->set('status', 'cancelled');
        
        // Set cancellation metadata
        if (!$booking->getMeta('cancelled')) {
            $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
            error_log('Added cancelled timestamp: ' . date('Y-m-d H:i:s'));
        }
        
        // Debug the cancellor value specifically
        $cancellor = $booking->getMeta('cancellor');
        error_log('Cancellor value: ' . ($cancellor ?: 'not set'));
        
        $selfCancelled = $booking->getMeta('self_cancelled');
        error_log('Self-cancelled value: ' . ($selfCancelled ?: 'not set'));
        
        // Check if this is an admin-initiated cancellation
        $isAdminCancelled = false;
        
        // Try multiple heuristics to determine if this is an admin cancellation
        if ($cancellor && $selfCancelled != 'true') {
            $isAdminCancelled = true;
            error_log('Admin cancellation detected based on cancellor presence and no self_cancelled flag');
        } else if ($booking->getMeta('admin_cancelled') == 'true') {
            $isAdminCancelled = true;
            error_log('Admin cancellation detected based on admin_cancelled meta flag');
        } else if ($booking->getMeta('backend_cancelled') == 'true') {
            $isAdminCancelled = true;
            error_log('Admin cancellation detected based on backend_cancelled meta flag');
        } else {
            error_log('No admin cancellation detected based on available metadata');
        }

        // Force admin cancellation for testing
        if (!$isAdminCancelled) {
            error_log('Forcing admin cancellation for testing purposes');
            $isAdminCancelled = true;
        }

        $this->bookingManager->save($booking);
        error_log('Booking saved with status: ' . $booking->get('status'));
        
        // Send cancellation email for admin-cancelled bookings
        if ($isAdminCancelled) {
            try {
                error_log('Preparing to send admin cancellation email for booking ID: ' . $booking->need('bid'));
                
                // Get the user details
                $userManager = $this->serviceManager->get('User\Manager\UserManager');
                if (!$userManager) {
                    error_log('ERROR: Failed to get User Manager service');
                } else {
                    error_log('Successfully got User Manager service');
                }
                
                $user = $userManager->get($booking->need('uid'));
                if (!$user) {
                    error_log('ERROR: Failed to get user with UID: ' . $booking->need('uid'));
                } else {
                    error_log('Successfully got user: ' . $user->need('alias') . ' (' . $user->need('email') . ')');
                }
                
                // Get the square details
                $squareManager = $this->serviceManager->get('Square\Manager\SquareManager');
                if (!$squareManager) {
                    error_log('ERROR: Failed to get Square Manager service');
                } else {
                    error_log('Successfully got Square Manager service');
                }
                
                $square = $squareManager->get($booking->need('sid'));
                if (!$square) {
                    error_log('ERROR: Failed to get square with SID: ' . $booking->need('sid'));
                } else {
                    error_log('Successfully got square: ' . $square->need('name'));
                }
                
                // Calculate refund if applicable
                $total = 0;
                if ($booking->get('status_billing') == 'paid') {
                    error_log('Booking is paid, calculating refund amount');
                    $bookingBillManager = $this->serviceManager->get('Booking\Manager\Booking\BillManager');
                    $bills = $bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
                    if ($bills) {
                        foreach ($bills as $bill) {
                            $total += $bill->need('price');
                        }
                    }
                    error_log('Calculated refund amount: ' . $total);
                } else {
                    error_log('Booking is not paid, no refund necessary');
                }
                
                // Get the controller to send the email
                $controllerManager = $this->serviceManager->get('ControllerManager');
                if (!$controllerManager) {
                    error_log('ERROR: Failed to get Controller Manager service');
                } else {
                    error_log('Successfully got Controller Manager service');
                }
                
                $squareController = $controllerManager->get('Square\Controller\BookingController');
                if (!$squareController) {
                    error_log('ERROR: Failed to get Square BookingController');
                } else {
                    error_log('Successfully got Square BookingController');
                }
                
                // Send the cancellation email
                error_log('About to call sendCancellationEmail');
                $squareController->sendCancellationEmail($booking, $user, $total);
                
                error_log('Successfully sent admin cancellation email for booking ID: ' . $booking->need('bid'));
            } catch (\Exception $e) {
                error_log('Failed to send admin cancellation email: ' . $e->getMessage());
                error_log('Exception trace: ' . $e->getTraceAsString());
            }
        }
        
        error_log('Triggering cancel.single event');
        $this->getEventManager()->trigger('cancel.single', $booking);
        
        error_log('---------- CANCELLATION DEBUG END ----------');
        return $booking;
    }

    /**
     * Send email notification when admin creates a booking for a user
     *
     * @param Booking $booking The booking entity
     * @param User $user The user who the booking is for
     * @param Square $square The square that was booked
     * @param DateTime $dateTimeStart The booking start time
     * @param DateTime $dateTimeEnd The booking end time
     * @return void
     */
    protected function sendAdminBookingNotification($booking, $user, $square, DateTime $dateTimeStart, DateTime $dateTimeEnd)
    {
        try {
            // Debug logging
            error_log('Attempting to send admin booking notification for booking ID: ' . $booking->need('bid'));
            
            $squareType = $this->optionManager->need('subject.square.type');
            $squareName = $this->t($square->need('name'));
            
            // Get email settings from config
            $fromAddress = $this->optionManager->need('client.mail');
            $fromName = $this->optionManager->need('client.name');
            $toAddress = $user->need('email');
            $toName = $user->need('alias');
            
            /** @var $dateRangeHelper DateRange */
            $dateRangeHelper = $this->viewHelperManager->get('DateRange');
            
            // Format the date and time
            $formattedDate = $dateTimeStart->format('d.m.Y');
            $formattedStartTime = $dateTimeStart->format('H:i');
            $formattedEndTime = $dateTimeEnd->format('H:i');
            
            // Get the door code if available
            $doorCode = $booking->getMeta('doorCode');
            $doorCodeMessage = '';
            if ($doorCode) {
                $doorCodeMessage = sprintf($this->t("\nDoor code: %s"), $doorCode);
            }
            
            // Set email content
            $subject = sprintf($this->t('Your booking for %s has been created'), $squareName);
            $body = sprintf(
                $this->t("Dear %s,\n\nWe have created a booking for you for %s on %s from %s to %s.\n\nBooking details:\n- Square: %s\n- Date: %s\n- Time: %s - %s\n- Quantity: %s%s\n\nIf you have any questions, please contact us.\n\nThank you,\n%s"),
                $user->need('alias'),
                $squareName,
                $formattedDate,
                $formattedStartTime,
                $formattedEndTime,
                $squareName,
                $formattedDate,
                $formattedStartTime,
                $formattedEndTime,
                $booking->get('quantity'),
                $doorCodeMessage,
                $fromName
            );
            
            error_log('Email content prepared for booking ID: ' . $booking->need('bid'));
            error_log('From: ' . $fromAddress . ' To: ' . $toAddress);
            
            // Check if mailService is available
            if (!$this->mailService) {
                error_log('ERROR: Mail service not available in BookingService');
                return;
            }
            
            // Send the email
            $this->mailService->sendPlain(
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
            
            // Update the booking to record that notification was sent
            $booking->setMeta('notification_sent', date('Y-m-d H:i:s'));
            $this->bookingManager->save($booking);
            
            error_log('Successfully sent admin booking notification to: ' . $toAddress . ' for booking ID: ' . $booking->need('bid'));
            
        } catch (\Exception $e) {
            // Log the error but don't disrupt the booking process
            error_log('Failed to send admin booking notification: ' . $e->getMessage() . ', trace: ' . $e->getTraceAsString());
        }
    }
}
