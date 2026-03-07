<?php

namespace Square\Controller;

use Zend\Mvc\Controller\AbstractActionController;

class SquareController extends AbstractActionController
{

    public function indexAction()
    {
        $dateStartParam = $this->params()->fromQuery('ds');
        $dateEndParam = $this->params()->fromQuery('de');
        $timeStartParam = $this->params()->fromQuery('ts');
        $timeEndParam = $this->params()->fromQuery('te');
        $squareParam = $this->params()->fromQuery('s');
        $flagParam = $this->params()->fromQuery('f');
        $jsonParam = $this->params()->fromQuery('json');

        $serviceManager = @$this->getServiceLocator();
        $squareProductManager = $serviceManager->get('Square\Manager\SquareProductManager');
        $squareValidator = $serviceManager->get('Square\Service\SquareValidator');

        try {
            $byproducts = $squareValidator->isBookable($dateStartParam, $dateEndParam, $timeStartParam, $timeEndParam, $squareParam);
        } catch (\RuntimeException $e) {
            return $this->redirect()->toRoute('frontend');
        }

        $byproducts['validator'] = $squareValidator;

        $products = $squareProductManager->getBySquare($byproducts['square']);
        $byproducts['products'] = $products;

        $byproducts['flag'] = $flagParam;

        if ($jsonParam == "true") {
            return $this->jsonViewModel($byproducts);
        }

        return $this->ajaxViewModel($byproducts);
    }

}
