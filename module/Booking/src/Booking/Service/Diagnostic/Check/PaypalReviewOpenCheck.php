<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Payments PayPal accepted but never released.
 *
 * A booking under review keeps status_billing='pending' + meta directpay_pending='true' and is
 * settled by scripts/payments.php reconcile. Anything that sits there for days means either the
 * reconcile cron is not running, or PayPal is genuinely stuck — both need a human.
 *
 * Also flags reviews without a paypalTransactionId: those can never be reconciled automatically.
 */
class PaypalReviewOpenCheck extends AbstractCheck
{

    /** Days a review may stay open before it is reported */
    const MAX_REVIEW_DAYS = 7;

    public function getKey()         { return 'payment.paypal-review-open'; }
    public function getDescription() { return 'PayPal-Zahlungen, die zu lange in Pruefung haengen.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT b.bid, b.status_billing, "
            . "(SELECT value FROM bs_bookings_meta WHERE bid = b.bid AND `key` = 'paypalReviewSince' LIMIT 1) AS review_since, "
            . "(SELECT value FROM bs_bookings_meta WHERE bid = b.bid AND `key` = 'paypalTransactionId' LIMIT 1) AS transaction_id, "
            . "(SELECT value FROM bs_bookings_meta WHERE bid = b.bid AND `key` = 'paypalPendingReason' LIMIT 1) AS pending_reason "
            . "FROM bs_bookings b "
            . "JOIN bs_bookings_meta dpp ON dpp.bid = b.bid AND dpp.`key` = 'directpay_pending' AND dpp.value = 'true' "
            . "WHERE b.status <> 'cancelled' AND b.status_billing = 'pending' "
            . "LIMIT 500"
        );

        $findings = array();

        foreach ($rows as $row) {
            $bid = (int) $row['bid'];
            $reason = $row['pending_reason'] ?: 'unbekannt';

            if (! $row['transaction_id']) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('Buchung #%d ist in Zahlungspruefung, hat aber keine PayPal-Transaktions-ID — kann nicht automatisch aufgeloest werden.', $bid),
                    array('entityType' => 'booking', 'entityId' => $bid, 'bids' => array($bid),
                          'detail' => array('pendingReason' => $reason, 'reviewSince' => $row['review_since']))
                );

                continue;
            }

            $days = $this->daysSince($row['review_since']);

            if ($days === null || $days >= self::MAX_REVIEW_DAYS) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    $days === null
                        ? sprintf('Buchung #%d haengt in PayPal-Pruefung (Grund: %s), Startzeitpunkt unbekannt.', $bid, $reason)
                        : sprintf('Buchung #%d haengt seit %d Tagen in PayPal-Pruefung (Grund: %s).', $bid, $days, $reason),
                    array('entityType' => 'booking', 'entityId' => $bid, 'bids' => array($bid),
                          'detail' => array('pendingReason' => $reason, 'reviewSince' => $row['review_since'],
                                            'transactionId' => $row['transaction_id'], 'days' => $days))
                );
            }
        }

        return $findings;
    }

    /**
     * @param string|null $timestamp
     * @return int|null
     */
    protected function daysSince($timestamp)
    {
        if (! $timestamp) {
            return null;
        }

        try {
            $start = new \DateTime($timestamp);
        } catch (\Exception $e) {
            return null;
        }

        return (int) $start->diff(new \DateTime())->days;
    }

}
