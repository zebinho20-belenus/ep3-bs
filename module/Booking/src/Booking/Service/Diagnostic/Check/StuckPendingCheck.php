<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Unpaid single bookings older than 3h that the auto-cleanup MySQL event will
 * never remove, because that event only deletes bookings with directpay='true'.
 * Async gateway bookings (directpay='false', directpay_pending='true') and any
 * pending single booking lacking directpay='true' get stuck forever.
 */
class StuckPendingCheck extends AbstractCheck
{

    public function getKey()         { return 'payment.stuck-pending'; }
    public function getDescription() { return 'Alte unbezahlte Buchungen, die der Auto-Cleanup nicht erfasst.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT b.bid, b.created FROM bs_bookings b "
            . "WHERE b.status = 'single' AND b.status_billing = 'pending' "
            . "AND b.created < (NOW() - INTERVAL 3 HOUR) "
            . "AND b.bid NOT IN (SELECT bid FROM bs_bookings_meta WHERE `key` = 'directpay' AND value = 'true') "
            . "LIMIT 1000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $bid = (int) $row['bid'];
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                sprintf('Buchung #%d ist seit %s unbezahlt/pending und wird vom Auto-Cleanup nicht entfernt.',
                    $bid, $row['created']),
                array('entityType' => 'booking', 'entityId' => $bid, 'bids' => array($bid),
                      'detail' => array('created' => $row['created']))
            );
        }

        return $findings;
    }

}
