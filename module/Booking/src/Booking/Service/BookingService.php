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

        try {
            // Determine if the user is a member
            $member = $user ? $user->getMeta('member') : 0;

            // Get guest parameter value - either from meta or from GET params
            $guestPlayer = isset($meta['guestPlayer']) ? $meta['guestPlayer'] : (isset($_GET['gp']) ? $_GET['gp'] : '0');

            // Use status_billing from controller meta if explicitly set (e.g. member with pricing > 0)
            // Otherwise default: 'member' for members without guest, 'pending' for others
            if (isset($meta['status_billing'])) {
                $statusBilling = $meta['status_billing'];
            } else {
                $statusBilling = ($member && $guestPlayer !== '1') ? 'member' : 'pending';
            }

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

            // Check if we already have a custom price from the controller
            $customPrice = isset($meta['price']) ? $meta['price'] : null;
            
            if ($customPrice) {
                
                $squareType = $this->optionManager->need('subject.square.type');
                $squareName = $this->t($square->need('name'));

                /** @var $dateRangeHelper DateRange  */
                $dateRangeHelper = $this->viewHelperManager->get('DateRange');

                $description = sprintf('%s %s, %s',
                    $squareType, $squareName,
                    $dateRangeHelper($dateTimeStart, $dateTimeEnd));
                
                // Use the custom price
                $bookingBill = new Bill(array(
                    'description' => $description,
                    'quantity' => $quantity,
                    'time' => $dateTimeEnd->getTimestamp() - $dateTimeStart->getTimestamp(),
                    'price' => $customPrice,
                    'rate' => 0, // Standard VAT rate
                    'gross' => true,
                ));
                
                array_unshift($bills, $bookingBill);
            } else {
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
            
            // If this is an admin-created booking, send email notification
            if ($isAdminCreated) {
                $this->sendAdminBookingNotification($booking, $user, $square, $dateTimeStart, $dateTimeEnd);
            }

            return $booking;

        } catch (Exception $e) {
            if ($transaction) {
                $this->connection->rollback();
            }
            
            throw $e;
        }
    }

    public function updatePaymentSingle(Booking $booking)
    {
        $this->bookingManager->save($booking);

        return $booking;
    }

    /**
     * Refund budget to user if booking was paid and not yet refunded.
     *
     * @param Booking $booking
     * @return int Total refunded amount in cents, or 0 if no refund
     */
    public function refundBudget(Booking $booking)
    {
        if ($booking->get('status_billing') != 'paid' || $booking->getMeta('refunded') == 'true') {
            return 0;
        }

        $booking->setMeta('refunded', 'true');
        $this->bookingManager->save($booking);

        $bills = $this->billManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
        $total = 0;
        if ($bills) {
            foreach ($bills as $bill) {
                $total += $bill->need('price');
            }
        }

        if ($total <= 0) {
            return 0;
        }

        $userManager = $this->serviceManager->get('User\Manager\UserManager');
        $user = $userManager->get($booking->get('uid'));

        $olduserbudget = $user->getMeta('budget');
        if ($olduserbudget == null || $olduserbudget == '') {
            $olduserbudget = 0;
        }

        $newbudget = ($olduserbudget * 100 + $total) / 100;
        $user->setMeta('budget', $newbudget);
        $userManager->save($user);

        return $total;
    }

    public function cancelSingle(Booking $booking)
    {
        $booking->set('status', 'cancelled');

        if (!$booking->getMeta('cancelled')) {
            $booking->setMeta('cancelled', date('Y-m-d H:i:s'));
        }

        $cancellor = $booking->getMeta('cancellor');
        $selfCancelled = $booking->getMeta('self_cancelled');

        // Check if this is an admin-initiated cancellation
        $isAdminCancelled = false;
        if ($cancellor && $selfCancelled != 'true') {
            $isAdminCancelled = true;
        } else if ($booking->getMeta('admin_cancelled') == 'true') {
            $isAdminCancelled = true;
        } else if ($booking->getMeta('backend_cancelled') == 'true') {
            $isAdminCancelled = true;
        }

        $this->bookingManager->save($booking);

        // Send cancellation email for admin-cancelled bookings
        if ($isAdminCancelled) {
            try {
                $userManager = $this->serviceManager->get('User\Manager\UserManager');
                $user = $userManager->get($booking->need('uid'));

                $squareManager = $this->serviceManager->get('Square\Manager\SquareManager');
                $square = $squareManager->get($booking->need('sid'));

                // Calculate refund if applicable
                $total = 0;
                if ($booking->get('status_billing') == 'paid') {
                    $bills = $this->billManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');
                    if ($bills) {
                        foreach ($bills as $bill) {
                            $total += $bill->need('price');
                        }
                    }
                }

                $this->sendCancellationNotification($booking, $user, $square, $total);
            } catch (\Exception $e) {
                // Silently continue — email failure should not break cancellation
            }
        }

        $this->getEventManager()->trigger('cancel.single', $booking);

        return $booking;
    }

    /**
     * Send cancellation notification email to user.
     */
    protected function sendCancellationNotification(Booking $booking, User $user, Square $square, $total)
    {
        if (!$this->mailService) {
            return;
        }

        $reservationManager = $this->serviceManager->get('Booking\Manager\ReservationManager');
        $reservations = $reservationManager->getBy(array('bid' => $booking->get('bid')), 'date ASC, time_start ASC');
        $reservation = current($reservations);

        $formattedDate = '';
        $formattedTime = '';
        if ($reservation) {
            $formattedDate = date('d.m.Y', strtotime($reservation->get('date')));
            $formattedTime = $reservation->get('time_start');
        }

        $squareName = $square->need('name');
        $clientName = $this->optionManager->get('client.name');
        $fromAddress = $this->optionManager->get('client.contact.email');
        if (empty($fromAddress)) {
            $fromAddress = $this->optionManager->get('client.mail');
        }

        $refundMessage = '';
        if ($total > 0) {
            $refundAmount = number_format($total / 100, 2);
            $refundMessage = "\n\nEine Rückerstattung von " . $refundAmount . " EUR wurde Ihrem Konto gutgeschrieben.";
        }

        $subject = sprintf('Ihre Buchung für %s wurde storniert', $squareName);
        $body = sprintf(
            "Hallo %s,\n\nIhre Buchung für %s am %s um %s Uhr wurde storniert.%s\n\nBei Fragen kontaktieren Sie uns bitte.\n\nViele Grüße,\n%s",
            $user->need('alias'),
            $squareName,
            $formattedDate,
            $formattedTime,
            $refundMessage,
            $clientName
        );

        $this->mailService->sendPlain(
            $fromAddress,
            $clientName,
            $fromAddress,
            $clientName,
            $user->need('email'),
            $user->need('alias'),
            $subject,
            $body,
            []
        );

        $booking->setMeta('cancellation_notification_sent', date('Y-m-d H:i:s'));
        $this->bookingManager->save($booking);
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
            $squareType = $this->optionManager->need('subject.square.type');
            $squareName = $this->t($square->need('name'));
            
            // Get email settings from config with fallbacks
            $fromAddress = $this->optionManager->get('client.contact.email');
            if (empty($fromAddress)) {
                $fromAddress = $this->optionManager->get('client.mail');
                if (empty($fromAddress)) {
                    $fromAddress = 'noreply@example.com';
                }
            }
            
            $clientName = $this->optionManager->get('client.name');
            if (empty($clientName)) {
                $clientName = 'TCN'; // Fallback name
            }
            
            $fromName = $clientName . ' Online-Platzbuchung';
            $toAddress = $user->need('email');
            $toName = $user->need('alias');
            
            // Format the date and time for German format
            $formattedDate = $dateTimeStart->format('d.m.Y');
            $formattedStartTime = $dateTimeStart->format('H:i');
            $formattedEndTime = $dateTimeEnd->format('H:i');
            
            // Get the door code if available
            $doorCode = $booking->getMeta('doorCode');
            $doorCodeMessage = '';
            if ($doorCode) {
                $doorCodeMessage = sprintf("\n\nTür code: %s", $doorCode);
            }
            
            // Set email content - German format
            $subject = sprintf('%s\'s Platz-Buchung wurde erstellt', $user->need('alias'));
            $body = sprintf(
                "Hallo,\n\nwir haben den Platz \"%s\" am %s, %s bis %s Uhr für Sie gebucht (Buchungs-Nr: %s).%s\n\nDiese Nachricht wurde automatisch gesendet. Ursprünglich gesendet an %s (%s).\n\nViele Grüße,\nIhr %s Online-Platzbuchung",
                $squareName,
                $formattedDate,
                $formattedStartTime,
                $formattedEndTime,
                $booking->need('bid'),
                $doorCodeMessage,
                $user->need('alias'),
                $user->need('email'),
                $clientName
            );
            
            // Check if mailService is available
            if (!$this->mailService) {
                return;
            }
            
            // Try PHP mail function (as a fallback)
            $headers = "From: $fromName <$fromAddress>\r\n";
            $headers .= "Reply-To: $fromName <$fromAddress>\r\n";
            $headers .= "X-Mailer: PHP/" . phpversion();
            
            mail($toAddress, $subject, $body, $headers);
            
            // Send the email using MailService
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
            $booking->setMeta('creation_notification_sent', date('Y-m-d H:i:s'));
            $this->bookingManager->save($booking);
            
        } catch (\Exception $e) {
            // Just catch exception and move on
        }
    }
}
