<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Active reservations whose effective square is disabled — they remain in the
 * data but the square is no longer bookable/visible.
 */
class DisabledSquareReservationCheck extends AbstractCheck
{

    public function getKey()         { return 'time.on-disabled-square'; }
    public function getCategory()    { return 'time'; }
    public function needsDateRange() { return true; }
    public function getDescription() { return 'Aktive Reservierungen auf deaktivierten Plätzen.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();
        $squares  = $context->squares();

        foreach ($context->reservationsInRange() as $rid => $reservation) {
            if ($reservation->get('status', 'confirmed') == 'cancelled') {
                continue;
            }

            $booking = $reservation->getExtra('booking');

            if (! $booking || $booking->need('status') == 'cancelled') {
                continue;
            }

            $sid = $context->effectiveSid($reservation, $booking);

            if (isset($squares[$sid]) && $squares[$sid]->get('status', 'enabled') === 'disabled') {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('Reservierung #%d am %s liegt auf deaktiviertem %s.',
                        (int) $rid, $reservation->need('date'), $context->squareName($sid)),
                    array('entityType' => 'reservation', 'entityId' => (int) $rid,
                          'date' => $reservation->need('date'), 'sid' => $sid,
                          'bids' => array((int) $booking->need('bid')), 'rids' => array((int) $rid))
                );
            }
        }

        return $findings;
    }

}
