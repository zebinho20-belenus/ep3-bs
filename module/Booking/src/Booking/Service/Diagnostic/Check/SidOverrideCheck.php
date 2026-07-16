<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Consistency of the per-reservation sid_override meta:
 *  - orphan: target square does not exist or is disabled (critical)
 *  - redundant: override equals the booking's own sid, i.e. no-op (info)
 */
class SidOverrideCheck extends AbstractCheck
{

    public function getKey()         { return 'override.sid'; }
    public function getDescription() { return 'sid_override zeigt auf nicht existierenden/deaktivierten Platz oder ist redundant.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            'SELECT m.rid, r.bid, r.date, m.value AS sid_override, b.sid AS base_sid, '
            . 's.sid AS target_sid, s.status AS target_status '
            . 'FROM bs_reservations_meta m '
            . 'JOIN bs_reservations r ON r.rid = m.rid '
            . 'JOIN bs_bookings b ON b.bid = r.bid '
            . 'LEFT JOIN bs_squares s ON s.sid = m.value '
            . "WHERE m.`key` = 'sid_override' LIMIT 2000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $override = (int) $row['sid_override'];
            $base     = (int) $row['base_sid'];
            $rid      = (int) $row['rid'];
            $bid      = (int) $row['bid'];

            if ($row['target_sid'] === null) {
                $findings[] = new Finding(
                    'override.sid-orphan', 'override', Finding::SEVERITY_CRITICAL,
                    sprintf('Reservierung #%d: sid_override %d zeigt auf nicht existierenden Platz.', $rid, $override),
                    array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                          'sid' => $override, 'bids' => array($bid), 'rids' => array($rid))
                );
            } elseif ($row['target_status'] === 'disabled') {
                $findings[] = new Finding(
                    'override.sid-orphan', 'override', Finding::SEVERITY_CRITICAL,
                    sprintf('Reservierung #%d: sid_override zeigt auf deaktivierten %s.', $rid, $context->squareName($override)),
                    array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                          'sid' => $override, 'bids' => array($bid), 'rids' => array($rid))
                );
            } elseif ($override === $base) {
                $findings[] = new Finding(
                    'override.sid-redundant', 'override', Finding::SEVERITY_INFO,
                    sprintf('Reservierung #%d: sid_override gleicht dem Buchungsplatz (%d) — wirkungslos.', $rid, $base),
                    array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                          'sid' => $base, 'bids' => array($bid), 'rids' => array($rid))
                );
            }
        }

        return $findings;
    }

}
