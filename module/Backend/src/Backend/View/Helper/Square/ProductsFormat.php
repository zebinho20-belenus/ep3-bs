<?php

namespace Backend\View\Helper\Square;

use Zend\View\Helper\AbstractHelper;

class ProductsFormat extends AbstractHelper
{

    public function __invoke(array $squareProducts)
    {
        $view = $this->getView();
        $html = '';

        $html .= '<div class="table-responsive">';
        $html .= '<table class="table table-bordered table-hover align-middle">';

        $html .= '<thead><tr>';
        $html .= '<th data-sort-type="number">&nbsp;</th>';
        $html .= '<th data-sort-type="text">' . $view->t('Name') . '</th>';
        $html .= '<th data-sort-type="text">' . $view->option('subject.square.type') . '</th>';
        $html .= '<th data-sort-type="price">' . $view->t('Price') . '</th>';
        $html .= '<th data-sort-type="none" class="no-print">&nbsp;</th>';
        $html .= '</tr></thead>';

        $html .= '<tbody>';
        foreach ($squareProducts as $squareProduct) {
            $html .= $view->backendSquareProductFormat($squareProduct);
        }
        $html .= '</tbody>';

        $html .= '</table>';
        $html .= '</div>';

        $view->headScript()->appendFile($view->basePath('js/controller/backend/config-square/products.min.js'));
        $view->headScript()->appendFile($view->basePath('js/controller/backend/table-sort.min.js'));

        return $html;
    }

}
