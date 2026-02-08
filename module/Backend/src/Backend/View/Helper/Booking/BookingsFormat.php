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
        $html .= '<th data-sort-type="none" class="no-print bulk-check-col">'
            . '<input type="checkbox" id="bulk-select-all" class="form-check-input" title="' . $view->t('Select all') . '">'
            . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select">&nbsp;</th>';
        $html .= '<th data-sort-type="number" class="responsive-pass-5">' . $view->t('No.') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="responsive-pass-2">' . $view->t('Member') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="responsive-pass-3">' . $view->t('Day') . '</th>';
        $html .= '<th data-sort-type="date-de">' . $view->t('Date') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Time') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="responsive-pass-4">' . $view->option('subject.square.type') . '</th>';
        $html .= '<th data-sort-type="text" class="notes-col responsive-pass-3">' . $view->t('Notes') . '</th>';
        $html .= '<th data-sort-type="price" class="responsive-pass-5">' . $view->t('Price') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="responsive-pass-2">' . $view->t('Billing status') . '</th>';
        $html .= '<th data-sort-type="text" data-filter-type="select" class="responsive-pass-3">' . $view->t('Budget') . '</th>';
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
