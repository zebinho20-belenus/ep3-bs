<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Maintenance / administration mode left switched on (service.maintenance).
 */
class MaintenanceModeCheck extends AbstractCheck
{

    public function getKey()         { return 'system.maintenance-on'; }
    public function getDescription() { return 'Wartungs-/Verwaltungsmodus aktiv gelassen.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT value FROM bs_options WHERE `key` = 'service.maintenance' AND locale IS NULL LIMIT 1"
        );

        if (! $rows) {
            return array();
        }

        $value = $rows[0]['value'];

        if ($value !== null && $value !== '' && $value !== 'false') {
            $label = $value === 'administration' ? 'Verwaltungsmodus' : 'Wartungsmodus';

            return array($this->finding(
                Finding::SEVERITY_INFO,
                sprintf('%s ist aktiv (service.maintenance = "%s").', $label, $value),
                array('entityType' => 'option', 'detail' => array('value' => $value))
            ));
        }

        return array();
    }

}
