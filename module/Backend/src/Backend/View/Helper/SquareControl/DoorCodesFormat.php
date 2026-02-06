<?php

namespace Backend\View\Helper\SquareControl;

use Zend\View\Helper\AbstractHelper;

class DoorCodesFormat extends AbstractHelper
{

    public function __invoke($codes)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('Date (Start)') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('Date (End)') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($codes as $code) {
            $html .= $view->backendDoorCodeFormat($code);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}
