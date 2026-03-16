<?php

namespace Booking\Service\Listener;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\Manager\ConfigManager;
use Base\View\Helper\DateRange;
use Base\View\Helper\PriceFormatPlain;
use Booking\Manager\ReservationManager;
use Booking\Manager\Booking\BillManager;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use User\Service\MailService as UserMailService;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\EventManager\Event;
use Zend\EventManager\EventManagerInterface;
use Zend\I18n\Translator\TranslatorInterface;
use Zend\I18n\View\Helper\DateFormat;

class NotificationListener extends AbstractListenerAggregate
{

    protected $optionManager;
    protected $configManager;
    protected $reservationManager;
    protected $bookingBillManager;
    protected $squareManager;
    protected $userManager;
    protected $userMailService;
	protected $backendMailService;
    protected $dateFormatHelper;
    protected $dateRangeHelper;
    protected $priceFormatHelper;
    protected $translator;

    public function __construct(OptionManager $optionManager, ConfigManager $configManager, ReservationManager $reservationManager, BillManager $bookingBillManager, SquareManager $squareManager,
	    UserManager $userManager, UserMailService $userMailService, BackendMailService $backendMailService,
	    DateFormat $dateFormatHelper, DateRange $dateRangeHelper, PriceFormatPlain $priceFormatHelper, TranslatorInterface $translator)
    {
        $this->optionManager = $optionManager;
        $this->configManager = $configManager;
        $this->reservationManager = $reservationManager;
        $this->bookingBillManager = $bookingBillManager;
        $this->squareManager = $squareManager;
        $this->userManager = $userManager;
        $this->userMailService = $userMailService;
	    $this->backendMailService = $backendMailService;
        $this->dateFormatHelper = $dateFormatHelper;
        $this->dateRangeHelper = $dateRangeHelper;
        $this->priceFormatHelper = $priceFormatHelper;
        $this->translator = $translator;
    }

    public function attach(EventManagerInterface $events)
    {
        $events->attach('create.single', array($this, 'onCreateSingle'));
        $events->attach('cancel.single', array($this, 'onCancelSingle'));
    }

    public function onCreateSingle(Event $event)
    {
        // syslog(LOG_EMERG, '|trigger onCreateSingle|');

        $booking = $event->getTarget();

        // Suppress email for directpay bookings until payment is confirmed
        if ($booking->getMeta('suppressEmail') == 'true') {
            return;
        }
        $reservations = $this->reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);
        $reservation = current($reservations);

        // $reservation = current($booking->getExtra('reservations'));
        
        $square = $this->squareManager->get($booking->need('sid'));
        $user = $this->userManager->get($booking->need('uid'));

        $dateFormatHelper = $this->dateFormatHelper;
        $dateRangerHelper = $this->dateRangeHelper;
        $priceFormatHelper = $this->priceFormatHelper;

	    $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        $subject = sprintf($this->t('Your %s-booking %s for %s'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT));

        if ($this->configManager->get('genDoorCode') != null && $this->configManager->get('genDoorCode') == true && $square->getMeta('square_control') == true) { 
            $doorCode = $booking->getMeta('doorCode');
            $message = sprintf($this->t('we have reserved %s "%s", %s for you (booking id: %s). Thank you for your booking. Door code: %s . The booking and the code is only valid after payment is fully completed.'),
                $this->optionManager->get('subject.square.type'),
                $square->need('name'),
                $dateRangerHelper($reservationStart, $reservationEnd),
                $booking->get('bid'),
                $doorCode);
        } else {
            $message = sprintf($this->t('we have reserved %s "%s", %s for you (booking id: %s). Thank you for your booking.'),
                $this->optionManager->get('subject.square.type'),
                $square->need('name'),
                $dateRangerHelper($reservationStart, $reservationEnd),
                $booking->get('bid'));
        }

        # guest player
        $guestPlayer = $booking->getMeta('guest-player');

        if ($guestPlayer) {
            $message .= "\n\n" . $this->t('Guest player:') . ' ' . $guestPlayer;
        }


        # player names
        $playerNames = $booking->getMeta('player-names');

        if ($playerNames) {
            $playerNamesUnserialized = json_decode($playerNames, true) ?: @unserialize($playerNames, ['allowed_classes' => false]);

            if (is_iterable($playerNamesUnserialized)) {
                $message .= "\n\nAngegebene Mitspieler:";

                foreach ($playerNamesUnserialized as $i => $playerName) {
                    $message .= sprintf("\n%s. %s",
                        $i + 1, $playerName['value']);
                }
            }
        }
	    
        # price notice
        $message .= "\n\n" . str_repeat('-', 40);
        $message .= "\n" . $this->t('Bill'). ":\n";

        $total = 0;
        $bills = $this->bookingBillManager->getBy(array('bid' => $booking->get('bid')), 'bbid ASC');

        foreach ($bills as $bill) {
                $total += $bill->get('price');
                $items = 'x';
                $squareUnit = '';

                if ($bill->get('quantity') == 1) {
                    $squareUnit = $this->optionManager->get('subject.square.unit');
                } else {
                    $squareUnit = $this->optionManager->get('subject.square.unit.plural');
                }

                if ($bill->get('time')) {
                    $items = $squareUnit;
                }

                $message .= "\n- ";
                $message .= $bill->get('description') . " (" . $bill->get('quantity') . " " . $items . ")";
                $message .= " → ";
                $message .= $priceFormatHelper($bill->get('price'), $bill->get('rate'), $bill->get('gross'));
        }
        $message .= "\n\n";
        $message .= $this->t('Total');
        $message .= ": ";
        $message .= $priceFormatHelper($total);
        $message .= "\n" . $this->t('Billing status') . ": " . $this->t(ucfirst($booking->get('status_billing')));
        $message .= "\n" . str_repeat('-', 40);

        if ($booking->get('status_billing') == 'pending' && $booking->getMeta('gp') == '1' && $booking->getMeta('directpay') != 'true' && $booking->getMeta('budgetpayment') != 'true') {
            $message .= "\n\n" . str_repeat('-', 40);
            $message .= "\n" . $this->t('Payment instructions:');
            $paypalEmail = $this->configManager->get('paypalEmail') ?: 'payment@your-domain.com';
            $message .= "\n" . sprintf($this->t('Please transfer the amount before the game via PayPal Friends & Family to %s or use the money letterbox at the office. Another option is instant bank transfer to our bank account.'), $paypalEmail);
            $message .= "\n" . $this->t('The booking is only valid after payment is completed.');
        }

        # payment method and budget info for email
        $paymentMethod = $booking->getMeta('paymentMethod');
        $hasBudget = $booking->getMeta('hasBudget') == 'true';
        $budgetpayment = $booking->getMeta('budgetpayment') == 'true';
        $methodLabels = ['paypal' => 'PayPal', 'stripe' => 'Stripe', 'klarna' => 'Klarna', 'billing' => $this->t('Invoice')];

        if ($budgetpayment || $paymentMethod || $hasBudget) {
            $message .= "\n\n" . $this->t('Payment information:');

            if ($budgetpayment) {
                // Full payment from budget
                $message .= "\n" . $this->t('Payment method:') . ' ' . $this->t('Budget');
            } elseif ($hasBudget && $paymentMethod) {
                // Partial: budget + payment gateway
                $methodLabel = isset($methodLabels[$paymentMethod]) ? $methodLabels[$paymentMethod] : $paymentMethod;
                $message .= "\n" . $this->t('Payment method:') . ' ' . $this->t('Budget') . ' + ' . $methodLabel;
            } elseif ($paymentMethod) {
                // Gateway only
                $methodLabel = isset($methodLabels[$paymentMethod]) ? $methodLabels[$paymentMethod] : $paymentMethod;
                $message .= "\n" . $this->t('Payment method:') . ' ' . $methodLabel;
            }

            // Show budget deduction if budget was used
            if ($hasBudget && $booking->getMeta('budget') !== null) {
                $budget = $booking->getMeta('budget');
                $newbudget = $booking->getMeta('newbudget') !== null ? $booking->getMeta('newbudget') : '0';
                $message .= "\n" . sprintf($this->t('Budget: %s → new budget: %s'),
                    number_format((float)$budget, 2, ',', '.') . ' €',
                    number_format((float)$newbudget, 2, ',', '.') . ' €');
            }
        }

        $message .= "\n\n";

        $message = $message . sprintf($this->t('Should you have any questions and commentaries, please contact us through Email - %s'),
             //$this->optionManager->get('client.contact.email'));
                str_replace('mailto:', '', $this->optionManager->get('client.website.contact')));

        # user notes (strip internal payment tracking)
        $notes = $booking->getMeta('notes');
        if ($square->get('allow_notes') && $notes) {
            $userNotes = $notes;
            if (strpos($userNotes, ' || ') !== false) {
                $userNotes = trim(substr($userNotes, 0, strpos($userNotes, ' || ')));
            } elseif (preg_match('/^\s*(direct pay|payment with|payment_status)/', $userNotes)) {
                $userNotes = '';
            }
            $userNotes = str_replace("Anmerkungen des Benutzers:\n", '', $userNotes);
            if (trim($userNotes)) {
                $message .= "\n\n" . $this->t('Notes:');
                $message .= "\n" . trim($userNotes);
            }
        }

        // ICS calendar attachment with booking details
        $vCalendar = new \Eluceo\iCal\Component\Calendar($this->optionManager->get('client.website'));
        $vEvent = new \Eluceo\iCal\Component\Event();
        $vEvent
            ->setDtStart($reservationStart)
            ->setDtEnd($reservationEnd)
            ->setNoTime(false)
            ->setSummary($subject)
            ->setLocation($square->need('name') . ', TC Neuperlach Kail e.V., Kurt-Eisner-Str. 30, 81735 München')
            ->setDescription($message)
        ;
        $vCalendar->addComponent($vEvent);

        if ($user->getMeta('notification.bookings', 'true') == 'true') {
            $attachments = ['event.ics' => ['name' => 'event.ics', 'disposition' => true, 'type' => 'text/calendar', 'content' => $vCalendar->render()]];
            $this->userMailService->send($user, $subject, $message, $attachments);
        }

	    if ($this->optionManager->get('client.contact.email.user-notifications')) {

		    $backendSubject = sprintf($this->t('%s\'s %s-booking for %s'),
		        $user->need('alias'), $this->optionManager->get('subject.square.type'),
			    $dateFormatHelper($reservationStart, \IntlDateFormatter::MEDIUM, \IntlDateFormatter::SHORT));

		    $addendum = sprintf($this->t('Originally sent to %s (%s).'),
	            $user->need('alias'), $user->need('email'));

	        $this->backendMailService->send($backendSubject, $message, array(), $addendum);
        }
    }

    public function onCancelSingle(Event $event)
    {
        $booking = $event->getTarget();

        // Suppress cancel email when payment failed (separate payment-failed email is sent instead)
        if ($booking->getMeta('suppressCancelEmail') == 'true') {
            return;
        }
        $reservations = $this->reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);
        $reservation = current($reservations);
        $square = $this->squareManager->get($booking->need('sid'));
        $user = $this->userManager->get($booking->need('uid'));

        $dateRangerHelper = $this->dateRangeHelper;

	    $reservationTimeStart = explode(':', $reservation->need('time_start'));
        $reservationTimeEnd = explode(':', $reservation->need('time_end'));

        $reservationStart = new \DateTime($reservation->need('date'));
        $reservationStart->setTime($reservationTimeStart[0], $reservationTimeStart[1]);

        $reservationEnd = new \DateTime($reservation->need('date'));
        $reservationEnd->setTime($reservationTimeEnd[0], $reservationTimeEnd[1]);

        $subject = sprintf($this->t('Your %s-booking has been cancelled'),
            $this->optionManager->get('subject.square.type'));

        // Strukturierte Buchungsdetails
        $formattedDate = $reservationStart->format('d.m.Y');
        $formattedTimeStart = $reservationStart->format('H:i');
        $formattedTimeEnd = $reservationEnd->format('H:i');

        $buchungsDetails = sprintf(
            "- %s: %s\n- %s: %s\n- %s: %s - %s Uhr\n- %s: %s",
            $this->t('Court'), $square->need('name'),
            $this->t('Date'), $formattedDate,
            $this->t('Time'), $formattedTimeStart, $formattedTimeEnd,
            $this->t('Booking ID'), $booking->get('bid')
        );

        // Keine manuelle Anrede — userMailService->send() fuegt automatisch "Dear [alias]," voran
        $message = sprintf(
            "%s\n\n%s",
            $this->t('your booking has been cancelled.'),
            $buchungsDetails
        );

        $message .= "\n\n" . sprintf($this->t('Should you have any questions and commentaries, please contact us through Email - %s'),
            str_replace('mailto:', '', $this->optionManager->get('client.website.contact')));

        if ($user->getMeta('notification.bookings', 'true') == 'true') {
            $this->userMailService->send($user, $subject, $message);
        }

	    if ($this->optionManager->get('client.contact.email.user-notifications')) {

		    $backendSubject = sprintf($this->t('%s\'s %s-booking has been cancelled'),
		        $user->need('alias'), $this->optionManager->get('subject.square.type'));

		    $addendum = sprintf($this->t('Originally sent to %s (%s).'),
	            $user->need('alias'), $user->need('email'));

	        $this->backendMailService->send($backendSubject, $message, array(), $addendum);
        }
    }

    protected function t($message)
    {
        return $this->translator->translate($message);
    }

}
