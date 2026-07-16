<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Bookings pointing at a non-existent square or user, and events pointing at a
 * non-existent square. These are RESTRICT foreign keys on fresh installs but
 * can dangle on databases imported without constraints.
 */
class DanglingBookingRefsCheck extends AbstractCheck
{

    public function getKey()         { return 'ref.dangling'; }
    public function getCategory()    { return 'ref'; }
    public function getDescription() { return 'Buchungen/Veranstaltungen mit Verweis auf nicht existierenden Platz oder Nutzer.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        $squareRefs = $context->fetchAll(
            'SELECT b.bid, b.sid FROM bs_bookings b '
            . 'LEFT JOIN bs_squares s ON s.sid = b.sid WHERE s.sid IS NULL LIMIT 1000'
        );
        foreach ($squareRefs as $row) {
            $findings[] = new Finding(
                'ref.booking-dangling-square', 'ref', Finding::SEVERITY_CRITICAL,
                sprintf('Buchung #%d verweist auf nicht existierenden Platz (sid %d).', $row['bid'], $row['sid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'],
                      'sid' => (int) $row['sid'], 'bids' => array((int) $row['bid']))
            );
        }

        $userRefs = $context->fetchAll(
            'SELECT b.bid, b.uid FROM bs_bookings b '
            . 'LEFT JOIN bs_users u ON u.uid = b.uid WHERE u.uid IS NULL LIMIT 1000'
        );
        foreach ($userRefs as $row) {
            $findings[] = new Finding(
                'ref.booking-dangling-user', 'ref', Finding::SEVERITY_CRITICAL,
                sprintf('Buchung #%d verweist auf nicht existierenden Nutzer (uid %d).', $row['bid'], $row['uid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'],
                      'bids' => array((int) $row['bid']), 'detail' => array('uid' => (int) $row['uid']))
            );
        }

        $eventRefs = $context->fetchAll(
            'SELECT e.eid, e.sid FROM bs_events e '
            . 'LEFT JOIN bs_squares s ON s.sid = e.sid WHERE e.sid IS NOT NULL AND s.sid IS NULL LIMIT 1000'
        );
        foreach ($eventRefs as $row) {
            $findings[] = new Finding(
                'ref.event-dangling-square', 'ref', Finding::SEVERITY_WARNING,
                sprintf('Veranstaltung #%d verweist auf nicht existierenden Platz (sid %d).', $row['eid'], $row['sid']),
                array('entityType' => 'event', 'entityId' => (int) $row['eid'], 'sid' => (int) $row['sid'])
            );
        }

        return $findings;
    }

}
