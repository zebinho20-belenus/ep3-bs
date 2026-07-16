<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Bookings marked status_billing='paid' with neither a directpay nor a
 * budgetpayment marker. For clubs settling most bookings manually (cash,
 * subscriptions, member accounts) this is the NORMAL case, not an anomaly —
 * so it is reported at INFO level. It stays useful as a forensic cross-check
 * (e.g. reconciling an unexpected 'paid' on a booking that was meant to run
 * through a gateway), but must not dominate the warning-level alert mail.
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
                Finding::SEVERITY_INFO,
                sprintf('Buchung #%d ist "bezahlt" ohne directpay-/budgetpayment-Nachweis (evtl. manuell/bar).', $bid),
                array('entityType' => 'booking', 'entityId' => $bid, 'bids' => array($bid))
            );
        }

        return $findings;
    }

}
