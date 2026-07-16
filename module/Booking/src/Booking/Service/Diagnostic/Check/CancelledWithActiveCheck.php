<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Cancelled bookings that still carry non-cancelled reservations. The code
 * tolerates this (it filters at booking level), so this is informational only.
 */
class CancelledWithActiveCheck extends AbstractCheck
{

    public function getKey()         { return 'status.cancelled-with-active'; }
    public function getCategory()    { return 'status'; }
    public function getDescription() { return 'Stornierte Buchungen mit noch aktiven Reservierungen (vom System toleriert).'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT b.bid, COUNT(r.rid) AS active FROM bs_bookings b "
            . "JOIN bs_reservations r ON r.bid = b.bid "
            . "WHERE b.status = 'cancelled' AND (r.status IS NULL OR r.status <> 'cancelled') "
            . "GROUP BY b.bid LIMIT 1000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $findings[] = $this->finding(
                Finding::SEVERITY_INFO,
                sprintf('Stornierte Buchung #%d hat %d aktive Reservierung(en).', $row['bid'], $row['active']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']),
                      'detail' => array('activeReservations' => (int) $row['active']))
            );
        }

        return $findings;
    }

}
