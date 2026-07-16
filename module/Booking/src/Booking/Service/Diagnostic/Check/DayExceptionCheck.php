<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;
use DateTime;

/**
 * Active reservations falling on a date hidden by service.calendar.day-exceptions
 * (and not re-opened by a leading '+' exception). Informational — such dates are
 * hidden from the calendar, so a live reservation there is worth noticing.
 */
class DayExceptionCheck extends AbstractCheck
{

    public function getKey()         { return 'time.on-day-exception'; }
    public function needsDateRange() { return true; }
    public function getDescription() { return 'Reservierungen an aus dem Kalender ausgeblendeten Ausnahmetagen.'; }

    public function run(DiagnosticContext $context)
    {
        $raw = $context->optionManager()->get('service.calendar.day-exceptions');

        if (! $raw) {
            return array();
        }

        $exceptions = array();
        $reopened   = array();

        foreach (preg_split('~(\\n|,)~', $raw) as $entry) {
            $entry = trim($entry);
            if ($entry === '') {
                continue;
            }
            if ($entry[0] === '+') {
                $reopened[] = trim($entry, '+');
            } else {
                $exceptions[] = $entry;
            }
        }

        if (! $exceptions) {
            return array();
        }

        $findings = array();

        foreach ($context->reservationsInRange() as $rid => $reservation) {
            if ($reservation->get('status', 'confirmed') == 'cancelled') {
                continue;
            }

            $booking = $reservation->getExtra('booking');

            if (! $booking || $booking->need('status') == 'cancelled') {
                continue;
            }

            $date = $reservation->need('date');

            try {
                $dt = new DateTime($date);
            } catch (\Exception $e) {
                continue;
            }

            $ymd     = $dt->format('Y-m-d');
            $weekday = $dt->format('l');

            $isException = in_array($ymd, $exceptions, true) || in_array($weekday, $exceptions, true);
            $isReopened  = in_array($ymd, $reopened, true);

            if ($isException && ! $isReopened) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_INFO,
                    sprintf('Reservierung #%d liegt am ausgeblendeten Ausnahmetag %s.', (int) $rid, $ymd),
                    array('entityType' => 'reservation', 'entityId' => (int) $rid, 'date' => $ymd,
                          'bids' => array((int) $booking->need('bid')), 'rids' => array((int) $rid))
                );
            }
        }

        return $findings;
    }

}
