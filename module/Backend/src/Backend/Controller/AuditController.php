<?php

namespace Backend\Controller;

use Zend\Mvc\Controller\AbstractActionController;

class AuditController extends AbstractActionController
{

    public function indexAction()
    {
        $this->authorize('admin.config');

        $serviceManager = $this->getServiceLocator();
        $auditLogTable = $serviceManager->get('Base\Table\AuditLogTable');

        $category = $this->params()->fromQuery('category');
        $search = $this->params()->fromQuery('search');
        $dateStart = $this->params()->fromQuery('date-start');
        $dateEnd = $this->params()->fromQuery('date-end');

        $page = max(1, (int) $this->params()->fromQuery('page', 1));
        $pageSize = 100;

        $select = $auditLogTable->getSql()->select();

        if ($category) {
            $select->where(['category' => $category]);
        }
        if ($dateStart) {
            $select->where->greaterThanOrEqualTo('created', $dateStart . ' 00:00:00');
        }
        if ($dateEnd) {
            $select->where->lessThanOrEqualTo('created', $dateEnd . ' 23:59:59');
        }
        if ($search) {
            $select->where->nest()
                ->like('message', '%' . $search . '%')
                ->or->like('user_name', '%' . $search . '%')
                ->or->like('detail', '%' . $search . '%')
                ->unnest();
        }

        // Count
        $countSelect = clone $select;
        $countSelect->columns(['cnt' => new \Zend\Db\Sql\Expression('COUNT(*)')]);
        $countResult = $auditLogTable->selectWith($countSelect);
        $totalCount = (int) $countResult->current()['cnt'];
        $totalPages = max(1, (int) ceil($totalCount / $pageSize));

        // Fetch
        $select->order('created DESC');
        $select->limit($pageSize);
        $select->offset(($page - 1) * $pageSize);

        $entries = [];
        foreach ($auditLogTable->selectWith($select) as $row) {
            $entries[] = $row;
        }

        return [
            'entries' => $entries,
            'category' => $category,
            'search' => $search,
            'dateStart' => $dateStart,
            'dateEnd' => $dateEnd,
            'page' => $page,
            'totalPages' => $totalPages,
            'totalCount' => $totalCount,
        ];
    }

}
