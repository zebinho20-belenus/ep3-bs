<?php

namespace Booking\Service\Diagnostic\Check;

use Booking\Service\Diagnostic\AbstractCheck;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;

/**
 * Users with upcoming active reservations but no email address. Sending a
 * notification for such a booking used to crash (#99). Users whose status is
 * configured as e-mail-exempt (service.no-email-statuses) are excluded.
 */
class MissingEmailCheck extends AbstractCheck
{

    public function getKey()         { return 'user.missing-email'; }
    public function getCategory()    { return 'user'; }
    public function needsDateRange() { return true; }
    public function getDescription() { return 'Nutzer mit kommenden Buchungen aber ohne E-Mail-Adresse.'; }

    public function run(DiagnosticContext $context)
    {
        if (! ($context->from && $context->to)) {
            return array();
        }

        /* Exclude statuses configured as e-mail-exempt. */
        $exemptRows = $context->fetchAll(
            "SELECT value FROM bs_options WHERE `key` = 'service.no-email-statuses' AND locale IS NULL LIMIT 1"
        );
        $exempt = array();
        if ($exemptRows && $exemptRows[0]['value']) {
            foreach (explode(',', $exemptRows[0]['value']) as $s) {
                $s = trim($s);
                if ($s !== '') {
                    $exempt[] = $s;
                }
            }
        }

        $sql = "SELECT DISTINCT u.uid, u.alias, u.status FROM bs_users u "
            . "JOIN bs_bookings b ON b.uid = u.uid "
            . "JOIN bs_reservations r ON r.bid = b.bid "
            . "WHERE (u.email IS NULL OR u.email = '') "
            . "AND b.status <> 'cancelled' "
            . "AND (r.status IS NULL OR r.status <> 'cancelled') "
            . "AND r.date >= ? AND r.date <= ?";

        $params = array($context->from->format('Y-m-d'), $context->to->format('Y-m-d'));

        if ($exempt) {
            $sql .= ' AND u.status NOT IN (' . implode(',', array_fill(0, count($exempt), '?')) . ')';
            $params = array_merge($params, $exempt);
        }

        $sql .= ' LIMIT 1000';

        $findings = array();

        foreach ($context->fetchAll($sql, $params) as $row) {
            $uid = (int) $row['uid'];
            $findings[] = $this->finding(
                Finding::SEVERITY_WARNING,
                sprintf('Nutzer #%d (%s, Status %s) hat kommende Buchungen aber keine E-Mail-Adresse.',
                    $uid, $row['alias'], $row['status']),
                array('entityType' => 'user', 'entityId' => $uid,
                      'detail' => array('alias' => $row['alias'], 'status' => $row['status']))
            );
        }

        return $findings;
    }

}
