<?php

namespace Backend\View\Helper\Event;

use Zend\View\Helper\AbstractHelper;

class EventsFormat extends AbstractHelper
{

    public function __invoke(array $events, $dateStart = null, $dateEnd = null)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="number">' . $view->t('No.') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('Start date') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('End date') . '</th>';
        $html .= '<th data-sort-type="number">' . $view->t('Capacity') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->option('subject.square.type') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($events as $event) {
            $html .= $view->backendEventFormat($event, $dateStart, $dateEnd);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/event/index.min.js'));
        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}
