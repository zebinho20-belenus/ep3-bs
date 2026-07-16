<?php

namespace Booking\Service\Diagnostic;

/**
 * Immutable result of a diagnostic check. One Finding = one detected anomaly.
 */
class Finding
{

    const SEVERITY_CRITICAL = 'critical';
    const SEVERITY_WARNING  = 'warning';
    const SEVERITY_INFO     = 'info';

    /** @var string check key, e.g. 'occupancy.double-booking' */
    public $key;

    /** @var string category slug */
    public $category;

    /** @var string one of the SEVERITY_* constants */
    public $severity;

    /** @var string human-readable summary of this specific finding */
    public $title;

    /** @var string|null e.g. 'booking', 'reservation', 'square', 'user', 'option' */
    public $entityType;

    /** @var int|null primary id of the offending entity */
    public $entityId;

    /** @var string|null date (Y-m-d) the anomaly relates to, if any */
    public $date;

    /** @var int|null square id, if any */
    public $sid;

    /** @var int[] involved booking ids */
    public $bids;

    /** @var int[] involved reservation ids */
    public $rids;

    /** @var array free-form structured detail */
    public $detail;

    public function __construct($key, $category, $severity, $title, array $options = array())
    {
        $this->key        = $key;
        $this->category   = $category;
        $this->severity   = $severity;
        $this->title      = $title;
        $this->entityType = isset($options['entityType']) ? $options['entityType'] : null;
        $this->entityId   = isset($options['entityId']) ? $options['entityId'] : null;
        $this->date       = isset($options['date']) ? $options['date'] : null;
        $this->sid        = isset($options['sid']) ? $options['sid'] : null;
        $this->bids       = isset($options['bids']) ? array_values($options['bids']) : array();
        $this->rids       = isset($options['rids']) ? array_values($options['rids']) : array();
        $this->detail     = isset($options['detail']) ? $options['detail'] : array();
    }

    /**
     * Severity rank for sorting/threshold comparison (higher = more severe).
     *
     * @param string $severity
     * @return int
     */
    public static function severityRank($severity)
    {
        switch ($severity) {
            case self::SEVERITY_CRITICAL: return 3;
            case self::SEVERITY_WARNING:  return 2;
            case self::SEVERITY_INFO:     return 1;
            default:                      return 0;
        }
    }

    /**
     * @return array
     */
    public function toArray()
    {
        return array(
            'key'        => $this->key,
            'category'   => $this->category,
            'severity'   => $this->severity,
            'title'      => $this->title,
            'entityType' => $this->entityType,
            'entityId'   => $this->entityId,
            'date'       => $this->date,
            'sid'        => $this->sid,
            'bids'       => $this->bids,
            'rids'       => $this->rids,
            'detail'     => $this->detail,
        );
    }

}
