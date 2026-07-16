<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Enum-like columns holding values outside their known domain.
 */
class StatusEnumCheck extends AbstractCheck
{

    public function getKey()         { return 'status.enum'; }
    public function getDescription() { return 'Status-/Enum-Spalten mit unbekannten Werten.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        $specs = array(
            array('bs_bookings', 'bid', 'status', "'single','subscription','cancelled'", false),
            array('bs_bookings', 'bid', 'status_billing', "'pending','paid','cancelled','uncollectable','member','training','free'", false),
            array('bs_bookings', 'bid', 'visibility', "'public','private'", false),
            array('bs_reservations', 'rid', 'status', "'confirmed','cancelled'", true),
            array('bs_squares', 'sid', 'status', "'enabled','readonly','disabled'", false),
            array('bs_users', 'uid', 'status',
                "'placeholder','deleted','blocked','disabled','enabled','assist','admin','guestgroup','singleguest','team'", false),
        );

        foreach ($specs as $spec) {
            list($table, $pk, $column, $allowed, $treatEmptyAsValid) = $spec;

            $sql = sprintf('SELECT %s AS id, %s AS val FROM %s WHERE %s NOT IN (%s)',
                $pk, $column, $table, $column, $allowed);

            if ($treatEmptyAsValid) {
                $sql .= sprintf(' AND %s IS NOT NULL AND %s <> \'\'', $column, $column);
            }

            $sql .= ' LIMIT 1000';

            foreach ($context->fetchAll($sql) as $row) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('%s #%d: %s="%s" ist kein gültiger Wert.', $table, $row['id'], $column, $row['val']),
                    array(
                        'entityType' => $table,
                        'entityId'   => (int) $row['id'],
                        'detail'     => array('table' => $table, 'column' => $column, 'value' => $row['val']),
                    )
                );
            }
        }

        return $findings;
    }

}
