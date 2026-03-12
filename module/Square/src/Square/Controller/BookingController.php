<?php

namespace Square\Controller;

use Booking\Entity\Booking\Bill;
use RuntimeException;
use Zend\Json\Json;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\ServiceManager\ServiceManager;
use Zend\ServiceManager\ServiceManagerAwareInterface;
use Zend\View\Model\JsonModel;
use Zend\Http\Response;
use Payum\Core\Request\GetHumanStatus;
use Payum\Core\Reply\HttpResponse;
use Payum\Core\Reply\HttpRedirect;
use Payum\Core\Reply\ReplyInterface;
use Payum\Stripe\Request\Confirm;
use Stripe;
use GuzzleHttp\Client; 


class BookingController extends AbstractActionController
{

    public function customizationAction()
    {
        $dateStartParam = $this->params()->fromQuery('ds');
        $dateEndParam = $this->params()->fromQuery('de');
        $timeStartParam = $this->params()->fromQuery('ts');
        $timeEndParam = $this->params()->fromQuery('te');
        $squareParam = $this->params()->fromQuery('s');

        $serviceManager = $this->getServiceLocator();
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');

        $byproducts = $squareValidator->isBookable($dateStartParam, $dateEndParam, $timeStartParam, $timeEndParam, $squareParam);

        $user = $byproducts['user'];

        if (! $user) {
            $query = $this->getRequest()->getUri()->getQueryAsArray();
            $query['ajax'] = 'false';

            $this->redirectBack()->setOrigin('square/booking/customization', [], ['query' => $query]);

            return $this->redirect()->toRoute('user/login');
        }

        if (! $byproducts['bookable']) {
            throw new RuntimeException(sprintf($this->t('This %s is already occupied'), $this->option('subject.square.type')));
        }

        return $this->ajaxViewModel($byproducts);
    }

    public function confirmationAction()
    {
        $dateStartParam = $this->params()->fromQuery('ds');
        $dateEndParam = $this->params()->fromQuery('de');
        $timeStartParam = $this->params()->fromQuery('ts');
        $timeEndParam = $this->params()->fromQuery('te');
        $squareParam = $this->params()->fromQuery('s');
        $quantityParam = $this->params()->fromQuery('q', 1);
        $productsParam = $this->params()->fromQuery('p', 0);
        $playerNamesParam = $this->params()->fromQuery('pn', 0);

        // Retrieve the guest player checkbox value from the query parameters
        $guestPlayerCheckbox = $this->params()->fromQuery('gp', 0); // Default to 0 if not provided

        $serviceManager = $this->getServiceLocator();
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');

        $byproducts = $squareValidator->isBookable($dateStartParam, $dateEndParam, $timeStartParam, $timeEndParam, $squareParam);

        $user = $byproducts['user'];

        $query = $this->getRequest()->getUri()->getQueryAsArray();
        $query['ajax'] = 'false';

        if (! $user) {
            $this->redirectBack()->setOrigin('square/booking/confirmation', [], ['query' => $query]);

            return $this->redirect()->toRoute('user/login');
        } else {
            $byproducts['url'] = $this->url()->fromRoute('square/booking/confirmation', [], ['query' => $query]);
        }

        if (! $byproducts['bookable']) {
            throw new RuntimeException(sprintf($this->t('This %s is already occupied'), $this->option('subject.square.type')));
        }

        /* Check passed quantity */

        if (! (is_numeric($quantityParam) && $quantityParam > 0)) {
            throw new RuntimeException(sprintf($this->t('Invalid %s-amount choosen'), $this->option('subject.square.unit')));
        }

        $square = $byproducts['square'];

        if ($square->need('capacity') - $byproducts['quantity'] < $quantityParam) {
            throw new RuntimeException(sprintf($this->t('Too many %s for this %s choosen'), $this->option('subject.square.unit.plural'), $this->option('subject.square.type')));
        }

        $byproducts['quantityChoosen'] = $quantityParam;

        // Handle guest player checkbox logic
        if ($guestPlayerCheckbox == 1) {
            // Set billing status to pending
            $statusBilling = 'pending';
            // Get the pricing manager to calculate the non-member price
            try {
                $squarePricingManager = $this->getServiceLocator()->get('Square\Manager\SquarePricingManager');

                // Convert string dates to DateTime objects
                $dateStart = new \DateTime($dateStartParam . ' ' . $timeStartParam);
                $dateEnd = new \DateTime($dateEndParam . ' ' . $timeEndParam);

                $nonMemberPricing = $squarePricingManager->getFinalPricingInRange($dateStart, $dateEnd, $square, $quantityParam, false);

                // Store non-member pricing for later use
                if (isset($nonMemberPricing) && isset($nonMemberPricing['price'])) {
                    if ($user->getMeta('member')) {
                        // Member with guest: 50% of non-member price
                        $nonMemberPricing['price'] = $nonMemberPricing['price'] / 2;
                    }
                    // Non-member with guest: full non-member price (no discount)
                    $byproducts['price'] = $nonMemberPricing['price'];
                }
            } catch (\Exception $e) {
                $nonMemberPricing = null;
            }
        }
        elseif ($user->getMeta('member')) {
            // Members use member pricing from DB (may be 0 = free, or > 0 = paid)
            // statusBilling will be updated to 'paid' later if total > 0
            $statusBilling = 'member';
        } else {
            // If not a guest player, set billing status to the default
            $statusBilling = 'paid'; // Set this to whatever the default billing status should be
        }

        /* Check passed products */

        $products = array();

        if (! ($productsParam === '0' || $productsParam === 0)) {
            $productManager = $serviceManager->get('Square\Manager\SquareProductManager');
            $productTuples = explode(',', $productsParam);

            foreach ($productTuples as $productTuple) {
                $productTupleParts = explode(':', $productTuple);

                if (count($productTupleParts) != 2) {
                    throw new RuntimeException('Malformed product parameter passed');
                }

                $spid = $productTupleParts[0];
                $amount = $productTupleParts[1];

                if (! (is_numeric($spid) && $spid > 0)) {
                    throw new RuntimeException('Malformed product parameter passed');
                }

                if (! is_numeric($amount)) {
                    throw new RuntimeException('Malformed product parameter passed');
                }

                $product = $productManager->get($spid);

                $productOptions = explode(',', $product->need('options'));

                if (! in_array($amount, $productOptions)) {
                    throw new RuntimeException('Malformed product parameter passed');
                }

                $product->setExtra('amount', $amount);

                $products[$spid] = $product;
            }
        }

        $byproducts['products'] = $products;

        /* Check passed player names */

        if ($playerNamesParam) {
            $playerNames = Json::decode($playerNamesParam, Json::TYPE_ARRAY);

            /* Validate player names: must contain first + last name (each >= 2 chars) */
            foreach ($playerNames as $playerName) {
                $trimmedName = trim($playerName['value']);

                // Strip " Gastspieler" suffix before validation
                $nameToValidate = preg_replace('/ Gastspieler$/', '', $trimmedName);

                // Must contain at least one space (first + last name)
                $parts = preg_split('/\s+/', $nameToValidate, -1, PREG_SPLIT_NO_EMPTY);

                if (count($parts) < 2 || mb_strlen($parts[0]) < 2 || mb_strlen($parts[1]) < 2) {
                    // Backward compatibility: accept old format with single name >= 2 chars
                    if (count($parts) == 1 && mb_strlen($parts[0]) >= 2) {
                        continue;
                    }
                    throw new \RuntimeException($this->t('Please enter first and last name (min. 2 characters each)'));
                }
            }
        } else {
            $playerNames = null;
        }

        /* display payment checkout */
        if ($this->config('paypal') != null && $this->config('paypal') == true) {  
            $byproducts['paypal'] = true;
        }
        if ($this->config('stripe') != null && $this->config('stripe') == true) {
            $byproducts['stripe'] = true;
            $byproducts['stripePaymentMethods'] = $this->config('stripePaymentMethods');
            $byproducts['stripeIcon'] = $this->config('stripeIcon');

        }
        if ($this->config('klarna') != null && $this->config('klarna') == true) {
            $byproducts['klarna'] = true;
        }
        if ($this->config('billing') != null && $this->config('billing') == true) {
            $byproducts['billing'] = true;
        }
        if ($this->config('payment_default') != null) {
            $byproducts['payment_default'] = $this->config('payment_default');
        }

        $payable = false;
        $bills = array();
        $total = 0;

        $member = 0;
        if ($user != null && $user->getMeta('member') != null) {
           $member = $user->getMeta('member');
        }

        $squarePricingManager = $serviceManager->get('Square\Manager\SquarePricingManager');
        $finalPricing = $squarePricingManager->getFinalPricingInRange($byproducts['dateStart'], $byproducts['dateEnd'], $square, $quantityParam, $member);

        // Check guest player checkbox
        $guestPlayerCheckbox = $this->params()->fromQuery('gp', 0); // Default to 0 if not provided
        // Calculate court price based on member/guest status
        if ($finalPricing != null && ($finalPricing['price'] || $guestPlayerCheckbox == 1)) {
            if ($guestPlayerCheckbox == 1 && $member) {
                // Member with guest: half of non-member price
                $nonMemberPricing = $squarePricingManager->getFinalPricingInRange($byproducts['dateStart'], $byproducts['dateEnd'], $square, $quantityParam, false);
                $total += $nonMemberPricing['price'] / 2;
            } elseif ($guestPlayerCheckbox == 1) {
                // Non-member with guest: full non-member price
                $total += $finalPricing['price'];
            } else {
                // Normal booking: price from DB (member or non-member)
                $total += $finalPricing['price'];
            }
        }

        foreach ($products as $product) {
            $productPrice = $product->need('price') * $product->needExtra('amount');
            if ($guestPlayerCheckbox == 1 && $member) {
                // Only members with guest get half price on products
                $productPrice = $productPrice / 2;
            }
            $bills[] = new Bill(array(
               'description' => $product->need('name'),
               'quantity' => $product->needExtra('amount'),
               'price' => $productPrice,
               'rate' => $product->need('rate'),
               'gross' => $product->need('gross'),
            ));

           // $total+=$product->need('price') * $product->needExtra('amount');
            // Calculate the normal price for products
            $total += $productPrice;
            // Ensure the total is being stored correctly in the database
        }
        $byproducts['total'] = $total;

        // Members with total > 0 (without guest) need to pay (via budget or payment gateway)
        // For guest bookings (gp=1), statusBilling stays 'pending' until payment completes
        if ($member && $total > 0 && $guestPlayerCheckbox != 1) {
            $statusBilling = 'paid';
        }

        if ($guestPlayerCheckbox == 1 && $member && isset($nonMemberPricing) && isset($nonMemberPricing['price'])) {
            // Member with guest: half non-member price
            $byproducts['courtPrice'] = $nonMemberPricing['price'] / 2;
        } elseif ($finalPricing != null && isset($finalPricing['price'])) {
            $byproducts['courtPrice'] = $finalPricing['price'];
        } else {
            $byproducts['courtPrice'] = 0;
        }

//        // Create a Bill object for the court itself
//        if ($guestPlayerCheckbox == 1 || $finalPricing != null) {
//            // Create a description for the court rental
//            $timeDescription = date('d.m.Y, H:i', strtotime($dateStartParam)) . ' bis ' .
//                date('H:i', strtotime($dateEndParam)) . ' Uhr';
//
//            // Make sure we check if the keys exist before accessing them
//            $courtPrice = 0;
//            if ($guestPlayerCheckbox == 1 && isset($nonMemberPricing) && isset($nonMemberPricing['price'])) {
//                // Use the already halved price for guest players
//                $courtPrice = $nonMemberPricing['price'];
//                error_log("Guest player court price: " . $courtPrice);
//            } elseif ($user->getMeta('member') && $guestPlayerCheckbox != 1) {
//                // Members should pay 0 for court rental
//                $courtPrice = 0;
//                error_log("Member court price (free): " . $courtPrice);
//            } elseif ($finalPricing != null && isset($finalPricing['price'])) {
//                $courtPrice = $finalPricing['price'];
//                error_log("Regular player court price: " . $courtPrice);
//            } else {
//                // Default fallback price
//                $courtPrice = 750; // 7.50€ in cents
//                error_log("Using fixed fallback court price: " . $courtPrice);
//            }
//
//            // Make sure courtPrice is a positive number ONLY for non-members
//            if ($courtPrice <= 0 && (!$user->getMeta('member') || $guestPlayerCheckbox == 1)) {
//                // Only apply this correction for non-members or members with guests
//                $courtPrice = 750; // Use fixed fallback price of 7.50€
//                error_log("Corrected court price to fixed fallback: " . $courtPrice);
//            }
//
//
//            // Add the court price to the total
//            $total += $courtPrice;
//            $byproducts['total'] = $total;
//            error_log("Updated total price with court rental: " . $total);
//
//            // Store the court price in meta data so BookingService can use it
//            $byproducts['courtPrice'] = $courtPrice;
//        }

        $newbudget = 0;
        $byproducts['hasBudget'] = false; 
        $budgetpayment = false;

        // calculate end total from user budget
        // Budget allowed for: non-members (gp=0) and members with guest (gp=1, member=1)
        if ($user != null && $user->getMeta('budget') != null && $user->getMeta('budget') > 0 && $total > 0 && ($guestPlayerCheckbox != 1 || $member)) {
            $byproducts['hasBudget'] = true;
            $budget = $user->getMeta('budget');
            $byproducts['budget'] = $budget;
            // syslog(LOG_EMERG, 'budget: ' . $budget);
            $newtotal = $total - ($budget*100);
            if ($newtotal <= 0) {
                $budgetpayment = true;
            }
            $byproducts['newtotal'] = $newtotal;
            // syslog(LOG_EMERG, 'newtotal: ' . $newtotal);
            $newbudget = ($budget*100-$total)/100;
            if ($newbudget < 0) { 
                $newbudget = 0;
            }
            $byproducts['newbudget'] = $newbudget;
            // syslog(LOG_EMERG, 'newbudget: ' . $newbudget);

            $total = $newtotal;
        }

        if ($total > 0 ) {
            $payable = true;
        }

        $byproducts['payable'] = $payable;
        $byproducts['budgetpayment'] = $budgetpayment;
        $byproducts['guestPlayer'] = ($guestPlayerCheckbox == 1);

        /* Check booking form submission */

        $acceptRulesDocument = $this->params()->fromPost('bf-accept-rules-document');
        $acceptRulesText = $this->params()->fromPost('bf-accept-rules-text');
        $confirmationHash = $this->params()->fromPost('bf-confirm');

        // CSRF token: validate against session-stored token
        $session = new \Zend\Session\Container('csrf');
        $confirmationHashOriginal = isset($session->token) ? $session->token : '';

        if ($confirmationHash) {

            if ($square->getMeta('rules.document.file') && $acceptRulesDocument != 'on') {
                $byproducts['message'] = sprintf($this->t('%sNote:%s Please read and accept the "%s".'),
                    '<b>', '</b>', $square->getMeta('rules.document.name', 'Rules-document'));
            }

            if ($square->getMeta('rules.text') && $acceptRulesText != 'on') {
                $byproducts['message'] = sprintf($this->t('%sNote:%s Please read and accept our rules and notes.'),
                    '<b>', '</b>');
            }

            if ($confirmationHash != $confirmationHashOriginal) {
                $byproducts['message'] = sprintf($this->t('%We are sorry:%s This did not work somehow. Please try again.'),
                    '<b>', '</b>');
            }

          if (! isset($byproducts['message'])) {

            $bookingService = $serviceManager->get('Booking\Service\BookingService');
            $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');

            $notes = ''; 

            if ($square->get('allow_notes') && $this->params()->fromPost('bf-user-notes') != null && $this->params()->fromPost('bf-user-notes') != '') {
                $notes = "Anmerkungen des Benutzers:\n" . $this->params()->fromPost('bf-user-notes') . " || ";
            } 

            $payservice = $this->params()->fromPost('paymentservice');
            $meta = array('player-names' => json_encode($playerNames), 'notes' => $notes, 'gp' => $guestPlayerCheckbox, 'status_billing' => $statusBilling);
            
            // Store player names in meta data
            if (is_array($playerNames) && !empty($playerNames)) {
                // Process player names individually to ensure they're stored as scalar values
                foreach ($playerNames as $index => $playerData) {
                    if (isset($playerData['name']) && isset($playerData['value'])) {
                        $meta['playerName_' . $index] = $playerData['value'];
                    }
                }
            }

            if (($payservice == 'paypal' || $payservice == 'stripe' || $payservice == 'klarna') && $payable) {
                   $meta['directpay'] = 'true';
                   $meta['status_billing'] = 'pending';
                   $meta['suppressEmail'] = 'true';
            }

            // Add payment and budget info to meta (needed for email notification)
            if ($payservice) {
                $meta['paymentMethod'] = $payservice;
            }
            if ($budgetpayment) {
                $meta['budgetpayment'] = 'true';
            }
            $meta['hasBudget'] = $byproducts['hasBudget'] ? 'true' : 'false';
            if (array_key_exists('budget', $byproducts)) {
                $meta['budget'] = $byproducts['budget'];
            }
            if (array_key_exists('newbudget', $byproducts)) {
                $meta['newbudget'] = $byproducts['newbudget'];
            }

            $booking = $bookingService->createSingle($user, $square, $quantityParam, $byproducts['dateStart'], $byproducts['dateEnd'], $bills, array_merge($meta, [
                'price' => $byproducts['courtPrice'], // Pass the correct price to the booking service
                'guestPlayer' => $guestPlayerCheckbox == 1 ? '1' : '0', // Pass guest player status as string
            ]));

            if (($payservice == 'paypal' || $payservice == 'stripe' || $payservice == 'klarna') && $payable) {
            # payment checkout
                   $booking->setMeta('paymentMethod', $payservice);
                   $booking->setMeta('hasBudget', $byproducts['hasBudget']);
                   if(array_key_exists('newbudget', $byproducts)) {
                       $booking->setMeta('newbudget', $byproducts['newbudget']);
                   }
                   if(array_key_exists('budget', $byproducts)) {
                       $booking->setMeta('budget', $byproducts['budget']);
                   }
                   $bookingManager->save($booking);

                   $projectShort = $this->option('client.name.short');
                   $userName = $user->getMeta('firstname') . ' ' . $user->getMeta('lastname');
                   $courtNumber = $square->get('name');
                   $booktime = $dateStartParam . ' - ' . ' um '. $timeStartParam . ' - ' . $timeEndParam . ' Uhr ';
                   $locale = $this->config('i18n.locale');

                   $description = $projectShort.' booking-'.$booking->get('bid').' for '.$userName. ' on '.$this->option('subject.square.type').' - '.$courtNumber. ' at '.$booktime;
                   if (isset($locale) && ($locale == 'de-DE' || $locale == 'de_DE')) {
                        $description = $projectShort.' Buchung-'.$booking->get('bid').' für '.$userName. ' auf '.$this->option('subject.square.type').' - '.$courtNumber. ' am '.$booktime;
                   }

                   return $this->createPaymentAndRedirect($payservice, $booking, $user, $total, $description);
            } else {
                # no paymentservice
               
                # redefine user budget
                if ($budgetpayment) {
                    $userManager = $serviceManager->get('User\Manager\UserManager');
                    $user->setMeta('budget', $newbudget);
                    $userManager->save($user);
                    $booking->setMeta('budget', $budget);
                    $booking->setMeta('newbudget', $newbudget);
                    # set booking to paid
                    $booking->set('status_billing', 'paid');
                    $notes = $notes . " payment with user budget | budget: " . $budget . " -> " . $newbudget;
                    $booking->setMeta('notes', $notes);
                    $bookingManager->save($booking);
                }
                
                if ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                    $doorCode = $booking->getMeta('doorCode');
                    $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');
                    if ($squareControlService->createDoorCode($booking->need('bid'), $doorCode) == true) {
                        $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %s has been booked! The doorcode is: %s'),
                            $this->option('subject.square.type'), $doorCode));
                    } else {
                        $this->flashMessenger()->addErrorMessage(sprintf($this->t('Your %s has been booked! But the doorcode could not be send. Please contact admin by phone - %s'),
                            $this->option('subject.square.type'), $this->option('client.contact.phone')));
                    }
                }
                else{
                    $this->flashMessenger()->addSuccessMessage(sprintf($this->t('%sCongratulations:%s Your %s has been booked!'),
                        '<b>', '</b>',$this->option('subject.square.type')));
                }

                if ($guestPlayerCheckbox == 1 && !$budgetpayment) {
                    $paypalEmail = $this->config('paypalEmail') ?: 'payment@your-domain.com';
                    $this->flashMessenger()->addInfoMessage(
                        sprintf($this->t('Please pay the booking amount before the game via PayPal Friends & Family to %s or use the money letterbox at the office. Another option is instant bank transfer to our bank account.'), $paypalEmail)
                    );
                }

                if ($this->config('tmpBookingAt') != null) {
                    $this->flashMessenger()->addSuccessMessage(sprintf($this->t('%sPayment and admittance temporarily at %s!%s'),
                        '<b>', $this->config('tmpBookingAt'), '</b>'));
                }

                return $this->redirectBack()->toOrigin();
            }                
          }               
        }

       // Generate CSRF token and store in session
       $session = new \Zend\Session\Container('csrf');
       $session->token = bin2hex(random_bytes(32));
       $byproducts['csrfToken'] = $session->token;

       return $this->ajaxViewModel($byproducts);
    }

    public function cancellationAction()
    {
        $bid = $this->params()->fromQuery('bid');

        if (! (is_numeric($bid) && $bid > 0)) {
            throw new RuntimeException('This booking does not exist');
        }

        $serviceManager = $this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');

        $booking = $bookingManager->get($bid);

        $cancellable = $squareValidator->isCancellable($booking);

        if (! $cancellable) {
            throw new RuntimeException('This booking cannot be cancelled anymore online.');
        }

        $origin = $this->redirectBack()->getOriginAsUrl();

        /* Check cancellation confirmation */

        $confirmed = $this->params()->fromPost('confirmed', $this->params()->fromQuery('confirmed'));

        if ($confirmed == 'true') {

            $bookingService = $serviceManager->get('Booking\Service\BookingService');

            $userManager = $serviceManager->get('User\Manager\UserManager');
            $user = $userManager->get($booking->get('uid'));

            $bookingService->cancelSingle($booking);

            # redefine user budget if status paid
            $refundTotal = $bookingService->refundBudget($booking);
            if ($refundTotal > 0) {
                $this->sendCancellationEmail($booking, $user, $refundTotal);
            }

            $this->flashMessenger()->addErrorMessage(sprintf($this->t('Your booking has been %scancelled%s.'),
                '<b>', '</b>'));

            return $this->redirectBack()->toOrigin();
        }

        return $this->ajaxViewModel(array(
            'bid' => $bid,
            'origin' => $origin,
        ));
    }

    public function sendCancellationEmail($booking, $user, $total)
    {
        try {
            $serviceManager = $this->getServiceLocator();
            $squareManager = $serviceManager->get('Square\Manager\SquareManager');
            $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
            $square = $squareManager->get($booking->need('sid'));

            $squareName = $square->need('name');
            $formattedDate = '[Datum nicht verfügbar]';
            $formattedTimeStart = '[Startzeit nicht verfügbar]';
            $formattedTimeEnd = '[Endzeit nicht verfügbar]';

            // Reservierungsdaten für Datum/Zeit
            $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);
            if (!empty($reservations)) {
                $reservation = current($reservations);
                if ($reservation->get('date')) {
                    $formattedDate = (new \DateTime($reservation->need('date')))->format('d.m.Y');
                }
                if ($reservation->get('time_start')) {
                    $formattedTimeStart = substr($reservation->get('time_start'), 0, 5);
                }
                if ($reservation->get('time_end')) {
                    $formattedTimeEnd = substr($reservation->get('time_end'), 0, 5);
                }
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

            $subject = sprintf($this->t('Your booking for %s has been cancelled'), $squareName);

            // Strukturierte Buchungsdetails
            $buchungsDetails = sprintf(
                $this->t("Cancelled booking details:\n\n- Court: %s\n- Date: %s\n- Time: %s - %s\n- Booking ID: %s"),
                $squareName,
                $formattedDate,
                $formattedTimeStart,
                $formattedTimeEnd,
                $booking->need('bid')
            );

            // Budget-Rückerstattung
            $refundInfo = '';
            if ($total > 0) {
                $refundAmount = number_format($total / 100, 2, ',', '.');
                $refundInfo = "\n\n" . sprintf($this->t('A refund of %s EUR has been credited to your account budget.'), $refundAmount);
            }

            // Kontaktinfo
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');

            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('This message was sent automatically. If you have questions, please contact our support team');

                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf($this->t(' at %s'), $contactEmail);
                }

                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= $this->t(' or');
                    }
                    $contactInfo .= sprintf($this->t(' on our website %s'), $clientWebsite);
                }
                $contactInfo .= '.';
            }

            $emailText = sprintf(
                "%s,\n\n%s\n\n%s%s",
                $anrede,
                $this->t('your booking has been cancelled.'),
                $buchungsDetails,
                $refundInfo
            );

            // Backend MailService verwenden (mit automatischer Signatur)
            if ($serviceManager->has('Backend\Service\MailService')) {
                $backendMailService = $serviceManager->get('Backend\Service\MailService');
                $backendMailService->sendCustomEmail(
                    $subject,
                    $emailText,
                    $user->need('email'),
                    $user->need('alias'),
                    [],
                    $contactInfo,
                    false
                );
            } else {
                // Fallback auf sendPlain
                $mailService = $serviceManager->get('Base\Service\MailService');
                if (!$mailService) {
                    return;
                }

                $fromAddress = $this->config('mail.address');
                $fromName = $this->option('client.name.short') . ' ' . $this->option('service.name.full');
                $replyToAddress = $this->option('client.contact.email');
                $replyToName = $this->option('client.name.full');

                $mailService->sendPlain(
                    $fromAddress,
                    $fromName,
                    $replyToAddress,
                    $replyToName,
                    $user->need('email'),
                    $user->need('alias'),
                    $subject,
                    $emailText,
                    []
                );
            }

            // Record that we sent a notification
            $booking->setMeta('cancellation_notification_sent', date('Y-m-d H:i:s'));

        } catch (\Exception $e) {
            // Silently continue — email failure should not break cancellation
        }
    }

    public function sendPaymentFailedEmail($booking, $user)
    {
        try {
            $serviceManager = $this->getServiceLocator();
            $squareManager = $serviceManager->get('Square\Manager\SquareManager');
            $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
            $square = $squareManager->get($booking->need('sid'));

            $reservations = $reservationManager->getBy(['bid' => $booking->need('bid')], 'date ASC', 1);
            $reservation = current($reservations);

            if (!$reservation) {
                return;
            }

            $formattedDate = (new \DateTime($reservation->need('date')))->format('d.m.Y');
            $formattedTimeStart = substr($reservation->need('time_start'), 0, 5);
            $formattedTimeEnd = substr($reservation->need('time_end'), 0, 5);
            $squareName = $square->need('name');

            // Personalisierte Anrede (gleicher Stil wie Backend-Stornierungsmail)
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

            $subject = $this->t('Payment failed for your booking');

            // Strukturierte Buchungsdetails
            $buchungsDetails = sprintf(
                $this->t("Booking details:\n\n- Court: %s\n- Date: %s\n- Time: %s - %s\n- Booking ID: %s"),
                $squareName,
                $formattedDate,
                $formattedTimeStart,
                $formattedTimeEnd,
                $booking->get('bid')
            );

            // Kontaktinfo
            $contactInfo = '';
            $contactEmail = $this->option('client.website.contact', '');
            $clientWebsite = $this->option('client.website', '');

            if (!empty($contactEmail) || !empty($clientWebsite)) {
                $contactInfo = $this->t('This message was sent automatically. If you have questions, please contact our support team');

                if (!empty($contactEmail)) {
                    $contactEmail = str_replace('mailto:', '', $contactEmail);
                    $contactInfo .= sprintf($this->t(' at %s'), $contactEmail);
                }

                if (!empty($clientWebsite)) {
                    if (!empty($contactEmail)) {
                        $contactInfo .= $this->t(' or');
                    }
                    $contactInfo .= sprintf($this->t(' on our website %s'), $clientWebsite);
                }
                $contactInfo .= '.';
            }

            $emailText = sprintf(
                "%s,\n\n%s\n\n%s\n\n%s",
                $anrede,
                $this->t('unfortunately the payment for your booking could not be completed.'),
                $buchungsDetails,
                $this->t('The booking has been cancelled. Please try again or contact us if you have any questions.')
            );

            // Backend MailService verwenden (mit automatischer Signatur)
            if ($serviceManager->has('Backend\Service\MailService')) {
                $backendMailService = $serviceManager->get('Backend\Service\MailService');
                $backendMailService->sendCustomEmail(
                    $subject,
                    $emailText,
                    $user->need('email'),
                    $user->need('alias'),
                    [],
                    $contactInfo,
                    false
                );
            } else {
                // Fallback auf sendPlain
                $mailService = $serviceManager->get('Base\Service\MailService');
                if (!$mailService) {
                    return;
                }

                $fromAddress = $this->config('mail.address');
                $fromName = $this->option('client.name.short') . ' ' . $this->option('service.name.full');
                $replyToAddress = $this->option('client.contact.email');
                $replyToName = $this->option('client.name.full');

                $mailService->sendPlain(
                    $fromAddress,
                    $fromName,
                    $replyToAddress,
                    $replyToName,
                    $user->need('email'),
                    $user->need('alias'),
                    $subject,
                    $emailText,
                    []
                );
            }
        } catch (\Exception $e) {
            error_log('sendPaymentFailedEmail error: ' . $e->getMessage());
        }
    }

    public function confirmAction()
    {

        $token = $this->getServiceLocator()->get('payum.security.http_request_verifier')->verify($this);
        $gateway = $this->getServiceLocator()->get('payum')->getGateway($token->getGatewayName());
        $tokenStorage = $this->getServiceLocator()->get('payum.options')->getTokenStorage();
        $gateway->execute($status = new GetHumanStatus($token));

        $payment = $status->getFirstModel();

        // syslog(LOG_EMERG, $payment['status']);
        // syslog(LOG_EMERG, json_encode($payment));

        if (($payment['status'] == "requires_action" && !(array_key_exists('error', (array)$payment)))) {
            
          // syslog(LOG_EMERG, "confirm success");
          $payment['doneAction'] = $token->getTargetUrl();

           try {
               // syslog(LOG_EMERG, "executing confirm");

               $gateway->execute(new Confirm($payment));

               // syslog(LOG_EMERG, $payment['status']);
               // syslog(LOG_EMERG, json_encode($payment));

           } catch (ReplyInterface $reply) {
               if ($reply instanceof HttpRedirect) {
                   return $this->redirect()->toUrl($reply->getUrl());
               }
               if ($reply instanceof HttpResponse) {
                  $this->getResponse()->setContent($reply->getContent());
                  $response = new Response();
                  $response->setStatusCode(200);
                  $response->setContent($reply->getContent());
                  return $response;
               }
            throw new \LogicException('Unsupported reply', null, $reply);
            }

        }
   
        if ($payment['status'] != "requires_action" || array_key_exists('error', (array)$payment)) {
           // syslog(LOG_EMERG, json_encode($payment)); 
           // syslog(LOG_EMERG, $payment['status']); 
           // syslog(LOG_EMERG, "confirm error");
           $doneAction = str_replace("confirm", "done", $token->getTargetUrl());

           $token->setTargetUrl($doneAction);
           $tokenStorage->update($token);
           return $this->redirect()->toUrl($doneAction);
        }

    }

    /**
     * Create Payum payment tokens and redirect to the payment gateway.
     *
     * @param string $payservice  'paypal', 'stripe', or 'klarna'
     * @param object $booking     Booking entity
     * @param object $user        User entity
     * @param int    $total       Amount in cents
     * @param string $description Payment description
     * @param string $doneRoute   Route suffix for done callback (e.g. '/square/booking/payment/done')
     * @return \Zend\Http\Response Redirect response to gateway
     */
    private function createPaymentAndRedirect($payservice, $booking, $user, $total, $description)
    {
        $basepath = $this->config('basepath');
        if (isset($basepath) && $basepath != '' && $basepath != ' ') {
            $basepath = '/' . $basepath;
        }
        $baseurl = $this->config('baseurl');
        $proxyurl = $this->config('proxyurl');
        $projectShort = $this->option('client.name.short');
        $locale = $this->config('i18n.locale');

        $storage = $this->getServiceLocator()->get('payum')->getStorage('Application\Model\PaymentDetails');
        $tokenStorage = $this->getServiceLocator()->get('payum.options')->getTokenStorage();
        $captureToken = null;
        $notifyToken = null;
        $model = $storage->create();

        $userName = $user->getMeta('firstname') . ' ' . $user->getMeta('lastname');
        $companyName = $this->option('client.name.full');

        if ($payservice == 'paypal') {
            $model['PAYMENTREQUEST_0_CURRENCYCODE'] = 'EUR';
            $model['PAYMENTREQUEST_0_AMT'] = $total / 100;
            $model['PAYMENTREQUEST_0_BID'] = $booking->get('bid');
            $model['PAYMENTREQUEST_0_DESC'] = $description;
            $model['PAYMENTREQUEST_0_EMAIL'] = $user->get('email');
            $storage->update($model);
            $captureToken = $this->getServiceLocator()->get('payum.security.token_factory')->createCaptureToken(
                'paypal_ec', $model, $proxyurl . $basepath . '/square/booking/payment/done');
        }

        if ($payservice == 'stripe') {
            $model["payment_method_types"] = $this->config('stripePaymentMethods');
            $model["amount"] = $total;
            $model["currency"] = 'EUR';
            $model["description"] = $description;
            $model["receipt_email"] = $user->get('email');
            $model["metadata"] = array(
                'bid' => $booking->get('bid'),
                'productName' => $this->option('subject.type'),
                'locale' => $locale,
                'instance' => $basepath,
                'projectShort' => $projectShort,
                'userName' => $userName,
                'companyName' => $companyName,
                'stripeDefaultPaymentMethod' => $this->config('stripeDefaultPaymentMethod'),
                'stripeAutoConfirm' => var_export($this->config('stripeAutoConfirm'), true),
                'stripePaymentRequest' => var_export($this->config('stripePaymentRequest'), true),
            );
            $storage->update($model);
            $captureToken = $this->getServiceLocator()->get('payum.security.token_factory')->createCaptureToken(
                'stripe', $model, $proxyurl . $basepath . '/square/booking/payment/confirm');
        }

        if ($payservice == 'klarna') {
            $model['purchase_country'] = 'DE';
            $model['purchase_currency'] = 'EUR';
            $model['locale'] = 'de-DE';
            $storage->update($model);
            $captureToken = $this->getServiceLocator()->get('payum.security.token_factory')->createAuthorizeToken(
                'klarna_checkout', $model, $proxyurl . $basepath . '/square/booking/payment/done');
            $notifyToken = $this->getServiceLocator()->get('payum.security.token_factory')->createNotifyToken(
                'klarna_checkout', $model);
        }

        $targetUrl = str_replace($baseurl, $proxyurl, $captureToken->getTargetUrl());
        $captureToken->setTargetUrl($targetUrl);
        $tokenStorage->update($captureToken);

        if ($payservice == 'klarna') {
            $model['merchant'] = array(
                'terms_uri' => $this->config('klarnaTermsUri', 'http://example.com/terms'),
                'checkout_uri' => $captureToken->getTargetUrl(),
                'confirmation_uri' => $captureToken->getTargetUrl(),
                'push_uri' => $notifyToken->getTargetUrl(),
            );
            $model['cart'] = array(
                'items' => array(
                    array(
                        'reference' => $booking->get('bid'),
                        'name' => $description,
                        'quantity' => 1,
                        'unit_price' => $total,
                    )
                )
            );
            $storage->update($model);
        }

        return $this->redirect()->toUrl($captureToken->getTargetUrl());
    }

    public function payAction()
    {
        $serviceManager = $this->getServiceLocator();

        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $user = $userSessionManager->getSessionUser();

        if (! $user) {
            $bid = $this->params()->fromRoute('bid');
            $this->redirectBack()->setOrigin('square/booking/payment_pay', ['bid' => $bid]);
            return $this->redirect()->toRoute('user/login');
        }

        $bid = $this->params()->fromRoute('bid');

        if (! (is_numeric($bid) && $bid > 0)) {
            throw new RuntimeException('This booking does not exist');
        }

        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $bookingBillManager = $serviceManager->get('Booking\Manager\Booking\BillManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');

        $booking = $bookingManager->get($bid);

        if ($booking->get('uid') != $user->get('uid')) {
            throw new RuntimeException('You have no permission for this');
        }

        if ($booking->get('status') == 'cancelled') {
            throw new RuntimeException('This booking has been cancelled');
        }

        if ($booking->get('status_billing') != 'pending') {
            throw new RuntimeException('This booking has already been paid');
        }

        $bills = $bookingBillManager->getBy(array('bid' => $bid), 'bbid ASC');
        $total = 0;
        foreach ($bills as $bill) {
            $total += $bill->get('price');
        }

        if ($total <= 0) {
            throw new RuntimeException('No amount to pay');
        }

        $payservice = $this->params()->fromPost('paymentservice');

        if (! in_array($payservice, ['paypal', 'stripe', 'klarna'])) {
            $this->flashMessenger()->addErrorMessage($this->t('Please select a payment method'));
            return $this->redirect()->toRoute('user/bookings/bills', ['bid' => $bid]);
        }

        $this->redirectBack()->setOrigin('user/bookings');

        $booking->setMeta('payLater', 'true');
        $booking->setMeta('paymentMethod', $payservice);
        $booking->setMeta('directpay', 'true');
        $bookingManager->save($booking);

        $square = $squareManager->get($booking->need('sid'));

        $projectShort = $this->option('client.name.short');
        $locale = $this->config('i18n.locale');
        $description = $projectShort . ' booking-' . $booking->get('bid');
        if (isset($locale) && ($locale == 'de-DE' || $locale == 'de_DE')) {
            $description = $projectShort . ' Buchung-' . $booking->get('bid');
        }

        return $this->createPaymentAndRedirect($payservice, $booking, $user, $total, $description);
    }

    public function doneAction()
    {
        // syslog(LOG_EMERG, 'doneAction');

        $serviceManager = $this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');

        $bookingService = $serviceManager->get('Booking\Service\BookingService');

        $token = $serviceManager->get('payum.security.http_request_verifier')->verify($this);

        $gateway = $serviceManager->get('payum')->getGateway($token->getGatewayName());

        $gateway->execute($status = new GetHumanStatus($token));

        $payment = $status->getFirstModel();

        // syslog(LOG_EMERG, json_encode($status));
        // syslog(LOG_EMERG, json_encode($payment));

        $origin = $this->redirectBack()->getOriginAsUrl();

        $bid = -1;
        $paymentNotes = '';
#paypal
        if ($token->getGatewayName() == 'paypal_ec') {
            $bid = $payment['PAYMENTREQUEST_0_BID'];
            $paymentNotes = ' direct pay with paypal - ';
        }
#paypal
#stripe
        if ($token->getGatewayName() == 'stripe') {
            $bid = $payment['metadata']['bid'];
            $paymentNotes = ' direct pay with stripe ' . $payment['charges']['data'][0]['payment_method_details']['type'] . ' - ';
        }
#stripe
#klarna
        if ($token->getGatewayName() == 'klarna') {
            $bid = $payment['items']['reference'];
            $paymentNotes = ' direct pay with klarna - ';
        }
#klarna

        if (! (is_numeric($bid) && $bid > 0)) {
            throw new RuntimeException('This booking does not exist');
        }

        $booking = $bookingManager->get($bid);
        $notes = $booking->getMeta('notes');

        $notes = $notes . $paymentNotes;

        $square = $squareManager->get($booking->need('sid'));

        $paymentStatus = isset($payment['status']) ? $payment['status'] : '';

        // For PayPal: isPending() alone is not reliable — sandbox returns "Pending" even on abort.
        // Only treat PayPal pending as success if PAYMENTREQUEST_0_PAYMENTSTATUS confirms "Completed".
        $paypalCompleted = ($token->getGatewayName() == 'paypal_ec'
            && isset($payment['PAYMENTREQUEST_0_PAYMENTSTATUS'])
            && $payment['PAYMENTREQUEST_0_PAYMENTSTATUS'] == 'Completed');

        $isPaypalPending = ($status->isPending() && $token->getGatewayName() == 'paypal_ec');
        $isNonPaypalPending = ($status->isPending() && $token->getGatewayName() != 'paypal_ec');

        // PayPal pending only counts as success if PAYMENTREQUEST_0_PAYMENTSTATUS == 'Completed'
        $isSuccess = $status->isCaptured()
            || $status->isAuthorized()
            || $isNonPaypalPending
            || ($isPaypalPending && $paypalCompleted)
            || ($status->isUnknown() && $paymentStatus == 'processing')
            || $status->getValue() === "success"
            || $paymentStatus === "succeeded";

        if ($isSuccess) {

            // syslog(LOG_EMERG, 'doneAction - success');

            if ($booking->getMeta('directpay_pending') != 'true') {
                if ($booking->getMeta('payLater') == 'true') {
                    $this->flashMessenger()->addSuccessMessage(sprintf($this->t('%sPayment successful!%s'),
                        '<b>', '</b>'));
                } elseif ($this->config('genDoorCode') != null && $this->config('genDoorCode') == true && $square->getMeta('square_control') == true) {
                   $doorCode = $booking->getMeta('doorCode');
                   $squareControlService = $serviceManager->get('SquareControl\Service\SquareControlService');
                   if ($squareControlService->createDoorCode($bid, $doorCode) == true) {
                       $this->flashMessenger()->addSuccessMessage(sprintf($this->t('Your %s has been booked! The doorcode is: %s'),
                           $this->option('subject.square.type'), $doorCode));
                   } else {
                       $this->flashMessenger()->addErrorMessage(sprintf($this->t('Your %s has been booked! But the doorcode could not be send. Please contact admin by phone - %s'),
                           $this->option('subject.square.type'), $this->option('client.contact.phone')));
                   }
                }
                else {
                    $this->flashMessenger()->addSuccessMessage(sprintf($this->t('%sCongratulations:%s Your %s has been booked!'),
                        '<b>', '</b>',$this->option('subject.square.type')));
                }
            }

            if(!$paypalCompleted && ($status->isPending() || ($status->isUnknown() && $paymentStatus == 'processing'))) {
                $booking->set('status_billing', 'pending');
                $booking->setMeta('directpay', 'false');
                $booking->setMeta('directpay_pending', 'true');
            }
            else {
                // syslog(LOG_EMERG, 'success paid');
                $booking->set('status_billing', 'paid');
                $booking->setMeta('directpay', 'true');
                $booking->setMeta('directpay_pending', 'false');
            }

            # redefine user budget
            if ($booking->getMeta('hasBudget') == 'true') {
                $userManager = $serviceManager->get('User\Manager\UserManager');
                $user = $userManager->get($booking->get('uid'));
                $user->setMeta('budget', $booking->getMeta('newbudget'));
                $userManager->save($user);
                # set booking to paid
                $notes = $notes . " payment with user budget (budget: " . $booking->getMeta('budget') . " -> " . $booking->getMeta('newbudget') . ") | ";
            }

            if ($booking->getMeta('payLater') == 'true') {
                $booking->setMeta('payLater', null);
                $notes = $notes . "(payLater) ";
            }

            $notes = $notes . " paymentMethod: " . $booking->getMeta('paymentMethod') . " | payment_status: " . $status->getValue() . ' ' . $paymentStatus;
            $booking->setMeta('notes', $notes);
            $bookingService->updatePaymentSingle($booking);

            // Send confirmation email now that payment is confirmed
            if ($booking->getMeta('suppressEmail') == 'true') {
                $booking->setMeta('suppressEmail', null);
                $bookingManager->save($booking);
                $bookingService->getEventManager()->trigger('create.single', $booking);
            }
	    }
	    else
        {
            // syslog(LOG_EMERG, 'doneAction - error');

            if ($booking->getMeta('payLater') == 'true') {
                if(isset($payment['error']['message'])) {
                    $this->flashMessenger()->addErrorMessage(htmlspecialchars($payment['error']['message'], ENT_QUOTES, 'UTF-8'));
                }
                $this->flashMessenger()->addErrorMessage(sprintf($this->t('%sPayment failed. Please try again.%s'),
                    '<b>', '</b>'));
                $booking->setMeta('payLater', null);
                $booking->setMeta('directpay', 'false');
                $bookingManager->save($booking);
            } else {
                if ($booking->getMeta('directpay_pending') != 'true') {
                    if(isset($payment['error']['message'])) {
                        $this->flashMessenger()->addErrorMessage(htmlspecialchars($payment['error']['message'], ENT_QUOTES, 'UTF-8'));
                    }
                    $this->flashMessenger()->addErrorMessage(sprintf($this->t('%sError during payment: Your booking has been cancelled.%s'),
                        '<b>', '</b>'));
                }
                // Suppress the automatic cancel email — we send a dedicated payment-failed email instead
                $booking->setMeta('suppressCancelEmail', 'true');
                $bookingManager->save($booking);

                $bookingService = $serviceManager->get('Booking\Service\BookingService');
                $bookingService->cancelSingle($booking);

                $userManager = $serviceManager->get('User\Manager\UserManager');
                $user = $userManager->get($booking->get('uid'));
                $this->sendPaymentFailedEmail($booking, $user);
            }
        }

        return $this->redirectBack()->toOrigin();

    }
}
