<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Schema version lagging behind the migration registry, or missing entirely.
 */
class PendingMigrationsCheck extends AbstractCheck
{

    public function getKey()         { return 'system.pending-migrations'; }
    public function getDescription() { return 'Datenbank-Schema-Version hinter dem Migrations-Register.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT value FROM bs_options WHERE `key` = 'schema.version' AND locale IS NULL LIMIT 1"
        );

        $findings = array();
        $latest   = $context->latestSchemaVersion;

        if (! $rows) {
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                'Kein schema.version-Eintrag in bs_options — Migrationsstatus unbekannt.',
                array('entityType' => 'option', 'detail' => array('key' => 'schema.version'))
            );

            return $findings;
        }

        $current = (int) $rows[0]['value'];

        if ($latest !== null && $current < $latest) {
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                sprintf('Schema-Version %d liegt unter der neuesten Migration %d — Migrationen ausstehend.', $current, $latest),
                array('entityType' => 'option',
                      'detail' => array('current' => $current, 'latest' => $latest))
            );
        }

        return $findings;
    }

}
