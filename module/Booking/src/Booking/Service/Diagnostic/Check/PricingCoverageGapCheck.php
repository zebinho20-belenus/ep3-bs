<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Visible squares that once had pricing rules but none covering today or later.
 * Their $payable becomes false and payment buttons disappear (the classic
 * "prod DB import → bs_squares_pricing.date_end lapsed" trap). Squares that
 * never had any pricing are intentionally free and are not flagged.
 */
class PricingCoverageGapCheck extends AbstractCheck
{

    public function getKey()         { return 'pricing.coverage-gap'; }
    public function getDescription() { return 'Kostenpflichtiger Platz ohne Preisregel ab heute → keine Zahl-Buttons.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            "SELECT s.sid, s.name FROM bs_squares s "
            . "WHERE s.status IN ('enabled', 'readonly') "
            . "AND EXISTS (SELECT 1 FROM bs_squares_pricing p WHERE p.sid = s.sid OR p.sid IS NULL) "
            . "AND NOT EXISTS (SELECT 1 FROM bs_squares_pricing p "
            . "WHERE (p.sid = s.sid OR p.sid IS NULL) AND p.date_end >= CURDATE()) "
            . "LIMIT 1000"
        );

        $findings = array();

        foreach ($rows as $row) {
            $sid = (int) $row['sid'];
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                sprintf('Platz %s hat keine gültige Preisregel ab heute — Zahlungs-Buttons erscheinen nicht.', $row['name']),
                array('entityType' => 'square', 'entityId' => $sid, 'sid' => $sid,
                      'detail' => array('name' => $row['name']))
            );
        }

        return $findings;
    }

}
