<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Per-reservation status_billing_override holding a value outside the known
 * billing-status domain.
 */
class BillingOverrideCheck extends AbstractCheck
{

    protected static $allowed = array('pending', 'paid', 'cancelled', 'uncollectable', 'member', 'training', 'free');

    public function getKey()         { return 'override.billing-invalid'; }
    public function getDescription() { return 'status_billing_override mit unbekanntem Wert.'; }

    public function run(DiagnosticContext $context)
    {
        $rows = $context->fetchAll(
            'SELECT m.rid, r.bid, r.date, m.value AS v FROM bs_reservations_meta m '
            . 'JOIN bs_reservations r ON r.rid = m.rid '
            . "WHERE m.`key` = 'status_billing_override' LIMIT 2000"
        );

        $findings = array();

        foreach ($rows as $row) {
            if (! in_array($row['v'], self::$allowed, true)) {
                $rid = (int) $row['rid'];
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('Reservierung #%d: status_billing_override="%s" ist kein bekannter Status.', $rid, $row['v']),
                    array('entityType' => 'reservation', 'entityId' => $rid, 'date' => $row['date'],
                          'bids' => array((int) $row['bid']), 'rids' => array($rid),
                          'detail' => array('value' => $row['v']))
                );
            }
        }

        return $findings;
    }

}
