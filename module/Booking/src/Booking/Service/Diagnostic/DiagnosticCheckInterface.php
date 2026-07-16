<?php

namespace Booking\Service\Diagnostic;

/**
 * A single diagnostic check. Each check inspects one class of anomaly and
 * returns zero or more Findings. Checks must be strictly read-only.
 */
interface DiagnosticCheckInterface
{

    /**
     * Unique, stable key in dot notation, e.g. 'occupancy.double-booking'.
     * The part before the first dot is the category.
     *
     * @return string
     */
    public function getKey();

    /**
     * Category slug (used for --checks=<category> filtering).
     *
     * @return string
     */
    public function getCategory();

    /**
     * Short human description for `list-checks`.
     *
     * @return string
     */
    public function getDescription();

    /**
     * Whether this check depends on the context date range (from/to).
     * Range-independent checks always run; range-dependent ones use the range.
     *
     * @return boolean
     */
    public function needsDateRange();

    /**
     * Runs the check.
     *
     * @param DiagnosticContext $context
     * @return Finding[]
     */
    public function run(DiagnosticContext $context);

}
