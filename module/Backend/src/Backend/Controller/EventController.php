<?php

namespace Backend\Controller;

use Event\Entity\Event;
use Event\Table\EventTable;
use Zend\Db\Adapter\Adapter;
use Zend\Mvc\Controller\AbstractActionController;

class EventController extends AbstractActionController
{

    public function indexAction()
    {
        $this->authorize('admin.event');

        $serviceManager = @$this->getServiceLocator();
        $eventManager = $serviceManager->get('Event\Manager\EventManager');

        $dateStartParam = $this->params()->fromQuery('date-start');
        $dateEndParam = $this->params()->fromQuery('date-end');

        $dateStart = null;
        $dateEnd = null;

        $events = array();

        if ($dateStartParam && $dateEndParam) {
            try {
                $dateStart = new \DateTime($dateStartParam);
                $dateStart->setTime(0, 0, 0);

                $dateEnd = new \DateTime($dateEndParam);
                $dateEnd->setTime(23, 59, 59);
            } catch (\Exception $e) {
                throw new \RuntimeException('Invalid date');
            }

            $events = $eventManager->getInRange($dateStart, $dateEnd);

            $eventManager->getSecondsPerDay($events);
        }

        $this->redirectBack()->setOrigin('backend/event');

        return array(
            'dateStart' => $dateStart,
            'dateEnd' => $dateEnd,
            'events' => $events,
        );
    }

    public function editAction()
    {
        $this->authorize('admin.event');

        $serviceManager = @$this->getServiceLocator();
        $eventManager = $serviceManager->get('Event\Manager\EventManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');
        $formElementManager = $serviceManager->get('FormElementManager');

        $eid = $this->params()->fromRoute('eid');

        if ($eid) {
            $event = $eventManager->get($eid);

            $eventManager->getSecondsPerDay($event);
        } else {
            $event = null;
        }

        $editForm = $formElementManager->get('Backend\Form\Event\EditForm');

        if ($this->getRequest()->isPost()) {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirectBack()->toOrigin();
            }

            $editForm->setData($this->params()->fromPost());

            if ($editForm->isValid()) {
                $data = $editForm->getData();

                $locale = $this->config('i18n.locale');

                if ($event) {
                    /* Update existing single event */

                    $event->setMeta('name', $data['ef-name'], $locale);
                    $event->setMeta('description', $data['ef-description'], $locale);

                    $dateStart = new \DateTime($data['ef-date-start']);
                    $timeStartParts = explode(':', $data['ef-time-start']);
                    $dateStart->setTime($timeStartParts[0], $timeStartParts[1], 0);

                    $dateEnd = new \DateTime($data['ef-date-end']);
                    $timeEndParts = explode(':', $data['ef-time-end']);
                    $dateEnd->setTime($timeEndParts[0], $timeEndParts[1], 0);

                    $event->set('datetime_start', $dateStart->format('Y-m-d H:i:s'));
                    $event->set('datetime_end', $dateEnd->format('Y-m-d H:i:s'));

                    $sid = $data['ef-sid'];
                    if ($sid == 'null') {
                        $sid = null;
                    }
                    $event->set('sid', $sid);

                    $capacity = $data['ef-capacity'];
                    if (! $capacity) {
                        $capacity = null;
                    }
                    $event->set('capacity', $capacity);

                    $event->setMeta('notes', $data['ef-notes']);

                    $eventManager->save($event);

                    $this->flashMessenger()->addSuccessMessage('Event has been saved');

                } else {
                    /* Create new event(s) — with square range and optional repeat */

                    $sidFrom = isset($data['ef-sid-from']) ? $data['ef-sid-from'] : 'null';
                    $sidTo = isset($data['ef-sid-to']) ? $data['ef-sid-to'] : 'null';
                    $repeat = isset($data['ef-repeat']) ? (int) $data['ef-repeat'] : 0;

                    /* Determine square list */
                    $allSquares = $squareManager->getAll();
                    $squareIds = array();

                    if ($sidFrom == 'null' || $sidTo == 'null') {
                        /* "All squares" selected on either side → null (all) */
                        $squareIds[] = null;
                    } else {
                        $inRange = false;
                        foreach ($allSquares as $sid => $square) {
                            if ($sid == $sidFrom) {
                                $inRange = true;
                            }
                            if ($inRange) {
                                $squareIds[] = $sid;
                            }
                            if ($sid == $sidTo) {
                                break;
                            }
                        }
                        if (empty($squareIds)) {
                            $squareIds[] = null;
                        }
                    }

                    /* Determine date list */
                    $dateStart = new \DateTime($data['ef-date-start']);
                    $dateStart->setTime(0, 0, 0);
                    $dateEnd = new \DateTime($data['ef-date-end']);
                    $dateEnd->setTime(0, 0, 0);

                    $timeStart = $data['ef-time-start'];
                    $timeEnd = $data['ef-time-end'];
                    $timeStartParts = explode(':', $timeStart);
                    $timeEndParts = explode(':', $timeEnd);

                    $dates = array();
                    if ($repeat == 1) {
                        /* Daily: one event per day */
                        $walkingDate = clone $dateStart;
                        while ($walkingDate <= $dateEnd) {
                            $dates[] = clone $walkingDate;
                            $walkingDate->modify('+1 day');
                        }
                    } else {
                        /* Only once: single event spanning date-start to date-end */
                        $dates[] = null;
                    }

                    /* Conflict check before creation (skip if force=1) */
                    $force = isset($data['ef-force']) && $data['ef-force'] == '1';

                    if (! $force) {
                        $conflicts = array();

                        /* Calculate overall time range for conflict query */
                        $rangeStart = new \DateTime($data['ef-date-start']);
                        $rangeStart->setTime($timeStartParts[0], $timeStartParts[1], 0);
                        $rangeEnd = new \DateTime($data['ef-date-end']);
                        $rangeEnd->setTime($timeEndParts[0], $timeEndParts[1], 0);

                        /* "All squares" → match any sid */
                        $allSquaresSelected = in_array(null, $squareIds, true);

                        /* Check for conflicting bookings */
                        $reservationManager = $serviceManager->get('Booking\Manager\ReservationManager');
                        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');

                        $reservations = $reservationManager->getInRange($rangeStart, $rangeEnd);

                        if ($reservations) {
                            $bookingManager->getByReservations($reservations);

                            foreach ($reservations as $reservation) {
                                $booking = $reservation->getExtra('booking');

                                if (! $booking || $booking->get('status') == 'cancelled') {
                                    continue;
                                }

                                $bookingSid = $booking->get('sid');

                                if (! $allSquaresSelected && ! in_array($bookingSid, $squareIds)) {
                                    continue;
                                }

                                $squareName = isset($allSquares[$bookingSid])
                                    ? $allSquares[$bookingSid]->get('name')
                                    : '?';

                                $userName = $booking->getExtra('user')
                                    ? $booking->getExtra('user')->get('alias')
                                    : 'Booking #' . $booking->get('bid');

                                $conflicts[] = array(
                                    'type' => 'booking',
                                    'name' => $userName,
                                    'square' => $squareName,
                                    'date' => $reservation->get('date'),
                                    'time' => substr($reservation->get('time_start'), 0, 5) . '–' . substr($reservation->get('time_end'), 0, 5),
                                );
                            }
                        }

                        /* Check for conflicting events */
                        $existingEvents = $eventManager->getInRange($rangeStart, $rangeEnd);

                        if ($existingEvents) {
                            foreach ($existingEvents as $existingEvent) {
                                $eventSid = $existingEvent->get('sid');

                                if (! $allSquaresSelected && $eventSid !== null && ! in_array($eventSid, $squareIds)) {
                                    continue;
                                }

                                $squareName = ($eventSid === null)
                                    ? $this->translate('All squares')
                                    : (isset($allSquares[$eventSid]) ? $allSquares[$eventSid]->get('name') : '?');

                                $evtStart = new \DateTime($existingEvent->need('datetime_start'));
                                $evtEnd = new \DateTime($existingEvent->need('datetime_end'));

                                $conflicts[] = array(
                                    'type' => 'event',
                                    'name' => $existingEvent->getMeta('name') ?: '?',
                                    'square' => $squareName,
                                    'date' => $evtStart->format('d.m.Y'),
                                    'time' => $evtStart->format('H:i') . '–' . $evtEnd->format('H:i'),
                                );
                            }
                        }

                        if ($conflicts) {
                            /* Re-render form with conflicts — set force to 1 for next submit */
                            $editForm->get('ef-force')->setValue('1');

                            return array(
                                'event' => $event,
                                'editForm' => $editForm,
                                'conflicts' => $conflicts,
                            );
                        }
                    }

                    $groupId = uniqid('evtgrp_', true);
                    $capacity = $data['ef-capacity'];
                    if (! $capacity) {
                        $capacity = null;
                    }

                    /* Use transaction for batch creation */
                    $connection = $serviceManager->get('Zend\Db\Adapter\Adapter')
                        ->getDriver()->getConnection();
                    $connection->beginTransaction();

                    try {
                        $count = 0;

                        foreach ($dates as $date) {
                            foreach ($squareIds as $sid) {
                                $newEvent = new Event();

                                $newEvent->setMeta('name', $data['ef-name'], $locale);
                                $newEvent->setMeta('description', $data['ef-description'], $locale);

                                if ($date !== null) {
                                    /* Daily repeat: same time on each day */
                                    $evtStart = clone $date;
                                    $evtStart->setTime($timeStartParts[0], $timeStartParts[1], 0);
                                    $evtEnd = clone $date;
                                    $evtEnd->setTime($timeEndParts[0], $timeEndParts[1], 0);
                                } else {
                                    /* Single event spanning full range */
                                    $evtStart = new \DateTime($data['ef-date-start']);
                                    $evtStart->setTime($timeStartParts[0], $timeStartParts[1], 0);
                                    $evtEnd = new \DateTime($data['ef-date-end']);
                                    $evtEnd->setTime($timeEndParts[0], $timeEndParts[1], 0);
                                }

                                $newEvent->set('datetime_start', $evtStart->format('Y-m-d H:i:s'));
                                $newEvent->set('datetime_end', $evtEnd->format('Y-m-d H:i:s'));
                                $newEvent->set('sid', $sid);
                                $newEvent->set('capacity', $capacity);
                                $newEvent->setMeta('notes', $data['ef-notes']);
                                $newEvent->setMeta('group', $groupId);

                                $eventManager->save($newEvent);
                                $count++;
                            }
                        }

                        $connection->commit();

                        $this->flashMessenger()->addSuccessMessage(sprintf(
                            $this->translate('%s event(s) have been created'), $count
                        ));

                    } catch (\Exception $e) {
                        $connection->rollback();
                        throw $e;
                    }
                }

                return $this->redirectBack()->toOrigin();
            }
        } else {
            if ($event) {
                $editForm->setData(array(
                    'ef-name' => $event->getMeta('name'),
                    'ef-description' => $event->getMeta('description'),
                    'ef-date-start' => $this->dateFormat($event->needExtra('datetime_start'), \IntlDateFormatter::MEDIUM),
                    'ef-time-start' => $event->needExtra('datetime_start')->format('H:i'),
                    'ef-date-end' => $this->dateFormat($event->needExtra('datetime_end'), \IntlDateFormatter::MEDIUM),
                    'ef-time-end' => $event->needExtra('datetime_end')->format('H:i'),
                    'ef-sid' =>  $event->get('sid'),
                    'ef-capacity' =>  $event->get('capacity', 0),
                    'ef-notes' =>  $event->getMeta('notes'),
                ));
            } else {
                /* Default dates: today, default times: 18:00–21:00 */
                $dateStartParam = $this->params()->fromQuery('ds');
                $dateEndParam = $this->params()->fromQuery('de');
                $timeStartParam = $this->params()->fromQuery('ts');
                $timeEndParam = $this->params()->fromQuery('te');
                $squareParam = $this->params()->fromQuery('s');

                $dateTimeStart = new \DateTime($dateStartParam ?: 'now');
                $dateTimeEnd = new \DateTime($dateEndParam ?: 'now');

                if ($timeStartParam && preg_match('/^[0-9]?[0-9]:[0-9][0-9]$/', $timeStartParam)) {
                    $tsParts = explode(':', $timeStartParam);
                    $dateTimeStart->setTime($tsParts[0], $tsParts[1]);
                } else {
                    $dateTimeStart->setTime(18, 0);
                }

                if ($timeEndParam && preg_match('/^[0-9]?[0-9]:[0-9][0-9]$/', $timeEndParam)) {
                    $teParts = explode(':', $timeEndParam);
                    $dateTimeEnd->setTime($teParts[0], $teParts[1]);
                } else {
                    $dateTimeEnd->setTime(21, 0);
                }

                $defaultSid = $squareParam ?: 'null';

                $editForm->setData(array(
                    'ef-date-start' => $this->dateFormat($dateTimeStart, \IntlDateFormatter::MEDIUM),
                    'ef-time-start' => $dateTimeStart->format('H:i'),
                    'ef-date-end' => $this->dateFormat($dateTimeEnd, \IntlDateFormatter::MEDIUM),
                    'ef-time-end' => $dateTimeEnd->format('H:i'),
                    'ef-sid' => $defaultSid,
                    'ef-sid-from' => $defaultSid,
                    'ef-sid-to' => $defaultSid,
                    'ef-capacity' => 0,
                ));
            }
        }

        return array(
            'event' => $event,
            'editForm' => $editForm,
        );
    }

    public function editChoiceAction()
    {
        $this->authorize('admin.event');

        $params = $this->backendBookingDetermineParams();

        $serviceManager = @$this->getServiceLocator();
        $eventManager = $serviceManager->get('Event\Manager\EventManager');

        $events = $eventManager->getInRange($params['dateTimeStart'], $params['dateTimeEnd']);

        $eventManager->getSecondsPerDay($events);

        return $this->ajaxViewModel(array(
            'events' => $events,
        ));
    }

    public function deleteAction()
    {
        $this->authorize('admin.event');

        $serviceManager = @$this->getServiceLocator();
        $eventManager = $serviceManager->get('Event\Manager\EventManager');

        $eid = $this->params()->fromRoute('eid');

        $event = $eventManager->get($eid);

        $groupId = $event->getMeta('group');

        $confirmed = $this->params()->fromPost('confirmed');

        if ($confirmed == 'true') {
            if (! $this->CsrfProtection()->validate($this->params()->fromPost('csrf_token'))) {
                $this->flashMessenger()->addErrorMessage('Invalid security token. Please try again.');
                return $this->redirectBack()->toOrigin();
            }

            if ($this->params()->fromPost('group') == 'true' && $groupId) {
                /* Delete entire series */
                $eventMetaTable = $serviceManager->get('Event\Table\EventMetaTable');

                $groupEvents = $eventMetaTable->select(array(
                    'key' => 'group',
                    'value' => $groupId,
                ));

                $connection = $serviceManager->get('Zend\Db\Adapter\Adapter')
                    ->getDriver()->getConnection();
                $connection->beginTransaction();

                try {
                    $count = 0;
                    foreach ($groupEvents as $row) {
                        $eventManager->delete($row['eid']);
                        $count++;
                    }
                    $connection->commit();

                    $this->flashMessenger()->addSuccessMessage(sprintf(
                        $this->translate('%s event(s) have been deleted'), $count
                    ));
                } catch (\Exception $e) {
                    $connection->rollback();
                    throw $e;
                }
            } else {
                /* Delete single event */
                $eventManager->delete($event);

                $this->flashMessenger()->addSuccessMessage('Event has been deleted');
            }

            return $this->redirectBack()->toOrigin();
        }

        return array(
            'event' => $event,
            'groupId' => $groupId,
        );
    }

    public function statsAction()
    {
        $this->authorize('admin.event');

        $db = @$this->getServiceLocator()->get('Zend\Db\Adapter\Adapter');

        $stats = $db->query(sprintf('SELECT status, COUNT(status) AS count FROM %s GROUP BY status', EventTable::NAME),
            Adapter::QUERY_MODE_EXECUTE)->toArray();

        return array(
            'stats' => $stats,
        );
    }

}
