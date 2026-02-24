<?php

namespace Square\Manager;

use Base\Manager\AbstractManager;
use DateTime;
use InvalidArgumentException;
use Square\Table\SquareOpeningTimesTable;
use Zend\Db\Adapter\Adapter;

class SquareOpeningTimesManager extends AbstractManager
{

    protected $squareOpeningTimesTable;
    protected $squareManager;

    protected $rules = array();

    /**
     * Creates a new square opening times manager object.
     *
     * Preloads all available opening time rules from the database.
     *
     * @param SquareOpeningTimesTable $squareOpeningTimesTable
     * @param SquareManager $squareManager
     */
    public function __construct(SquareOpeningTimesTable $squareOpeningTimesTable, SquareManager $squareManager)
    {
        $this->squareOpeningTimesTable = $squareOpeningTimesTable;
        $this->squareManager = $squareManager;

        $select = $squareOpeningTimesTable->getSql()->select();
        $select->order('priority ASC');

        foreach ($squareOpeningTimesTable->selectWith($select) as $result) {
            $this->rules[] = $result;
        }
    }

    /**
     * Creates a new opening time rule set.
     *
     * This will always truncate the table and write all rules again.
     *
     * @param array $rules
     * @return array
     */
    public function create(array $rules = array())
    {
        $connection = $this->squareOpeningTimesTable->getAdapter()->getDriver()->getConnection();

        if (! $connection->inTransaction()) {
            $connection->beginTransaction();
            $transaction = true;
        } else {
            $transaction = false;
        }

        try {
            $adapter = $this->squareOpeningTimesTable->getAdapter();
            $adapter->query('DELETE FROM ' . SquareOpeningTimesTable::NAME, Adapter::QUERY_MODE_EXECUTE);

            $statement = $adapter->query('INSERT INTO ' . SquareOpeningTimesTable::NAME . ' (sid, priority, date_start, date_end, time_start, time_end) VALUES (?, ?, ?, ?, ?, ?)', Adapter::QUERY_MODE_PREPARE);

            foreach ($rules as $rule) {
                if (count($rule) != 6) {
                    throw new InvalidArgumentException('Opening time rules are not well formed internally');
                }

                $statement->execute($rule);
            }

            if ($transaction) {
                $connection->commit();
            }

            $this->getEventManager()->trigger('create', $rules);

            return $rules;

        } catch (\Exception $e) {
            if ($transaction) {
                $connection->rollback();
            }

            throw $e;
        }
    }

    /**
     * Gets all opening time rules.
     *
     * @return array
     */
    public function getAll()
    {
        return $this->rules;
    }

    /**
     * Determines the opening times for the passed date and square.
     *
     * Returns an array with 'time_start' and 'time_end' keys, or null if no rule matches
     * (fallback to bs_squares.time_start/time_end).
     *
     * @param DateTime $date
     * @param int $squareId
     * @return array|null
     */
    public function getOpeningTimes(DateTime $date, $squareId)
    {
        foreach ($this->rules as $rule) {
            $dateStart = new DateTime($rule['date_start']);
            $dateEnd = new DateTime($rule['date_end']);
            $dateEnd->setTime(23, 59, 59);

            if ($date >= $dateStart && $date <= $dateEnd) {
                if (is_null($rule['sid']) || $rule['sid'] == $squareId) {
                    return array(
                        'time_start' => $rule['time_start'],
                        'time_end' => $rule['time_end'],
                    );
                }
            }
        }

        return null;
    }

}
