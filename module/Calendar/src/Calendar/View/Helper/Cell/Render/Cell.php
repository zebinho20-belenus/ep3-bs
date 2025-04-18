<?php

namespace Calendar\View\Helper\Cell\Render;

use Zend\View\Helper\AbstractHelper;
use Base\Manager\OptionManager;

class Cell extends AbstractHelper
{

    public function __construct(OptionManager $optionManager)
    {
        $this->optionManager = $optionManager;
    }

    public function __invoke($walkingDate, $walkingTime, $timeBlock, $square, $user, $reservationsForCell, $eventsForCell)
    {
        $view = $this->getView();

        $cellLinkParams = ['query' => [
            'ds' => $walkingDate->format('Y-m-d'),
            'ts' => gmdate('H:i', $walkingTime),
            'te' => gmdate('H:i', $walkingTime + $timeBlock),
            's' => $square->need('sid'),
        ]];

        if ($cellLinkParams['query']['te'] == '00:00') {
            $cellLinkParams['query']['te'] = '24:00';
        }

        /* Check events */

        if ($eventsForCell) {
            if (count($eventsForCell) > 1 || count($reservationsForCell) > 0) {
                return $view->calendarCellRenderEventConflict($user, $eventsForCell, $reservationsForCell, $cellLinkParams);
            } else {
                $event = current($eventsForCell);
                return $view->calendarCellRenderEvent($user, $event, $cellLinkParams);
            }
        }

        /* Check bookings */

        $capacity = $square->need('capacity');
        $capacityHeterogenic = $square->need('capacity_heterogenic');

        $quantity = 0;

        $userBooking = null;

        foreach ($reservationsForCell as $reservation) {
            $booking = $reservation->needExtra('booking');
            $quantity += $booking->need('quantity');

            if ($user && $user->need('uid') == $booking->need('uid')) {
                $userBooking = $booking;
            }
        }

        if ($capacity > $quantity) {
            if ($quantity && ! $capacityHeterogenic) {
                $cellFree = false;
            } else {
                $cellFree = true;
            }
        } else {
            $cellFree = false;
        }

        if ($capacity - $quantity < 0) {
            if ($user && $user->can('calendar.see-data')) {
                return $view->calendarCellLink($this->view->t('Conflict'), $view->url('backend/booking/edit', [], $cellLinkParams), 'cc-conflict');
            }
        }

        /* Check for club reserved time blocks in club-exception days*/
        $cellReserved = false;

        $clubExceptions = $this->optionManager->get('service.calendar.club-exceptions');
        $displayClubExceptions = $this->optionManager->get('service.calendar.display-club-exceptions');

        if ($clubExceptions) {
            $clubExceptions = preg_split('~(\\n|,)~', $clubExceptions);
            $clubExceptionsExceptions = [];

            $clubExceptionsCleaned = [];

            foreach ($clubExceptions as $clubException) {
                $clubException = trim($clubException);

                if ($clubException) {
                    if ($clubException[0] === '+') {
                        $clubExceptionsExceptions[] = trim($clubException, '+');
                    } else {
                        $clubExceptionsCleaned[] = $clubException;
                    }
                }
            }

            $clubExceptions = $clubExceptionsCleaned;

            // syslog(LOG_EMERG, '|'.$timeBlock.'|');
            // syslog(LOG_EMERG, '|'.$walkingDate->format('Y-m-d').'|');

            if (in_array($walkingDate->format('Y-m-d'), $clubExceptions) ||
                in_array($walkingDate->format('l'), $clubExceptions)) {

                // syslog(LOG_EMERG, '|'.$walkingDate->format($view->t('Y-m-d')).'|');

                if (!in_array($walkingDate->format('Y-m-d'), $clubExceptionsExceptions)) {
                    // clone is important to  not modify the origin walkingDate
                    $resTimeStart = clone $walkingDate;
                    $resTimeEnd = clone $walkingDate;
                    $timeStart = clone $walkingDate;
                    $timeEnd = clone $walkingDate;
                    $timeBlockMinutes = $timeBlock/60;
                    $timeEnd = $timeEnd->modify("+{$timeBlockMinutes} minutes");
                    
                    $resTimeStartParam = $square->getMeta('club_reserved_time_start');
                    $resTimeEndParam = $square->getMeta('club_reserved_time_end');
                    $resTimeStartParts = explode(':', $resTimeStartParam);
                    $resTimeEndParts = explode(':', $resTimeEndParam);

                    $resTimeStart->setTime($resTimeStartParts[0], $resTimeStartParts[1]);
                    $resTimeEnd->setTime($resTimeEndParts[0], $resTimeEndParts[1]);

                    if ( (($timeStart >= $resTimeStart) && ($timeStart < $resTimeEnd))
                          || (($timeEnd > $resTimeStart) && ($timeEnd <= $resTimeEnd)) ) {
                              $cellReserved = true;
                              // syslog(LOG_EMERG, '|'.$cellReserved.'|');
                   }
                }
            }
        }

        if ($cellFree) {
            if ($cellReserved && $displayClubExceptions && ($user && !$user->getMeta('member'))) {
                return $view->calendarCellRenderReserved($user, $userBooking, $reservationsForCell, $cellLinkParams, $square);   
            } else {             
                return $view->calendarCellRenderFree($user, $userBooking, $reservationsForCell, $cellLinkParams, $square);
            }    
        } else {
            return $view->calendarCellRenderOccupied($user, $userBooking, $reservationsForCell, $cellLinkParams, $square);
        }
    }

}
