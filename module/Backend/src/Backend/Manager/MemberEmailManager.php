<?php

namespace Backend\Manager;

use Base\Manager\AbstractManager;
use Backend\Entity\MemberEmail;
use Backend\Entity\MemberEmailFactory;
use Backend\Table\MemberEmailTable;
use RuntimeException;
use InvalidArgumentException;

class MemberEmailManager extends AbstractManager
{

    protected $memberEmailTable;

    public function __construct(MemberEmailTable $memberEmailTable)
    {
        $this->memberEmailTable = $memberEmailTable;
    }

    public function save(MemberEmail $memberEmail)
    {
        if ($memberEmail->get('meid')) {

            $updates = array();

            foreach ($memberEmail->need('updatedProperties') as $property) {
                $updates[$property] = $memberEmail->get($property);
            }

            if ($updates) {
                $this->memberEmailTable->update($updates, array('meid' => $memberEmail->get('meid')));
            }

            $memberEmail->reset();

            $this->getEventManager()->trigger('save.update', $memberEmail);

        } else {

            $this->memberEmailTable->insert(array(
                'email' => $memberEmail->need('email'),
                'firstname' => $memberEmail->get('firstname'),
                'lastname' => $memberEmail->get('lastname'),
            ));

            $meid = $this->memberEmailTable->getLastInsertValue();

            if (! (is_numeric($meid) && $meid > 0)) {
                throw new RuntimeException('Failed to save member email');
            }

            $memberEmail->add('meid', $meid);

            $this->getEventManager()->trigger('save.insert', $memberEmail);
        }

        $this->getEventManager()->trigger('save', $memberEmail);

        return $memberEmail;
    }

    public function get($meid, $strict = true)
    {
        $memberEmail = $this->getBy(array('meid' => $meid));

        if (empty($memberEmail)) {
            if ($strict) {
                throw new RuntimeException('This member email does not exist');
            }

            return null;
        } else {
            return current($memberEmail);
        }
    }

    public function getBy($where, $order = null, $limit = null, $offset = null)
    {
        $select = $this->memberEmailTable->getSql()->select();

        if ($where) {
            $select->where($where);
        }

        if ($order) {
            $select->order($order);
        }

        if ($limit) {
            $select->limit($limit);

            if ($offset) {
                $select->offset($offset);
            }
        }

        $resultSet = $this->memberEmailTable->selectWith($select);

        return MemberEmailFactory::fromResultSet($resultSet);
    }

    public function getByEmail($email)
    {
        $results = $this->getBy(array('email' => strtolower(trim($email))));

        if (empty($results)) {
            return null;
        }

        return current($results);
    }

    public function getAll($order = null, $limit = null, $offset = null)
    {
        return $this->getBy(null, $order, $limit, $offset);
    }

    public function delete($memberEmail)
    {
        if ($memberEmail instanceof MemberEmail) {
            $meid = $memberEmail->need('meid');
        } else {
            $meid = $memberEmail;
        }

        if (! (is_numeric($meid) && $meid > 0)) {
            throw new InvalidArgumentException('Member email id must be numeric');
        }

        $deletion = $this->memberEmailTable->delete(array('meid' => $meid));

        $this->getEventManager()->trigger('delete', $memberEmail);

        return $deletion;
    }

    public function deleteAll()
    {
        return $this->memberEmailTable->delete('1=1');
    }

    public function importFromCsv($content)
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($content));

        if (empty($lines)) {
            return 0;
        }

        // Detect and skip header
        $firstLine = strtolower(trim($lines[0]));
        if (strpos($firstLine, 'email') !== false || strpos($firstLine, 'e-mail') !== false) {
            array_shift($lines);
        }

        $imported = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            // Support semicolon and comma as delimiter
            $delimiter = (strpos($line, ';') !== false) ? ';' : ',';
            $parts = str_getcsv($line, $delimiter);

            $email = isset($parts[0]) ? strtolower(trim($parts[0])) : '';
            $firstname = isset($parts[1]) ? trim($parts[1]) : null;
            $lastname = isset($parts[2]) ? trim($parts[2]) : null;

            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Skip if already exists
            $existing = $this->getByEmail($email);
            if ($existing) {
                // Update name if provided
                if ($firstname || $lastname) {
                    if ($firstname) {
                        $existing->set('firstname', $firstname);
                    }
                    if ($lastname) {
                        $existing->set('lastname', $lastname);
                    }
                    $this->save($existing);
                }
                continue;
            }

            $memberEmail = new MemberEmail(array(
                'email' => $email,
                'firstname' => $firstname,
                'lastname' => $lastname,
            ));

            $this->save($memberEmail);
            $imported++;
        }

        return $imported;
    }

    public function getCount()
    {
        $select = $this->memberEmailTable->getSql()->select();
        $select->columns(array('count' => new \Zend\Db\Sql\Expression('COUNT(*)')));

        $resultSet = $this->memberEmailTable->selectWith($select);
        $row = $resultSet->current();

        return $row ? (int) $row['count'] : 0;
    }

}
