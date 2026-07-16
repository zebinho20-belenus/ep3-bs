<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Meta rows whose parent entity no longer exists (dangling foreign key).
 * Should be prevented by ON DELETE CASCADE, but migrated databases may lack
 * the constraints, so orphaned meta can exist in practice.
 */
class OrphanMetaCheck extends AbstractCheck
{

    /** table => [fk column, parent table, parent pk] */
    protected static $metaTables = array(
        'bs_bookings_meta'      => array('bid', 'bs_bookings',      'bid'),
        'bs_reservations_meta'  => array('rid', 'bs_reservations',  'rid'),
        'bs_users_meta'         => array('uid', 'bs_users',         'uid'),
        'bs_squares_meta'       => array('sid', 'bs_squares',       'sid'),
        'bs_squares_pricing'    => array('sid', 'bs_squares',       'sid'),
        'bs_squares_opening_times' => array('sid', 'bs_squares',    'sid'),
    );

    public function getKey()         { return 'ref.orphan-meta'; }
    public function getDescription() { return 'Meta-/Konfigurationszeilen ohne zugehörigen Datensatz.'; }

    public function run(DiagnosticContext $context)
    {
        $findings = array();

        foreach (self::$metaTables as $table => $def) {
            list($fk, $parent, $pk) = $def;

            $rows = $context->fetchAll(
                sprintf(
                    'SELECT COUNT(*) AS cnt FROM %s c LEFT JOIN %s p ON p.%s = c.%s WHERE p.%s IS NULL',
                    $table, $parent, $pk, $fk, $pk
                )
            );

            $count = isset($rows[0]['cnt']) ? (int) $rows[0]['cnt'] : 0;

            if ($count > 0) {
                $findings[] = $this->finding(
                    Finding::SEVERITY_WARNING,
                    sprintf('%d verwaiste Zeile(n) in %s (kein zugehöriger %s.%s).', $count, $table, $parent, $pk),
                    array(
                        'entityType' => 'meta',
                        'detail'     => array('table' => $table, 'count' => $count, 'parent' => $parent),
                    )
                );
            }
        }

        return $findings;
    }

}
