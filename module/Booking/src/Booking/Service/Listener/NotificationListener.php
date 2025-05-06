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

        $vCalendar = new \Eluceo\iCal\Component\Calendar($this->optionManager->get('client.website'));
        $vEvent = new \Eluceo\iCal\Component\Event();
        $vEvent
            ->setDtStart($reservationStart)
            ->setDtEnd($reservationEnd)
            ->setNoTime(false)
            ->setSummary($this->optionManager->get('client.name.full') . ' - ' . $square->need('name'))
        ;
        $vCalendar->addComponent($vEvent);

        $subject = sprintf($this->t('Your %s-booking for %s'),
            $this->optionManager->get('subject.square.type'),
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
            $playerNamesUnserialized = @unserialize($playerNames);

            if (is_iterable($playerNamesUnserialized)) {
                $message .= "\n\nAngegebene Mitspieler:";

                foreach ($playerNamesUnserialized as $i => $playerName) {
                    $message .= sprintf("\n%s. %s",
                        $i + 1, $playerName['value']);
                }
            }
        }
	    
        # price notice
        $message .= "\n\n" . $this->t('Bill'). ":\n";

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

                $message .= "\n"; 
                $message .= $bill->get('description') . " (" . $bill->get('quantity') . " " . $items . ")";
                $message .= " -> ";
                $message .= $priceFormatHelper($bill->get('price'), $bill->get('rate'), $bill->get('gross'));
        }
        $message .= "\n\n";
        $message .= $this->t('Total');
        $message .= " -> ";
        $message .= $priceFormatHelper($total);    

        $message .= "\n\n"; 

        $message = $message . sprintf($this->t('Should you have any questions and commentaries, please contact us through Email - %s'),
             //$this->optionManager->get('client.contact.email'));
                str_replace('mailto:', '', $this->optionManager->get('client.website.contact')));

        if ($square->get('allow_notes') && $booking->getMeta('notes')) {
            $message .= "\n\nAnmerkungen:";
            $message .= "\n" . $booking->getMeta('notes');
        }

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

        $message = sprintf($this->t('we have just cancelled %s "%s", %s for you (booking id: %s).'),
            $this->optionManager->get('subject.square.type'),
            $square->need('name'),
            $dateRangerHelper($reservationStart, $reservationEnd),
            $booking->get('bid'));

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
