<?php

namespace Booking\Service;

use Base\Manager\ConfigManager;
use Base\Manager\OptionManager;
use Base\Service\AuditService;
use Backend\Service\MailService as BackendMailService;
use Booking\Manager\BookingManager;
use Booking\Manager\Booking\BillManager;
use Booking\Manager\ReservationManager;
use Payum\Core\Bridge\Spl\ArrayObject as PayumArrayObject;
use Payum\Paypal\ExpressCheckout\Nvp\Api as PaypalApi;
use Payum\Paypal\ExpressCheckout\Nvp\Request\Api\GetTransactionDetails;
use Square\Manager\SquareManager;
use User\Manager\UserManager;
use Zend\Db\Adapter\Adapter;

/**
 * Resolves payments that could not be decided during the browser round-trip.
 *
 * Two independent jobs, both driven by scripts/payments.php:
 *
 *  - reconcile(): PayPal accepted money but holds it for review
 *    (PAYMENTSTATUS=Pending, PENDINGREASON=paymentreview/echeck/...). The booking was kept
 *    with status_billing='pending' + meta directpay_pending='true'. Here we ask PayPal via
 *    GetTransactionDetails what became of the transaction and settle the booking.
 *
 *  - remindUnpaid(): the member left the gateway without paying (closed the browser), so
 *    doneAction() never ran. Those bookings are deleted by the remove_unpaid_bookings MySQL
 *    event; before that happens the member gets one reminder with a payment link.
 *
 * Everything is fire-and-forget per booking: a failing API call or mail must never abort
 * the whole run.
 */
class PaymentReconciliationService
{

    /** Default: alert administration when a review is unresolved for this many days */
    const DEFAULT_REVIEW_ALERT_DAYS = 10;

    /** Default: send the unpaid reminder once the booking is this many minutes old */
    const DEFAULT_REMINDER_AFTER_MINUTES = 10;

    /** Default: minutes after creation at which the cleanup event deletes an unpaid booking */
    const DEFAULT_UNPAID_GRACE_MINUTES = 30;

    protected $adapter;
    protected $bookingManager;
    protected $billManager;
    protected $reservationManager;
    protected $squareManager;
    protected $userManager;
    protected $bookingService;
    protected $auditService;
    protected $mailService;
    protected $optionManager;
    protected $configManager;
    protected $payum;

    public function __construct(
        Adapter $adapter,
        BookingManager $bookingManager,
        BillManager $billManager,
        ReservationManager $reservationManager,
        SquareManager $squareManager,
        UserManager $userManager,
        BookingService $bookingService,
        AuditService $auditService,
        BackendMailService $mailService,
        OptionManager $optionManager,
        ConfigManager $configManager,
        $payum)
    {
        $this->adapter            = $adapter;
        $this->bookingManager     = $bookingManager;
        $this->billManager        = $billManager;
        $this->reservationManager = $reservationManager;
        $this->squareManager      = $squareManager;
        $this->userManager        = $userManager;
        $this->bookingService     = $bookingService;
        $this->auditService       = $auditService;
        $this->mailService        = $mailService;
        $this->optionManager      = $optionManager;
        $this->configManager      = $configManager;
        $this->payum              = $payum;
    }

    /* ------------------------------------------------------------------ */
    /* Shared PayPal status classification                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Maps a PayPal PAYMENTSTATUS/PENDINGREASON pair to an actionable outcome.
     *
     * Single source of truth — used both by the browser round-trip
     * (Square\Controller\BookingController::evaluatePaypalOutcome) and by reconcile().
     *
     * @param string|null $paymentStatus PayPal PAYMENTSTATUS
     * @param string|null $pendingReason PayPal PENDINGREASON
     * @return string 'paid' | 'review' | 'failed' | 'unknown'
     */
    public static function classifyPaypalStatus($paymentStatus, $pendingReason = null)
    {
        $paymentStatus = (string) $paymentStatus;
        $pendingReason = strtolower((string) $pendingReason);

        if (in_array($paymentStatus, array(PaypalApi::PAYMENTSTATUS_COMPLETED, PaypalApi::PAYMENTSTATUS_PROCESSED,
                PaypalApi::PAYMENTSTATUS_COMPLETED_FUNDS_HELD), true)) {
            return 'paid';
        }

        if (in_array($paymentStatus, array(PaypalApi::PAYMENTSTATUS_FAILED, PaypalApi::PAYMENTSTATUS_DENIED,
                PaypalApi::PAYMENTSTATUS_EXPIRED, PaypalApi::PAYMENTSTATUS_REVERSED,
                PaypalApi::PAYMENTSTATUS_CANCELED_REVERSAL, PaypalApi::PAYMENTSTATUS_VOIDED), true)) {
            return 'failed';
        }

        if (in_array($paymentStatus, array(PaypalApi::PAYMENTSTATUS_PENDING, PaypalApi::PAYMENTSTATUS_IN_PROGRESS), true)) {
            /* 'authorization' only means the capture is still outstanding — that is a success path. */
            return ($pendingReason === PaypalApi::PENDINGREASON_AUTHORIZATION) ? 'paid' : 'review';
        }

        return 'unknown';
    }

    /**
     * Human-readable German explanation of a PayPal PENDINGREASON.
     *
     * @param string|null $reason
     * @return string
     */
    public static function describePendingReason($reason)
    {
        $reason = strtolower(trim((string) $reason));

        $map = array(
            'paymentreview'    => 'PayPal-Sicherheitsprüfung (paymentreview)',
            'regulatoryreview' => 'regulatorische Prüfung durch PayPal (regulatoryreview)',
            'echeck'           => 'Zahlung per eCheck, wartet auf Bankeinzug (echeck)',
            'verify'           => 'PayPal-Konto des Zahlers ist nicht verifiziert (verify)',
            'multi-currency'   => 'Fremdwährung, muss manuell akzeptiert werden (multi-currency)',
            'intl'             => 'Auslandszahlung, muss manuell akzeptiert werden (intl)',
            'unilateral'       => 'Empfänger-E-Mail nicht bei PayPal registriert (unilateral)',
            'order'            => 'Bestellung ohne Zahlungseinzug (order)',
            'authorization'    => 'Zahlung autorisiert, aber noch nicht eingezogen (authorization)',
            'upgrade'          => 'Kontoupgrade des Empfängers erforderlich (upgrade)',
            'address'          => 'Adressprüfung des Zahlers offen (address)',
            'other'            => 'sonstiger Grund (other)',
            'none'             => 'kein Grund angegeben',
            ''                 => 'unbekannt',
        );

        return isset($map[$reason]) ? $map[$reason] : $reason;
    }

    /* ------------------------------------------------------------------ */
    /* Job 1: resolve PayPal reviews                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Asks PayPal about every booking whose payment is under review and settles it.
     *
     * @param boolean $dryRun no writes, no mails — only report what would happen
     * @return array report: counts + per-booking results
     */
    public function reconcile($dryRun = false)
    {
        $report = array('checked' => 0, 'paid' => 0, 'cancelled' => 0, 'kept_open' => 0,
                        'still_review' => 0, 'errors' => 0, 'items' => array());

        foreach ($this->findBookingsUnderReview() as $row) {
            $bid = (int) $row['bid'];
            $report['checked']++;

            try {
                $booking = $this->bookingManager->get($bid);
            } catch (\Exception $e) {
                $report['errors']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'error', 'message' => 'booking not loadable: ' . $e->getMessage());
                continue;
            }

            $transactionId = $booking->getMeta('paypalTransactionId');

            if (! $transactionId) {
                /* Non-PayPal async payment (Stripe "processing") or a review recorded before
                 * transaction ids were persisted — nothing to query, leave it to the admin. */
                $report['kept_open']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'no-transaction-id');
                continue;
            }

            try {
                $details = $this->fetchTransactionDetails($transactionId);
            } catch (\Exception $e) {
                error_log('PaymentReconciliation: GetTransactionDetails failed for booking #' . $bid . ': ' . $e->getMessage());
                $report['errors']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'error', 'message' => $e->getMessage());
                continue;
            }

            $outcome = self::classifyPaypalStatus($details['paymentStatus'], $details['pendingReason']);

            $item = array('bid' => $bid, 'transactionId' => $transactionId,
                          'paypalStatus' => $details['paymentStatus'], 'pendingReason' => $details['pendingReason'],
                          'result' => $outcome);

            if ($outcome === 'paid') {
                if (! $dryRun) {
                    $this->settleAsPaid($booking, $details);
                }
                $report['paid']++;
            } elseif ($outcome === 'failed') {
                $cancelled = $dryRun ? $this->hasFutureReservation($booking) : $this->settleAsFailed($booking, $details);
                $item['result'] = $cancelled ? 'cancelled' : 'kept_open';
                $cancelled ? $report['cancelled']++ : $report['kept_open']++;
            } else {
                /* Still under review (or PayPal returned something we do not classify). */
                $report['still_review']++;
                $item['reviewDays'] = $this->reviewAgeInDays($booking);

                if (! $dryRun && $item['reviewDays'] !== null
                        && $item['reviewDays'] >= $this->reviewAlertDays()
                        && $booking->getMeta('paypalReviewAlertSent') != 'true') {
                    $this->alertLongRunningReview($booking, $details, $item['reviewDays']);
                    $booking->setMeta('paypalReviewAlertSent', 'true');
                    $this->bookingManager->save($booking);
                    $item['alerted'] = true;
                }
            }

            $report['items'][] = $item;
        }

        return $report;
    }

    /**
     * Bookings whose payment is under review: pending, not cancelled, directpay_pending='true'.
     *
     * @return array rows with 'bid'
     */
    protected function findBookingsUnderReview()
    {
        return $this->fetchAll(
            "SELECT b.bid FROM bs_bookings b "
            . "JOIN bs_bookings_meta dpp ON dpp.bid = b.bid AND dpp.`key` = 'directpay_pending' AND dpp.value = 'true' "
            . "WHERE b.status_billing = 'pending' AND b.status <> 'cancelled' "
            . "ORDER BY b.bid ASC LIMIT 500"
        );
    }

    /**
     * GetTransactionDetails via the configured paypal_ec gateway.
     *
     * @param string $transactionId
     * @return array ['paymentStatus' => string, 'pendingReason' => string, 'amount' => string]
     * @throws \Exception when the gateway is unavailable or PayPal answers with an error
     */
    protected function fetchTransactionDetails($transactionId)
    {
        $gateway = $this->payum->getGateway('paypal_ec');

        $model = new PayumArrayObject(array('PAYMENTREQUEST_0_TRANSACTIONID' => $transactionId));
        $gateway->execute(new GetTransactionDetails($model, 0));

        $status = isset($model['PAYMENTREQUEST_0_PAYMENTSTATUS']) ? (string) $model['PAYMENTREQUEST_0_PAYMENTSTATUS'] : '';

        if ($status === '') {
            $error = isset($model['L_LONGMESSAGE0']) ? (string) $model['L_LONGMESSAGE0'] : 'no PAYMENTSTATUS in response';
            throw new \RuntimeException($error);
        }

        return array(
            'paymentStatus' => $status,
            'pendingReason' => isset($model['PAYMENTREQUEST_0_PENDINGREASON']) ? (string) $model['PAYMENTREQUEST_0_PENDINGREASON'] : '',
            'amount'        => isset($model['PAYMENTREQUEST_0_AMT']) ? (string) $model['PAYMENTREQUEST_0_AMT'] : '',
        );
    }

    /**
     * PayPal released the money: booking becomes paid and the member is informed.
     *
     * @param object $booking
     * @param array $details
     */
    protected function settleAsPaid($booking, array $details)
    {
        $bid = $booking->get('bid');

        $booking->set('status_billing', 'paid');
        $booking->setMeta('directpay', 'true');
        $booking->setMeta('directpay_pending', 'false');
        $booking->setMeta('paypalReviewResolved', date('Y-m-d H:i:s'));
        $booking->setMeta('paypalReviewAlertSent', null);
        $booking->setMeta('notes', $booking->getMeta('notes')
            . ' | reconciled: ' . $details['paymentStatus'] . ' (' . date('Y-m-d H:i') . ')');

        $this->bookingManager->save($booking);

        $userName = $this->userName($booking);

        $this->audit('payment_reconciled',
            sprintf('PayPal-Pruefung abgeschlossen: Buchung #%s bezahlt (%s), %s', $bid, $details['paymentStatus'], $userName),
            $booking,
            array('payment_status' => $details['paymentStatus'], 'pendingReason' => $details['pendingReason'],
                  'paypalTransactionId' => $booking->getMeta('paypalTransactionId'), 'status_billing' => 'paid',
                  'user_name_full' => $userName));

        $this->mailUser($booking,
            'Zahlung bestätigt - Buchung #' . $bid,
            sprintf("%s\n\n%s\n\n%s",
                'die Prüfung durch PayPal ist abgeschlossen, Ihre Zahlung wurde freigegeben. Ihre Buchung ist damit vollständig bezahlt.',
                $this->bookingDetailsText($booking),
                'Vielen Dank für Ihre Geduld.'));
    }

    /**
     * PayPal refused the money after the review.
     *
     * Future reservation → cancel the booking and refund a budget share.
     * Past reservation    → keep it as an open bill and alert administration, so the money
     *                       can still be collected (the court was blocked and played).
     *
     * @param object $booking
     * @param array $details
     * @return boolean true when the booking was cancelled
     */
    protected function settleAsFailed($booking, array $details)
    {
        $bid = $booking->get('bid');
        $userName = $this->userName($booking);
        $hasFuture = $this->hasFutureReservation($booking);

        $booking->setMeta('directpay_pending', 'false');
        $booking->setMeta('paypalReviewResolved', date('Y-m-d H:i:s'));
        $booking->setMeta('paypalReviewFailed', $details['paymentStatus']);
        $booking->setMeta('notes', $booking->getMeta('notes')
            . ' | reconciled: ' . $details['paymentStatus'] . ' (' . date('Y-m-d H:i') . ')');
        $this->bookingManager->save($booking);

        $this->audit('payment_failed',
            sprintf('PayPal-Pruefung negativ: Buchung #%s, Status %s, %s', $bid, $details['paymentStatus'], $userName),
            $booking,
            array('payment_status' => $details['paymentStatus'], 'pendingReason' => $details['pendingReason'],
                  'paypalTransactionId' => $booking->getMeta('paypalTransactionId'),
                  'cancelled' => $hasFuture ? 'true' : 'false', 'user_name_full' => $userName));

        if ($hasFuture) {
            $this->refundDeductedBudget($booking);

            $booking->setMeta('suppressCancelEmail', 'true');
            $this->bookingManager->save($booking);
            $this->bookingService->cancelSingle($booking);

            $this->mailUser($booking,
                'Zahlung abgelehnt - Buchung #' . $bid . ' storniert',
                sprintf("%s\n\n%s\n\n%s",
                    'leider hat PayPal Ihre Zahlung nach der Prüfung nicht freigegeben (Status: ' . $details['paymentStatus'] . ').',
                    $this->bookingDetailsText($booking),
                    'Die Buchung wurde daher storniert. Sie können den Platz jederzeit neu buchen.'));

            $this->mailAdmin('PayPal-Zahlung abgelehnt - Buchung #' . $bid . ' storniert',
                sprintf("%s\n\n%s\n\n%s\n%s",
                    'PayPal hat die Zahlung für Buchung #' . $bid . ' nach der Prüfung abgelehnt (Status: ' . $details['paymentStatus'] . ').',
                    $this->bookingDetailsText($booking),
                    'Mitglied: ' . $userName,
                    'Die Buchung wurde automatisch storniert, ein etwaiger Budget-Anteil wurde zurückgebucht.'));

            return true;
        }

        $this->mailAdmin('PayPal-Zahlung abgelehnt - Buchung #' . $bid . ' bleibt offen',
            sprintf("%s\n\n%s\n\n%s\n%s\n%s",
                'PayPal hat die Zahlung für Buchung #' . $bid . ' nach der Prüfung abgelehnt (Status: ' . $details['paymentStatus'] . ').',
                $this->bookingDetailsText($booking),
                'Mitglied: ' . $userName,
                'PayPal-Transaktion: ' . ($booking->getMeta('paypalTransactionId') ?: '-'),
                'Der Termin liegt bereits in der Vergangenheit, die Buchung bleibt daher auf "Ausstehend" stehen. Bitte das Geld manuell einfordern.'));

        return false;
    }

    /**
     * A review that PayPal does not resolve needs a human after a while.
     *
     * @param object $booking
     * @param array $details
     * @param int $days
     */
    protected function alertLongRunningReview($booking, array $details, $days)
    {
        $this->mailAdmin('PayPal-Prüfung seit ' . $days . ' Tagen offen - Buchung #' . $booking->get('bid'),
            sprintf("%s\n\n%s\n\n%s\n%s\n%s\n\n%s",
                'Die PayPal-Prüfung für Buchung #' . $booking->get('bid') . ' ist seit ' . $days . ' Tagen nicht abgeschlossen.',
                $this->bookingDetailsText($booking),
                'Mitglied: ' . $this->userName($booking),
                'PayPal-Status: ' . $details['paymentStatus'] . ' / ' . self::describePendingReason($details['pendingReason']),
                'PayPal-Transaktion: ' . ($booking->getMeta('paypalTransactionId') ?: '-'),
                'Bitte im PayPal-Konto prüfen. Diese Meldung wird pro Buchung nur einmal versendet.'));
    }

    /* ------------------------------------------------------------------ */
    /* Job 2: remind members who left the gateway without paying           */
    /* ------------------------------------------------------------------ */

    /**
     * Sends one payment reminder per abandoned booking before the cleanup event deletes it.
     *
     * @param boolean $dryRun
     * @return array report
     */
    public function remindUnpaid($dryRun = false)
    {
        $after = $this->intOption('service.payment.reminder-after-minutes', self::DEFAULT_REMINDER_AFTER_MINUTES);
        $grace = $this->intOption('service.payment.unpaid-grace-minutes', self::DEFAULT_UNPAID_GRACE_MINUTES);

        $report = array('checked' => 0, 'reminded' => 0, 'skipped' => 0, 'errors' => 0,
                        'window' => $after . '-' . $grace . ' min', 'items' => array());

        if ($after >= $grace) {
            /* Misconfiguration would either spam or never remind — refuse instead of guessing. */
            $report['errors']++;
            $report['items'][] = array('result' => 'error',
                'message' => 'reminder-after-minutes must be smaller than unpaid-grace-minutes');

            return $report;
        }

        foreach ($this->findAbandonedBookings($after, $grace) as $row) {
            $bid = (int) $row['bid'];
            $report['checked']++;

            try {
                $booking = $this->bookingManager->get($bid);
                $user = $this->userManager->get($booking->get('uid'));
            } catch (\Exception $e) {
                $report['errors']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'error', 'message' => $e->getMessage());
                continue;
            }

            if (! $user || ! $user->get('email')) {
                $report['skipped']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'no-email');
                continue;
            }

            if ($dryRun) {
                $report['reminded']++;
                $report['items'][] = array('bid' => $bid, 'result' => 'would-remind');
                continue;
            }

            $minutesLeft = max(1, $grace - $this->ageInMinutes($booking));

            $this->mailUser($booking,
                'Ihre Buchung ist noch nicht bezahlt - Buchung #' . $bid,
                sprintf("%s\n\n%s\n\n%s\n\n%s",
                    'die Zahlung für Ihre Buchung wurde nicht abgeschlossen, die Reservierung ist daher noch nicht verbindlich.',
                    $this->bookingDetailsText($booking),
                    'Sie können die Zahlung hier nachholen: ' . $this->billsUrl($bid),
                    sprintf('Wichtig: Wird die Zahlung nicht innerhalb von etwa %d Minuten abgeschlossen, wird die Reservierung automatisch aufgehoben und der Platz wieder freigegeben.', $minutesLeft)));

            $booking->setMeta('paymentReminderSent', date('Y-m-d H:i:s'));
            $this->bookingManager->save($booking);

            $this->audit('payment_reminder',
                sprintf('Zahlungserinnerung versendet: Buchung #%s, %s', $bid, $this->userName($booking)),
                $booking, array('minutes_left' => $minutesLeft));

            $report['reminded']++;
            $report['items'][] = array('bid' => $bid, 'result' => 'reminded', 'minutesLeft' => $minutesLeft);
        }

        return $report;
    }

    /**
     * Unpaid direct-payment bookings inside the reminder window that were not reminded yet.
     *
     * payLater bookings are excluded: those are open bills, not abandoned checkouts, and the
     * cleanup event does not touch them.
     *
     * @param int $after minutes
     * @param int $grace minutes
     * @return array rows with 'bid'
     */
    protected function findAbandonedBookings($after, $grace)
    {
        $after = (int) $after;
        $grace = (int) $grace;

        return $this->fetchAll(
            "SELECT b.bid FROM bs_bookings b "
            . "JOIN bs_bookings_meta dp ON dp.bid = b.bid AND dp.`key` = 'directpay' AND dp.value = 'true' "
            . "LEFT JOIN bs_bookings_meta rs ON rs.bid = b.bid AND rs.`key` = 'paymentReminderSent' "
            . "LEFT JOIN bs_bookings_meta pl ON pl.bid = b.bid AND pl.`key` = 'payLater' AND pl.value = 'true' "
            . "WHERE b.status = 'single' AND b.status_billing = 'pending' "
            . "AND b.created < (NOW() - INTERVAL " . $after . " MINUTE) "
            . "AND b.created > (NOW() - INTERVAL " . $grace . " MINUTE) "
            . "AND rs.bid IS NULL AND pl.bid IS NULL "
            . "ORDER BY b.bid ASC LIMIT 200"
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Returns a budget share that was deducted for a booking whose payment failed later.
     *
     * BookingService::refundBudget() only works for paid bookings, so the pending case is
     * handled here — atomically and only once.
     *
     * @param object $booking
     * @return float refunded EUR
     */
    protected function refundDeductedBudget($booking)
    {
        if ($booking->getMeta('hasBudget') != 'true' || $booking->getMeta('refunded') == 'true') {
            return 0.0;
        }

        $oldBudget = (float) $booking->getMeta('budget');
        $newBudget = (float) $booking->getMeta('newbudget');
        $amount = round($oldBudget - $newBudget, 2);

        if ($amount <= 0) {
            return 0.0;
        }

        $booking->setMeta('refunded', 'true');
        $this->bookingManager->save($booking);
        $this->userManager->addBudgetAtomic($booking->get('uid'), $amount);

        $this->audit('budget_refund',
            sprintf('Budget-Rueckerstattung: %.2f EUR fuer Buchung #%s (PayPal-Pruefung negativ)', $amount, $booking->get('bid')),
            $booking, array('refund_eur' => $amount, 'uid' => $booking->get('uid')));

        return $amount;
    }

    /**
     * @param object $booking
     * @return boolean true when at least one reservation is today or later
     */
    protected function hasFutureReservation($booking)
    {
        $rows = $this->fetchAll(
            "SELECT COUNT(*) AS cnt FROM bs_reservations WHERE bid = " . (int) $booking->get('bid')
            . " AND date >= CURDATE()"
        );

        return $rows && (int) $rows[0]['cnt'] > 0;
    }

    /**
     * @param object $booking
     * @return int|null days since the review started
     */
    protected function reviewAgeInDays($booking)
    {
        $since = $booking->getMeta('paypalReviewSince');

        if (! $since) {
            return null;
        }

        try {
            $start = new \DateTime($since);
        } catch (\Exception $e) {
            return null;
        }

        return (int) $start->diff(new \DateTime())->days;
    }

    /**
     * @param object $booking
     * @return int minutes since creation
     */
    protected function ageInMinutes($booking)
    {
        try {
            $created = new \DateTime($booking->get('created'));
        } catch (\Exception $e) {
            return 0;
        }

        return (int) floor((time() - $created->getTimestamp()) / 60);
    }

    /**
     * Structured booking details block for the notification mails.
     *
     * @param object $booking
     * @return string
     */
    protected function bookingDetailsText($booking)
    {
        try {
            $square = $this->squareManager->get($booking->need('sid'));
            $reservations = $this->reservationManager->getBy(array('bid' => $booking->need('bid')), 'date ASC', 1);
            $reservation = current($reservations);

            if (! $reservation) {
                return 'Buchungs-Nr: ' . $booking->get('bid');
            }

            return sprintf("Buchungsdetails:\n\n- Platz: %s\n- Datum: %s\n- Zeit: %s - %s Uhr\n- Buchungs-Nr: %s",
                $square->need('name'),
                (new \DateTime($reservation->need('date')))->format('d.m.Y'),
                substr($reservation->need('time_start'), 0, 5),
                substr($reservation->need('time_end'), 0, 5),
                $booking->get('bid'));
        } catch (\Exception $e) {
            return 'Buchungs-Nr: ' . $booking->get('bid');
        }
    }

    /**
     * @param object $booking
     * @return string display name of the booking owner
     */
    protected function userName($booking)
    {
        try {
            $user = $this->userManager->get($booking->get('uid'));

            if (! $user) {
                return 'uid=' . $booking->get('uid');
            }

            return trim($user->getMeta('firstname') . ' ' . $user->getMeta('lastname')) ?: $user->get('alias');
        } catch (\Exception $e) {
            return 'uid=' . $booking->get('uid');
        }
    }

    /**
     * Public bills URL of a booking (payment can be repeated from there).
     *
     * @param int $bid
     * @return string
     */
    protected function billsUrl($bid)
    {
        $base = rtrim((string) $this->configManager->get('proxyurl', $this->configManager->get('baseurl', '')), '/');
        $path = trim((string) $this->configManager->get('basepath', ''), '/');

        return $base . ($path ? '/' . $path : '') . '/user/bookings/bills/' . (int) $bid;
    }

    /**
     * Mail to the booking owner, with salutation and signature. Never throws.
     *
     * @param object $booking
     * @param string $subject
     * @param string $body already-formatted body without salutation
     */
    protected function mailUser($booking, $subject, $body)
    {
        try {
            $user = $this->userManager->get($booking->get('uid'));

            if (! $user || ! $user->get('email')) {
                return;
            }

            $firstname = $user->getMeta('firstname');
            $lastname = $user->getMeta('lastname');

            if ($firstname && $lastname) {
                $salutation = 'Hallo ' . $firstname . ' ' . $lastname;
            } elseif ($lastname) {
                $salutation = 'Hallo ' . $lastname;
            } else {
                $salutation = 'Hallo ' . $user->need('alias');
            }

            $this->mailService->sendCustomEmail($subject, $salutation . ",\n\n" . $body,
                $user->get('email'), $user->need('alias'), array(), null, true);
        } catch (\Exception $e) {
            error_log('PaymentReconciliation mailUser error: ' . $e->getMessage());
        }
    }

    /**
     * Mail to the club administration (client.contact.email). Never throws.
     *
     * @param string $subject
     * @param string $text
     */
    protected function mailAdmin($subject, $text)
    {
        try {
            $this->mailService->send($subject, $text);
        } catch (\Exception $e) {
            error_log('PaymentReconciliation mailAdmin error: ' . $e->getMessage());
        }
    }

    /**
     * Audit entry, fire-and-forget.
     *
     * @param string $action
     * @param string $message
     * @param object $booking
     * @param array $detail
     */
    protected function audit($action, $message, $booking, array $detail = array())
    {
        try {
            $this->auditService->log('payment', $action, $message, array(
                'user_id' => $booking->get('uid'),
                'entity_type' => 'booking',
                'entity_id' => $booking->get('bid'),
                'detail' => array_merge(array('source' => 'reconcile-cli'), $detail),
            ));
        } catch (\Exception $e) {
            error_log('PaymentReconciliation audit error: ' . $e->getMessage());
        }
    }

    /**
     * @return int configured review alert threshold in days
     */
    protected function reviewAlertDays()
    {
        return $this->intOption('service.payment.review-alert-days', self::DEFAULT_REVIEW_ALERT_DAYS);
    }

    /**
     * @param string $key bs_options key
     * @param int $default
     * @return int
     */
    protected function intOption($key, $default)
    {
        $value = $this->optionManager->get($key, null);

        return (is_numeric($value) && (int) $value > 0) ? (int) $value : $default;
    }

    /**
     * @param string $sql
     * @return array
     */
    protected function fetchAll($sql)
    {
        $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);

        $rows = array();

        foreach ($result as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

}
