<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Detects real over-occupancy: overlapping active public reservations on the
 * same EFFECTIVE square (sid_override ?: booking sid) whose summed effective
 * quantity exceeds what the square capacity allows.
 *
 * Mirrors the capacity rule of Square\Service\SquareValidator::isBookable():
 *  - non-heterogenic square: at most one booking per slot (>=2 overlapping
 *    distinct bookings = conflict, regardless of capacity number)
 *  - heterogenic square: summed quantity must not exceed capacity
 */
class DoubleBookingCheck extends AbstractCheck
{

    public function getKey()         { return 'occupancy.double-booking'; }
    public function needsDateRange() { return true; }
    public function getDescription() { return 'Überlappende aktive Buchungen auf demselben effektiven Platz (Doppelbuchung/Überbelegung).'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();
        $squares  = $context->squares();

        /* Group active public reservations by (date, effective sid). */
        $groups = array();

        foreach ($context->reservationsInRange() as $rid => $reservation) {
            if ($reservation->get('status', 'confirmed') == 'cancelled') {
                continue;
            }

            $booking = $reservation->getExtra('booking');

            if (! $booking || $booking->need('status') == 'cancelled' || $booking->need('visibility') != 'public') {
                continue;
            }

            $sid  = $context->effectiveSid($reservation, $booking);
            $date = $reservation->need('date');
            $key  = $date . '#' . $sid;

            $groups[$key][] = array(
                'rid'      => (int) $rid,
                'bid'      => (int) $booking->need('bid'),
                'date'     => $date,
                'sid'      => $sid,
                'start'    => $context->timeToSeconds($reservation->need('time_start')),
                'end'      => $context->timeToSeconds($reservation->need('time_end'), true),
                'quantity' => $context->effectiveQuantity($reservation, $booking),
            );
        }

        foreach ($groups as $segments) {
            if (count($segments) < 2) {
                /* A single segment can only over-occupy if it alone exceeds a
                   heterogenic capacity; handle inside the sweep uniformly. */
            }

            $sid    = $segments[0]['sid'];
            $date   = $segments[0]['date'];
            $square = isset($squares[$sid]) ? $squares[$sid] : null;

            if (! $square) {
                /* Effective square does not exist — reported by override.sid-orphan. */
                continue;
            }

            $capacity    = (int) $square->get('capacity', 1);
            $heterogenic = (bool) $square->get('capacity_heterogenic', 0);

            /* Sweep line over segment boundaries. */
            $points = array();
            foreach ($segments as $s) {
                $points[$s['start']] = true;
                $points[$s['end']]   = true;
            }
            $points = array_keys($points);
            sort($points);

            $conflict     = false;
            $peakQuantity = 0;
            $peakBids     = array();
            $involvedBids = array();
            $involvedRids = array();

            for ($i = 0; $i < count($points) - 1; $i++) {
                $t0 = $points[$i];
                $t1 = $points[$i + 1];

                if ($t1 <= $t0) {
                    continue;
                }

                $mid          = ($t0 + $t1) / 2;
                $quantityHere = 0;
                $bidsHere     = array();
                $ridsHere     = array();

                foreach ($segments as $s) {
                    if ($s['start'] <= $mid && $s['end'] > $mid) {
                        $quantityHere += $s['quantity'];
                        $bidsHere[$s['bid']] = true;
                        $ridsHere[$s['rid']] = true;
                    }
                }

                $isConflict = $heterogenic
                    ? ($quantityHere > $capacity)
                    : (count($bidsHere) >= 2 || $quantityHere > $capacity);

                if ($isConflict) {
                    $conflict = true;

                    if ($quantityHere > $peakQuantity) {
                        $peakQuantity = $quantityHere;
                        $peakBids     = array_keys($bidsHere);
                    }

                    foreach ($bidsHere as $b => $_) { $involvedBids[$b] = true; }
                    foreach ($ridsHere as $r => $_) { $involvedRids[$r] = true; }
                }
            }

            if ($conflict) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_CRITICAL,
                    sprintf(
                        '%s am %s: %d überlappende Buchungen, Belegung %d > Kapazität %d.',
                        $context->squareName($sid), $date, count($involvedBids), $peakQuantity, $capacity
                    ),
                    array(
                        'entityType' => 'square',
                        'entityId'   => $sid,
                        'date'       => $date,
                        'sid'        => $sid,
                        'bids'       => array_keys($involvedBids),
                        'rids'       => array_keys($involvedRids),
                        'detail'     => array(
                            'capacity'        => $capacity,
                            'heterogenic'     => $heterogenic,
                            'peakQuantity'    => $peakQuantity,
                            'peakBookings'    => $peakBids,
                        ),
                    )
                );
            }
        }

        return $findings;
    }

}
