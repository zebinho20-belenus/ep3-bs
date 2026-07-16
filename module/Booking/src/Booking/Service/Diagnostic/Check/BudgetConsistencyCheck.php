<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Budget/refund consistency:
 *  - negative user budget (critical) — should be impossible via atomic ops
 *  - budgetpayment='true' but booking still 'pending' (warning) — interrupted deduction
 *  - refunded='true' but booking still 'pending' (info) — refund flag on unpaid booking
 */
class BudgetConsistencyCheck extends AbstractCheck
{

    public function getKey()         { return 'budget.consistency'; }
    public function getCategory()    { return 'budget'; }
    public function getDescription() { return 'Negatives Budget bzw. inkonsistente budgetpayment-/refunded-Marker.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        $negative = $context->fetchAll(
            "SELECT uid, value FROM bs_users_meta "
            . "WHERE `key` = 'budget' AND CAST(value AS DECIMAL(10,2)) < 0 LIMIT 1000"
        );
        foreach ($negative as $row) {
            $findings[] = new Finding(
                'budget.negative', 'budget', Finding::SEVERITY_CRITICAL,
                sprintf('Nutzer #%d hat negatives Budget (%s €).', $row['uid'], $row['value']),
                array('entityType' => 'user', 'entityId' => (int) $row['uid'],
                      'detail' => array('budget' => $row['value']))
            );
        }

        $budgetPayPending = $context->fetchAll(
            "SELECT b.bid FROM bs_bookings b "
            . "JOIN bs_bookings_meta m ON m.bid = b.bid AND m.`key` = 'budgetpayment' AND m.value = 'true' "
            . "WHERE b.status_billing = 'pending' LIMIT 1000"
        );
        foreach ($budgetPayPending as $row) {
            $findings[] = new Finding(
                'budget.budgetpay-not-paid', 'budget', Finding::SEVERITY_WARNING,
                sprintf('Buchung #%d: Budget-Zahlung markiert, aber Status noch "pending" (Abbuchung evtl. abgebrochen).', $row['bid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']))
            );
        }

        $refundedPending = $context->fetchAll(
            "SELECT b.bid FROM bs_bookings b "
            . "JOIN bs_bookings_meta m ON m.bid = b.bid AND m.`key` = 'refunded' AND m.value = 'true' "
            . "WHERE b.status_billing = 'pending' LIMIT 1000"
        );
        foreach ($refundedPending as $row) {
            $findings[] = new Finding(
                'budget.refunded-not-paid', 'budget', Finding::SEVERITY_INFO,
                sprintf('Buchung #%d: als erstattet markiert, aber Status "pending".', $row['bid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']))
            );
        }

        return $findings;
    }

}
