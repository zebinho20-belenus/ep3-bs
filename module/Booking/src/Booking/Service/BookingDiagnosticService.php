<?php

namespace Booking\Service;

use Backend\Service\MailService as BackendMailService;
use Base\Manager\OptionManager;
use Base\Service\AuditService;
use Booking\Manager\BookingManager;
use Booking\Manager\ReservationManager;
use Booking\Service\Diagnostic\CheckRegistry;
use Booking\Service\Diagnostic\DiagnosticContext;
use Booking\Service\Diagnostic\Finding;
use DateTime;
use Square\Manager\SquareManager;
use Square\Manager\SquareOpeningTimesManager;
use Square\Manager\SquarePricingManager;
use User\Manager\UserManager;
use Zend\Db\Adapter\Adapter;

/**
 * Read-only forensic + anomaly-scanning service for bookings.
 *
 * - inspectBooking()/inspectSlot(): reconstruct what a booking/slot looks like
 *   now, including per-reservation overrides and the audit timeline.
 * - scan(): run the registered anomaly checks over an optional date range.
 * - recordAlerts(): (write) log findings to the audit log and e-mail a summary.
 */
class BookingDiagnosticService
{

    protected $registry;
    protected $adapter;
    protected $reservationManager;
    protected $bookingManager;
    protected $squareManager;
    protected $userManager;
    protected $openingTimesManager;
    protected $pricingManager;
    protected $optionManager;
    protected $bookingStatusService;
    protected $auditService;
    protected $backendMailService;
    protected $latestSchemaVersion;

    public function __construct(
        CheckRegistry $registry,
        Adapter $adapter,
        ReservationManager $reservationManager,
        BookingManager $bookingManager,
        SquareManager $squareManager,
        UserManager $userManager,
        SquareOpeningTimesManager $openingTimesManager,
        SquarePricingManager $pricingManager,
        OptionManager $optionManager,
        BookingStatusService $bookingStatusService,
        AuditService $auditService,
        BackendMailService $backendMailService,
        $latestSchemaVersion = null
    ) {
        $this->registry            = $registry;
        $this->adapter             = $adapter;
        $this->reservationManager  = $reservationManager;
        $this->bookingManager      = $bookingManager;
        $this->squareManager       = $squareManager;
        $this->userManager         = $userManager;
        $this->openingTimesManager = $openingTimesManager;
        $this->pricingManager      = $pricingManager;
        $this->optionManager       = $optionManager;
        $this->bookingStatusService = $bookingStatusService;
        $this->auditService        = $auditService;
        $this->backendMailService  = $backendMailService;
        $this->latestSchemaVersion = $latestSchemaVersion;
    }

    /**
     * @return CheckRegistry
     */
    public function getRegistry()
    {
        return $this->registry;
    }

    /**
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @return DiagnosticContext
     */
    protected function buildContext(?DateTime $from = null, ?DateTime $to = null)
    {
        $context = new DiagnosticContext(
            $this->adapter,
            $this->reservationManager,
            $this->bookingManager,
            $this->squareManager,
            $this->userManager,
            $this->openingTimesManager,
            $this->pricingManager,
            $this->optionManager,
            $this->bookingStatusService,
            $from,
            $to
        );

        $context->latestSchemaVersion = $this->latestSchemaVersion;

        return $context;
    }

    /* ------------------------------------------------------------------ */
    /* Scanner                                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Runs the selected checks and returns all findings, most severe first.
     * A check that throws is logged and skipped (graceful degradation).
     *
     * @param array $checkKeys keys/categories to run (empty = all)
     * @param DateTime|null $from
     * @param DateTime|null $to
     * @return Finding[]
     */
    public function scan(array $checkKeys = array(), ?DateTime $from = null, ?DateTime $to = null)
    {
        $context  = $this->buildContext($from, $to);
        $checks   = $this->registry->select($checkKeys);
        $findings = array();

        foreach ($checks as $check) {
            if ($check->needsDateRange() && ! ($context->from && $context->to)) {
                continue;
            }

            try {
                foreach ($check->run($context) as $finding) {
                    $findings[] = $finding;
                }
            } catch (\Exception $e) {
                error_log('[diagnose] check ' . $check->getKey() . ' failed: ' . $e->getMessage());
            }
        }

        usort($findings, function ($a, $b) {
            $rank = Finding::severityRank($b->severity) - Finding::severityRank($a->severity);

            if ($rank !== 0) {
                return $rank;
            }

            return strcmp($a->key, $b->key);
        });

        return $findings;
    }

    /**
     * Filters findings at or above a severity threshold.
     *
     * @param Finding[] $findings
     * @param string $threshold
     * @return Finding[]
     */
    public function filterBySeverity(array $findings, $threshold)
    {
        $min = Finding::severityRank($threshold);

        return array_values(array_filter($findings, function ($f) use ($min) {
            return Finding::severityRank($f->severity) >= $min;
        }));
    }

    /* ------------------------------------------------------------------ */
    /* Alerting (the only writing methods)                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Logs each finding to the audit log and sends one summary e-mail to the
     * configured admin contact. No-op when there are no findings.
     *
     * @param Finding[] $findings
     * @return int number of findings recorded
     */
    public function recordAlerts(array $findings)
    {
        if (! $findings) {
            return 0;
        }

        foreach ($findings as $finding) {
            $this->auditService->log('system', 'anomaly_' . str_replace(array('.', '-'), '_', $finding->key), $finding->title, array(
                'entity_type' => $finding->entityType,
                'entity_id'   => $finding->entityId,
                'detail'      => array(
                    'check'    => $finding->key,
                    'severity' => $finding->severity,
                    'sid'      => $finding->sid,
                    'date'     => $finding->date,
                    'bids'     => $finding->bids,
                    'rids'     => $finding->rids,
                    'info'     => $finding->detail,
                ),
            ));
        }

        $this->sendSummaryMail($findings);

        return count($findings);
    }

    /**
     * @param Finding[] $findings
     */
    protected function sendSummaryMail(array $findings)
    {
        $counts = array(
            Finding::SEVERITY_CRITICAL => 0,
            Finding::SEVERITY_WARNING  => 0,
            Finding::SEVERITY_INFO     => 0,
        );

        foreach ($findings as $f) {
            if (isset($counts[$f->severity])) {
                $counts[$f->severity]++;
            }
        }

        $lines = array();
        $lines[] = sprintf('Der Integritäts-Scan hat %d Auffälligkeit(en) gefunden:', count($findings));
        $lines[] = sprintf('  Kritisch: %d, Warnung: %d, Info: %d',
            $counts[Finding::SEVERITY_CRITICAL], $counts[Finding::SEVERITY_WARNING], $counts[Finding::SEVERITY_INFO]);
        $lines[] = '';

        foreach ($findings as $f) {
            $lines[] = sprintf('[%s] %s — %s', strtoupper($f->severity), $f->key, $f->title);
        }

        try {
            $this->backendMailService->send('Integritäts-Scan: Auffälligkeiten gefunden', implode("\r\n", $lines));
        } catch (\Exception $e) {
            error_log('[diagnose] alert mail failed: ' . $e->getMessage());
        }
    }

    /* ------------------------------------------------------------------ */
    /* Forensic inspectors                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Reconstructs a single booking: properties, meta, reservations (with
     * effective square/billing/quantity), bills and audit timeline.
     *
     * @param int $bid
     * @return array|null
     */
    public function inspectBooking($bid)
    {
        $context = $this->buildContext();
        $bid = (int) $bid;

        $bookingRows = $context->fetchAll('SELECT * FROM bs_bookings WHERE bid = ?', array($bid));

        if (! $bookingRows) {
            return null;
        }

        $booking = $bookingRows[0];
        $meta    = $this->keyValue($context->fetchAll('SELECT `key`, value FROM bs_bookings_meta WHERE bid = ?', array($bid)));

        $reservations = array();
        foreach ($context->fetchAll('SELECT * FROM bs_reservations WHERE bid = ? ORDER BY date, time_start', array($bid)) as $r) {
            $reservations[] = $this->decorateReservation($context, $r, (int) $booking['sid'], $booking['status_billing']);
        }

        $bills = $context->fetchAll('SELECT bbid, description, quantity, time, price, rate, gross FROM bs_bookings_bills WHERE bid = ? ORDER BY bbid', array($bid));

        return array(
            'booking'      => $booking,
            'meta'         => $meta,
            'square'       => $context->squareName((int) $booking['sid']),
            'user'         => $context->userName((int) $booking['uid']),
            'reservations' => $reservations,
            'bills'        => $bills,
            'billTotal'    => array_sum(array_map(function ($b) { return (int) $b['price']; }, $bills)),
            'timeline'     => $this->timeline($context, $bid),
        );
    }

    /**
     * Reconstructs the booking a reservation belongs to, flagging that
     * reservation as the focus. Several checks (time.*, override.*,
     * orphan-reservation) report a reservation id (rid), not a booking id —
     * this resolves the rid to its booking and renders the full context.
     *
     * @param int $rid
     * @return array|null the inspectBooking() payload plus 'focusRid', or null
     */
    public function inspectReservation($rid)
    {
        $context = $this->buildContext();
        $rid = (int) $rid;

        $rows = $context->fetchAll('SELECT bid FROM bs_reservations WHERE rid = ?', array($rid));

        if (! $rows) {
            return null;
        }

        $result = $this->inspectBooking((int) $rows[0]['bid']);

        if ($result !== null) {
            $result['focusRid'] = $rid;
        }

        return $result;
    }

    /**
     * Reconstructs everything occupying an (effective) square on a date, with
     * an audit timeline per involved booking.
     *
     * @param string $date Y-m-d
     * @param int $sid
     * @param string|null $timeStart HH:MM
     * @param string|null $timeEnd HH:MM
     * @return array
     */
    public function inspectSlot($date, $sid, $timeStart = null, $timeEnd = null)
    {
        $context = $this->buildContext();
        $sid = (int) $sid;

        $rows = $context->fetchAll(
            'SELECT r.rid, r.bid, r.date, r.time_start, r.time_end, r.status AS res_status, '
            . 'b.sid AS base_sid, b.status AS booking_status, b.status_billing, b.uid, b.quantity, b.visibility, '
            . "(SELECT value FROM bs_reservations_meta m WHERE m.rid = r.rid AND m.`key` = 'sid_override') AS sid_override, "
            . "(SELECT value FROM bs_reservations_meta m WHERE m.rid = r.rid AND m.`key` = 'quantity_override') AS quantity_override, "
            . "(SELECT value FROM bs_reservations_meta m WHERE m.rid = r.rid AND m.`key` = 'status_billing_override') AS billing_override "
            . 'FROM bs_reservations r JOIN bs_bookings b ON b.bid = r.bid WHERE r.date = ? ORDER BY r.time_start',
            array($date)
        );

        $winStart = $timeStart !== null ? $context->timeToSeconds($timeStart) : null;
        $winEnd   = $timeEnd !== null ? $context->timeToSeconds($timeEnd, true) : null;

        $matches = array();
        $bids    = array();

        foreach ($rows as $row) {
            $effSid = (int) ($row['sid_override'] !== null && $row['sid_override'] !== ''
                ? $row['sid_override'] : $row['base_sid']);

            if ($effSid !== $sid) {
                continue;
            }

            if ($winStart !== null && $winEnd !== null) {
                $rs = $context->timeToSeconds($row['time_start']);
                $re = $context->timeToSeconds($row['time_end'], true);

                if (! ($rs < $winEnd && $re > $winStart)) {
                    continue;
                }
            }

            $row['effective_sid']  = $effSid;
            $row['effective_name'] = $context->squareName($effSid);
            $row['user']           = $context->userName((int) $row['uid']);
            $matches[] = $row;
            $bids[(int) $row['bid']] = true;
        }

        $timelines = array();
        foreach (array_keys($bids) as $b) {
            $timelines[$b] = $this->timeline($context, $b);
        }

        return array(
            'date'         => $date,
            'sid'          => $sid,
            'square'       => $context->squareName($sid),
            'timeStart'    => $timeStart,
            'timeEnd'      => $timeEnd,
            'reservations' => $matches,
            'timelines'    => $timelines,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    protected function decorateReservation(DiagnosticContext $context, array $r, $baseSid, $bookingBilling)
    {
        $rid  = (int) $r['rid'];
        $meta = $this->keyValue($context->fetchAll('SELECT `key`, value FROM bs_reservations_meta WHERE rid = ?', array($rid)));

        $effSid = isset($meta['sid_override']) && $meta['sid_override'] !== '' ? (int) $meta['sid_override'] : (int) $baseSid;

        $r['meta']            = $meta;
        $r['effective_sid']   = $effSid;
        $r['effective_name']  = $context->squareName($effSid);
        $r['effective_billing'] = isset($meta['status_billing_override']) && $meta['status_billing_override'] !== ''
            ? $meta['status_billing_override'] : $bookingBilling;
        $r['moved']           = ($effSid !== (int) $baseSid);

        return $r;
    }

    protected function timeline(DiagnosticContext $context, $bid)
    {
        return $context->fetchAll(
            'SELECT created, user_name, action, message, detail FROM bs_audit_log '
            . "WHERE entity_type = 'booking' AND entity_id = ? ORDER BY created DESC LIMIT 50",
            array((int) $bid)
        );
    }

    protected function keyValue(array $rows)
    {
        $map = array();

        foreach ($rows as $row) {
            $map[$row['key']] = $row['value'];
        }

        return $map;
    }

}
