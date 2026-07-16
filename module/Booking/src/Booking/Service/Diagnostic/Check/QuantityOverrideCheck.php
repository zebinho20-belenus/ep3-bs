<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Per-reservation quantity_override outside the valid range (<= 0, or greater
 * than the effective square's capacity).
 */
class QuantityOverrideCheck extends AbstractCheck
{

    public function getKey()         { return 'override.quantity-invalid'; }
    public function getDescription() { return 'quantity_override <= 0 oder größer als die Platzkapazität.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            'SELECT m.rid, r.bid, r.date, m.value AS q, '
            . "COALESCE((SELECT mo.value FROM bs_reservations_meta mo WHERE mo.rid = r.rid AND mo.`key` = 'sid_override'), b.sid) AS eff_sid "
            . 'FROM bs_reservations_meta m '
            . 'JOIN bs_reservations r ON r.rid = m.rid '
            . 'JOIN bs_bookings b ON b.bid = r.bid '
            . "WHERE m.`key` = 'quantity_override' LIMIT 2000"
        );

        $squares  = $context->squares();
        $findings = array();

        foreach ($rows as $row) {
            $q      = (int) $row['q'];
            $effSid = (int) $row['eff_sid'];
            $rid    = (int) $row['rid'];
            $bid    = (int) $row['bid'];

            $capacity = isset($squares[$effSid]) ? (int) $squares[$effSid]->get('capacity', 1) : null;

            $invalid = ($q <= 0) || ($capacity !== null && $q > $capacity);

            if ($invalid) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf(
                        'Reservierung #%d: quantity_override=%d ungültig (Kapazität %s).',
                        $rid, $q, $capacity === null ? '?' : $capacity
                    ),
                    array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                          'sid' => $effSid, 'bids' => array($bid), 'rids' => array($rid),
                          'detail' => array('quantity_override' => $q, 'capacity' => $capacity))
                );
            }
        }

        return $findings;
    }

}
