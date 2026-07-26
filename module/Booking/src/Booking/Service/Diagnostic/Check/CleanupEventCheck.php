<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Health of the MySQL scheduled events the payment flow depends on.
 *
 * remove_unpaid_bookings frees courts whose checkout was abandoned; cleanup_audit_log trims the
 * audit table. Both are silent when the global event scheduler is off — the symptom is a slowly
 * growing pile of unpaid bookings that block courts, which is exactly what happened on prod.
 */
class CleanupEventCheck extends AbstractCheck
{

    public function getKey()         { return 'payment.cleanup-event'; }
    public function getDescription() { return 'MySQL-Event-Scheduler und Cleanup-Events fuer unbezahlte Buchungen.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        $scheduler = $context->fetchAll("SHOW VARIABLES LIKE 'event_scheduler'");
        $schedulerOn = $scheduler && strtoupper((string) $scheduler[0]['Value']) === 'ON';

        if (! $schedulerOn) {
            $findings[] = $this->finding(
                Finding::SEVERITY_CRITICAL,
                sprintf('MySQL event_scheduler ist nicht aktiv (%s) — unbezahlte Buchungen werden nie aufgeraeumt.',
                    $scheduler ? $scheduler[0]['Value'] : 'unbekannt'),
                array('detail' => array('event_scheduler' => $scheduler ? $scheduler[0]['Value'] : null))
            );
        }

        $events = $context->fetchAll(
            "SELECT EVENT_NAME, STATUS, INTERVAL_VALUE, INTERVAL_FIELD FROM information_schema.EVENTS "
            . "WHERE EVENT_SCHEMA = DATABASE()"
        );

        $byName = array();

        foreach ($events as $event) {
            $byName[$event['EVENT_NAME']] = $event;
        }

        foreach (array('remove_unpaid_bookings', 'cleanup_audit_log') as $name) {
            if (! isset($byName[$name])) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_CRITICAL,
                    sprintf('MySQL-Event "%s" existiert nicht — zugehoerige Migration wurde nicht eingespielt.', $name),
                    array('detail' => array('event' => $name))
                );

                continue;
            }

            if (strtoupper((string) $byName[$name]['STATUS']) !== 'ENABLED') {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('MySQL-Event "%s" ist nicht aktiviert (Status: %s).', $name, $byName[$name]['STATUS']),
                    array('detail' => array('event' => $name, 'status' => $byName[$name]['STATUS']))
                );
            }
        }

        return $findings;
    }

}
