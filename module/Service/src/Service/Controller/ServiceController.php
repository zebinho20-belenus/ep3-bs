<?php

namespace Service\Controller;

use Zend\Mvc\Controller\AbstractActionController;

class ServiceController extends AbstractActionController
{

    public function infoAction()
    { }

    public function helpAction()
    { }

    public function statusAction()
    {
        $maintenanceMode = $this->option('service.maintenance', 'false');

        if ($maintenanceMode == 'true') {
            $title = 'Maintenance';
            $status = 'maintenance';

            $response = $this->getResponse();
            $response->setStatusCode(503);
        } elseif ($maintenanceMode == 'administration') {
            $title = 'Administration';
            $status = 'administration';
        } else {
            $title = 'System status';
            $status = 'default';
        }

        return array(
            'title' => $title,
            'status' => $status,
        );
    }

}