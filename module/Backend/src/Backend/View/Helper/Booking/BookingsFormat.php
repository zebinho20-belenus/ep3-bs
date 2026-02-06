<?php

namespace Backend\View\Helper\Booking;

use Zend\View\Helper\AbstractHelper;

class BookingsFormat extends AbstractHelper
{

    public function __invoke(array $reservations, $dateStart = null, $dateEnd = null, $search = null)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="text" data-filter-type="select">&nbsp;</th>';
        $html .= '<th data-sort-type="number">' . $view->t('No.') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->t('Member') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->t('Day') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('Date') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->option('subject.square.type') . '</th>';
        $html .= '<th data-sort-type="text" class="notes-col">' . $view->t('Notes') . '</th>';
        $html .= '<th data-sort-type="price">' . $view->t('Price') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->t('Billing status') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">' . $view->t('Budget') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($reservations as $reservation) {
            $html .= $view->backendBookingFormat($reservation, $dateStart, $dateEnd, $search);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/booking/index.min.js'));
        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}
