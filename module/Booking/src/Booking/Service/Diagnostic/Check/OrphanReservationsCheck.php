<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Reservations whose parent booking no longer exists (dangling bid).
 */
class OrphanReservationsCheck extends AbstractCheck
{

    public function getKey()         { return 'ref.orphan-reservation'; }
    public function getDescription() { return 'Reservierungen ohne zugehörige Buchung.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            'SELECT r.rid, r.bid, r.date FROM bs_reservations r '
            . 'LEFT JOIN bs_bookings b ON b.bid = r.bid WHERE b.bid IS NULL LIMIT 1000'
        );

        $findings = array();

        foreach ($rows as $row) {
            $findings[] = $this->finding(
                Finding::SEVERITY_CRITICAL,
                sprintf('Reservierung #%d verweist auf nicht existierende Buchung #%d.', $row['rid'], $row['bid']),
                array(
                    'entityType' => 'reservation',
                    'entityId'   => (int) $row['rid'],
                    'date'       => isset($row['date']) ? $row['date'] : null,
                    'bids'       => array((int) $row['bid']),
                    'rids'       => array((int) $row['rid']),
                )
            );
        }

        return $findings;
    }

}
