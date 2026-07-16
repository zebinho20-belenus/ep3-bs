<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Bookings marked status_billing='paid' with neither a directpay nor a
 * budgetpayment marker. Often a legitimate manual/cash payment marked by an
 * admin, but also the fingerprint of a payment flow that set 'paid' without a
 * recorded transaction — hence flagged for review (warning, not critical).
 */
class PaidWithoutEvidenceCheck extends AbstractCheck
{

    public function getKey()         { return 'payment.paid-without-evidence'; }
    public function getDescription() { return 'Als bezahlt markierte Buchungen ohne elektronischen Zahlungsnachweis.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT b.bid FROM bs_bookings b "
            . "WHERE b.status_billing = 'paid' "
            . "AND b.bid NOT IN (SELECT bid FROM bs_bookings_meta WHERE `key` = 'directpay' AND value = 'true') "
            . "AND b.bid NOT IN (SELECT bid FROM bs_bookings_meta WHERE `key` = 'budgetpayment' AND value = 'true') "
            . "LIMIT 1000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $bid = (int) $row['bid'];
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                sprintf('Buchung #%d ist "bezahlt" ohne directpay-/budgetpayment-Nachweis (evtl. manuell/bar).', $bid),
                array('entityType' => 'booking', 'entityId' => $bid, 'bids' => array($bid))
            );
        }

        return $findings;
    }

}
