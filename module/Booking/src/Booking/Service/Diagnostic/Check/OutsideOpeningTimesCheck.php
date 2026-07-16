<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;
use DateTime;

/**
 * Active reservations lying outside the effective opening times of their
 * (effective) square for that date. Effective window = highest-priority
 * matching bs_squares_opening_times rule, else the square's own time_start/end.
 */
class OutsideOpeningTimesCheck extends AbstractCheck
{

    public function getKey()         { return 'time.outside-opening'; }
    public function needsDateRange() { return true; }
    public function getDescription() { return 'Reservierungen außerhalb der Öffnungszeit des Platzes.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();
        $squares  = $context->squares();

        foreach ($context->reservationsInRange() as $rid => $reservation) {
            if ($reservation->get('status', 'confirmed') == 'cancelled') {
                continue;
            }

            $booking = $reservation->getExtra('booking');

            if (! $booking || $booking->need('status') == 'cancelled' || $booking->need('visibility') != 'public') {
                continue;
            }

            $sid = $context->effectiveSid($reservation, $booking);

            if (! isset($squares[$sid])) {
                continue; /* reported by override.sid-orphan / ref.dangling */
            }

            $square = $squares[$sid];
            $date   = $reservation->need('date');

            try {
                $dateTime = new DateTime($date . ' ' . $reservation->need('time_start'));
            } catch (\Exception $e) {
                continue;
            }

            $effective = $context->openingTimesManager()->getOpeningTimes($dateTime, $sid);
            $openStart = $effective ? $effective['time_start'] : $square->get('time_start', '00:00');
            $openEnd   = $effective ? $effective['time_end']   : $square->get('time_end', '00:00');

            $resStart  = $context->timeToSeconds($reservation->need('time_start'));
            $resEnd    = $context->timeToSeconds($reservation->need('time_end'), true);
            $winStart  = $context->timeToSeconds($openStart);
            $winEnd    = $context->timeToSeconds($openEnd, true);

            if ($resStart < $winStart || $resEnd > $winEnd) {
                $bid = (int) $booking->need('bid');
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('Reservierung #%d am %s (%s): %s–%s liegt außerhalb der Öffnungszeit %s–%s.',
                        (int) $rid, $date, $context->squareName($sid),
                        $reservation->need('time_start'), $reservation->need('time_end'), $openStart, $openEnd),
                    array('entityType' => 'reservation', 'entityId' => (int) $rid, 'date' => $date, 'sid' => $sid,
                          'bids' => array($bid), 'rids' => array((int) $rid),
                          'detail' => array('reservation' => $reservation->need('time_start') . '-' . $reservation->need('time_end'),
                                            'opening' => $openStart . '-' . $openEnd))
                );
            }
        }

        return $findings;
    }

}
