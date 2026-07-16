<?php

namespace Booking\Service\Diagnostic;

use Base\Manager\OptionManager;
use Booking\Manager\BookingManager;
use Booking\Manager\ReservationManager;
use DateTime;
use Square\Manager\SquareManager;
use Square\Manager\SquareOpeningTimesManager;
use Square\Manager\SquarePricingManager;
use User\Manager\UserManager;
use Zend\Db\Adapter\Adapter;

/**
 * Shared, lazily-populated context handed to every diagnostic check.
 *
 * Caches expensive shared data (squares, in-range reservations, user names)
 * so individual checks do not re-query the same rows. Strictly read-only.
 */
class DiagnosticContext
{

    /** @var DateTime|null */
    public $from;

    /** @var DateTime|null */
    public $to;

    /** @var int|null latest migration version from the registry (for pending-migration check) */
    public $latestSchemaVersion = null;

    protected $adapter;
    protected $reservationManager;
    protected $bookingManager;
    protected $squareManager;
    protected $userManager;
    protected $openingTimesManager;
    protected $pricingManager;
    protected $optionManager;

    /** @var array|null cache: sid => Square */
    protected $squaresCache = null;

    /** @var array|null cache: reservations in [from,to] with 'booking' extra attached */
    protected $reservationsInRangeCache = null;

    /** @var array cache: uid => display name */
    protected $userNameCache = array();

    public function __construct(
        Adapter $adapter,
        ReservationManager $reservationManager,
        BookingManager $bookingManager,
        SquareManager $squareManager,
        UserManager $userManager,
        SquareOpeningTimesManager $openingTimesManager,
        SquarePricingManager $pricingManager,
        OptionManager $optionManager,
        ?DateTime $from = null,
        ?DateTime $to = null
    ) {
        $this->adapter             = $adapter;
        $this->reservationManager  = $reservationManager;
        $this->bookingManager      = $bookingManager;
        $this->squareManager       = $squareManager;
        $this->userManager         = $userManager;
        $this->openingTimesManager = $openingTimesManager;
        $this->pricingManager      = $pricingManager;
        $this->optionManager       = $optionManager;
        $this->from                = $from;
        $this->to                  = $to;
    }

    /* ------------------------------------------------------------------ */
    /* Manager accessors                                                   */
    /* ------------------------------------------------------------------ */

    public function reservationManager()  { return $this->reservationManager; }
    public function bookingManager()      { return $this->bookingManager; }
    public function squareManager()       { return $this->squareManager; }
    public function userManager()         { return $this->userManager; }
    public function openingTimesManager() { return $this->openingTimesManager; }
    public function pricingManager()      { return $this->pricingManager; }
    public function optionManager()       { return $this->optionManager; }

    /* ------------------------------------------------------------------ */
    /* Query helper                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Runs a read-only SQL query and returns rows as associative arrays.
     *
     * @param string $sql
     * @param array $params optional bound parameters
     * @return array[]
     */
    public function fetchAll($sql, array $params = array())
    {
        if ($params) {
            $result = $this->adapter->query($sql, $params);
        } else {
            $result = $this->adapter->query($sql, Adapter::QUERY_MODE_EXECUTE);
        }

        $rows = array();

        foreach ($result as $row) {
            $rows[] = (array) $row;
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /* Cached shared data                                                  */
    /* ------------------------------------------------------------------ */

    /**
     * All squares keyed by sid.
     *
     * @return array sid => Square
     */
    public function squares()
    {
        if ($this->squaresCache === null) {
            $this->squaresCache = $this->squareManager->getAll();
        }

        return $this->squaresCache;
    }

    /**
     * Human-readable square label ("Platz <name>") or "#<sid>" if unknown.
     *
     * @param int $sid
     * @return string
     */
    public function squareName($sid)
    {
        $squares = $this->squares();

        if (isset($squares[$sid])) {
            return 'Platz ' . $squares[$sid]->get('name', $sid);
        }

        return '#' . $sid;
    }

    /**
     * Resolves a user display name (alias) with caching.
     *
     * @param int $uid
     * @return string
     */
    public function userName($uid)
    {
        if (! array_key_exists($uid, $this->userNameCache)) {
            $name = '#' . $uid;

            try {
                $user = $this->userManager->get($uid, false);

                if ($user) {
                    $name = $user->get('alias', '#' . $uid);
                }
            } catch (\Exception $e) {
                /* leave fallback */
            }

            $this->userNameCache[$uid] = $name;
        }

        return $this->userNameCache[$uid];
    }

    /**
     * Reservations within [from, to] with their booking attached as the
     * 'booking' extra. Loaded once and shared across range-dependent checks.
     * Includes cancelled reservations/bookings — checks filter as needed.
     *
     * @return array rid => Reservation
     */
    public function reservationsInRange()
    {
        if ($this->reservationsInRangeCache === null) {
            if (! ($this->from && $this->to)) {
                $this->reservationsInRangeCache = array();

                return $this->reservationsInRangeCache;
            }

            $reservations = $this->reservationManager->getInRange($this->from, $this->to, null, null, true);

            /* Attach bookings (no where filter → keep all, just cross-link) */
            $this->bookingManager->getByReservations($reservations);

            $this->reservationsInRangeCache = $reservations;
        }

        return $this->reservationsInRangeCache;
    }

    /**
     * The effective square id of a reservation: sid_override, else booking sid.
     *
     * @param \Booking\Entity\Reservation $reservation
     * @param \Booking\Entity\Booking $booking
     * @return int
     */
    public function effectiveSid($reservation, $booking)
    {
        return (int) ($reservation->getMeta('sid_override') ?: $booking->need('sid'));
    }

    /**
     * The effective quantity of a reservation: quantity_override, else booking quantity.
     *
     * @param \Booking\Entity\Reservation $reservation
     * @param \Booking\Entity\Booking $booking
     * @return int
     */
    public function effectiveQuantity($reservation, $booking)
    {
        return (int) ($reservation->getMeta('quantity_override') ?: $booking->need('quantity'));
    }

    /**
     * Converts a HH:MM[:SS] time string to seconds since midnight, treating
     * '00:00' end-of-day as 86400 (matches ReservationManager::getSecondsPerDay).
     *
     * @param string $time
     * @param boolean $isEnd
     * @return int
     */
    public function timeToSeconds($time, $isEnd = false)
    {
        $parts = explode(':', $time);
        $seconds = ((int) $parts[0]) * 3600 + (isset($parts[1]) ? (int) $parts[1] * 60 : 0)
            + (isset($parts[2]) ? (int) $parts[2] : 0);

        if ($isEnd && $seconds === 0) {
            return 86400;
        }

        return $seconds;
    }

}
