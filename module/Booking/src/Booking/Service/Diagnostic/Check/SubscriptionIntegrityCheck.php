<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Subscription bookings whose defining meta is inconsistent:
 *  - subscription without a positive `repeat` meta
 *  - single booking that nevertheless carries a `repeat` meta
 *  - subscription missing date_start/date_end/time_start/time_end, or date_start >= date_end
 */
class SubscriptionIntegrityCheck extends AbstractCheck
{

    public function getKey()         { return 'subscription.integrity'; }
    public function getCategory()    { return 'subscription'; }
    public function getDescription() { return 'Abo-Buchungen mit fehlendem/inkonsistentem repeat- oder Zeitraum-Meta.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        $missingRepeat = $context->fetchAll(
            "SELECT b.bid FROM bs_bookings b WHERE b.status = 'subscription' "
            . "AND NOT EXISTS (SELECT 1 FROM bs_bookings_meta m WHERE m.bid = b.bid "
            . "AND m.`key` = 'repeat' AND CAST(m.value AS UNSIGNED) > 0) LIMIT 1000"
        );
        foreach ($missingRepeat as $row) {
            $findings[] = new Finding(
                'subscription.missing-repeat', 'subscription', Finding::SEVERITY_WARNING,
                sprintf('Abo-Buchung #%d hat kein gültiges repeat-Intervall.', $row['bid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']))
            );
        }

        $repeatOnSingle = $context->fetchAll(
            "SELECT b.bid FROM bs_bookings b WHERE b.status = 'single' "
            . "AND EXISTS (SELECT 1 FROM bs_bookings_meta m WHERE m.bid = b.bid "
            . "AND m.`key` = 'repeat' AND CAST(m.value AS UNSIGNED) > 0) LIMIT 1000"
        );
        foreach ($repeatOnSingle as $row) {
            $findings[] = new Finding(
                'subscription.repeat-on-single', 'subscription', Finding::SEVERITY_WARNING,
                sprintf('Einzelbuchung #%d trägt ein repeat-Intervall (sollte Abo sein).', $row['bid']),
                array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']))
            );
        }

        $range = $context->fetchAll(
            "SELECT b.bid, "
            . "(SELECT value FROM bs_bookings_meta m WHERE m.bid = b.bid AND m.`key` = 'date_start') AS ds, "
            . "(SELECT value FROM bs_bookings_meta m WHERE m.bid = b.bid AND m.`key` = 'date_end') AS de, "
            . "(SELECT value FROM bs_bookings_meta m WHERE m.bid = b.bid AND m.`key` = 'time_start') AS ts, "
            . "(SELECT value FROM bs_bookings_meta m WHERE m.bid = b.bid AND m.`key` = 'time_end') AS te "
            . "FROM bs_bookings b WHERE b.status = 'subscription' LIMIT 2000"
        );
        foreach ($range as $row) {
            $missing = array();
            foreach (array('ds' => 'date_start', 'de' => 'date_end', 'ts' => 'time_start', 'te' => 'time_end') as $k => $label) {
                if ($row[$k] === null || $row[$k] === '') {
                    $missing[] = $label;
                }
            }

            if ($missing) {
                $findings[] = new Finding(
                    'subscription.missing-range', 'subscription', Finding::SEVERITY_WARNING,
                    sprintf('Abo-Buchung #%d fehlt Meta: %s.', $row['bid'], implode(', ', $missing)),
                    array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']),
                          'detail' => array('missing' => $missing))
                );
            } elseif ($row['ds'] >= $row['de']) {
                $findings[] = new Finding(
                    'subscription.missing-range', 'subscription', Finding::SEVERITY_WARNING,
                    sprintf('Abo-Buchung #%d: date_start (%s) >= date_end (%s).', $row['bid'], $row['ds'], $row['de']),
                    array('entityType' => 'booking', 'entityId' => (int) $row['bid'], 'bids' => array((int) $row['bid']),
                          'detail' => array('date_start' => $row['ds'], 'date_end' => $row['de']))
                );
            }
        }

        return $findings;
    }

}
