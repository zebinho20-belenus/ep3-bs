<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Reservations with a non-positive duration (time_start >= time_end), treating
 * time_end '00:00:00' as end-of-day (86400 seconds).
 */
class InvalidTimeRangeCheck extends AbstractCheck
{

    public function getKey()         { return 'time.invalid-range'; }
    public function getDescription() { return 'Reservierungen mit ungültigem Zeitbereich (Start >= Ende).'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            'SELECT r.rid, r.bid, r.date, r.time_start, r.time_end FROM bs_reservations r '
            . "WHERE TIME_TO_SEC(r.time_start) >= "
            . "(CASE WHEN r.time_end = '00:00:00' THEN 86400 ELSE TIME_TO_SEC(r.time_end) END) LIMIT 1000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $rid = (int) $row['rid'];
            $findings[] = $this->finding(
                Finding::SEVERITY_CRITICAL,
                sprintf('Reservierung #%d (%s): Zeitbereich %s–%s ist ungültig.',
                    $rid, $row['date'], $row['time_start'], $row['time_end']),
                array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                      'bids' => array((int) $row['bid']), 'rids' => array($rid),
                      'detail' => array('time_start' => $row['time_start'], 'time_end' => $row['time_end']))
            );
        }

        return $findings;
    }

}
