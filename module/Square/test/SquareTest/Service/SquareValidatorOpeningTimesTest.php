<?php

namespace SquareTest\Service;

use Square\Entity\Square;
use Square\Service\SquareValidator;

class SquareValidatorOpeningTimesTest extends \PHPUnit_Framework_TestCase
{

    /**
     * Creates a real Square entity with the given time_start/time_end defaults.
     *
     * @param string $timeStart Default square opening time (e.g. '08:00')
     * @param string $timeEnd   Default square closing time (e.g. '22:00')
     * @return Square
     */
    protected function createSquare($timeStart = '08:00', $timeEnd = '22:00')
    {
        return new Square(array(
            'sid' => 1,
            'name' => 'Test Court',
            'status' => 'enabled',
            'priority' => 1,
            'capacity' => 1,
            'capacity_heterogenic' => 0,
            'allow_notes' => 0,
            'time_start' => $timeStart,
            'time_end' => $timeEnd,
            'time_block' => 3600,
            'time_block_bookable' => 0,
            'time_block_bookable_max' => 0,
            'min_range_book' => 0,
            'range_book' => 0,
            'max_active_bookings' => 0,
            'range_cancel' => 0,
        ));
    }

    /**
     * Creates a SquareValidator with mocked dependencies.
     *
     * @param array|null $openingTimesReturn Return value for getOpeningTimes()
     * @param string $squareTimeStart Default square time_start
     * @param string $squareTimeEnd Default square time_end
     * @return SquareValidator
     */
    protected function createValidator($openingTimesReturn, $squareTimeStart = '08:00', $squareTimeEnd = '22:00')
    {
        $bookingManager = $this->getMockBuilder('Booking\Manager\BookingManager')
            ->disableOriginalConstructor()
            ->getMock();

        $reservationManager = $this->getMockBuilder('Booking\Manager\ReservationManager')
            ->disableOriginalConstructor()
            ->getMock();

        $eventManager = $this->getMockBuilder('Event\Manager\EventManager')
            ->disableOriginalConstructor()
            ->getMock();

        $square = $this->createSquare($squareTimeStart, $squareTimeEnd);

        $squareManager = $this->getMockBuilder('Square\Manager\SquareManager')
            ->disableOriginalConstructor()
            ->getMock();
        $squareManager->method('get')->willReturn($square);

        $userSessionManager = $this->getMockBuilder('User\Manager\UserSessionManager')
            ->disableOriginalConstructor()
            ->getMock();
        $userSessionManager->method('getSessionUser')->willReturn(null);

        $optionManager = $this->getMockBuilder('Base\Manager\OptionManager')
            ->disableOriginalConstructor()
            ->getMock();
        $optionManager->method('get')->willReturn(null);

        $openingTimesManager = $this->getMockBuilder('Square\Manager\SquareOpeningTimesManager')
            ->disableOriginalConstructor()
            ->getMock();
        $openingTimesManager->method('getOpeningTimes')->willReturn($openingTimesReturn);

        return new SquareValidator(
            $bookingManager,
            $reservationManager,
            $eventManager,
            $squareManager,
            $userSessionManager,
            $optionManager,
            $openingTimesManager
        );
    }

    /**
     * Returns a date string far enough in the future to pass time-range validation.
     */
    protected function futureDate()
    {
        $date = new \DateTime('+1 day');
        return $date->format('Y-m-d');
    }

    // ─── Test: No override, booking within default square times ───

    public function testNoOverrideWithinDefaultTimesIsValid()
    {
        $validator = $this->createValidator(null, '08:00', '22:00');
        $date = $this->futureDate();

        $result = $validator->isValid($date, $date, '09:00', '10:00', 1);

        $this->assertArrayHasKey('dateStart', $result);
        $this->assertArrayHasKey('square', $result);
    }

    // ─── Test: No override, booking before default square start ───

    public function testNoOverrideBeforeDefaultStartThrows()
    {
        $validator = $this->createValidator(null, '08:00', '22:00');
        $date = $this->futureDate();

        $this->setExpectedException('RuntimeException', 'The passed time range is invalid');
        $validator->isValid($date, $date, '07:00', '09:00', 1);
    }

    // ─── Test: Override active, booking within override times ───

    public function testOverrideActiveWithinOverrideTimesIsValid()
    {
        $openingTimes = array('time_start' => '10:00', 'time_end' => '18:00');
        $validator = $this->createValidator($openingTimes, '08:00', '22:00');
        $date = $this->futureDate();

        $result = $validator->isValid($date, $date, '11:00', '12:00', 1);

        $this->assertArrayHasKey('dateStart', $result);
    }

    // ─── Test: Override active, booking outside override times ───

    public function testOverrideActiveOutsideOverrideTimesThrows()
    {
        $openingTimes = array('time_start' => '10:00', 'time_end' => '18:00');
        $validator = $this->createValidator($openingTimes, '08:00', '22:00');
        $date = $this->futureDate();

        $this->setExpectedException('RuntimeException', 'The passed time range is invalid');
        $validator->isValid($date, $date, '09:00', '11:00', 1);
    }

    // ─── Test: Override active, booking exactly at override boundaries ───

    public function testOverrideActiveExactBoundariesIsValid()
    {
        $openingTimes = array('time_start' => '10:00', 'time_end' => '18:00');
        $validator = $this->createValidator($openingTimes, '08:00', '22:00');
        $date = $this->futureDate();

        $result = $validator->isValid($date, $date, '10:00', '18:00', 1);

        $this->assertArrayHasKey('dateStart', $result);
    }

}