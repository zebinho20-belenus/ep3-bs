<?php

namespace Backend\View\Helper\Square;

use Zend\View\Helper\AbstractHelper;

class SquaresFormat extends AbstractHelper
{

    public function __invoke(array $squares)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="number">&nbsp;</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Status') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time block') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time block (min. bookable)') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time block (max. bookable)') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($squares as $square) {
            $html .= $view->backendSquareFormat($square);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/config-square/index.min.js'));
        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}
