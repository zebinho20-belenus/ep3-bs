<?php

namespace Square\View\Helper;

use Square\Entity\Square;
use Square\Manager\SquareProductManager;
use Zend\View\Helper\AbstractHelper;

class ProductChoice extends AbstractHelper
{

    protected $squareProductManager;

    public function __construct(SquareProductManager $squareProductManager)
    {
        $this->squareProductManager = $squareProductManager;
    }

    public function __invoke(Square $square, \DateTime $dateStart)
    {
        $products = $this->squareProductManager->getBySquare($square);

        if (! $products) {
            return null;
        }

        $view = $this->getView();
        $html = '';

        foreach ($products as $product) {

            /* Validate product date range */

            if ($product->get('date_start')) {
                $productDateStart = new \DateTime($product->get('date_start'));
                $productDateStart->setTime(0, 0, 0);

                if ($dateStart <= $productDateStart) {
                    continue;
                }
            }

            if ($product->get('date_end')) {
                $productDateEnd = new \DateTime($product->get('date_end'));
                $productDateEnd->setTime(23, 59, 59);

                if ($dateStart >= $productDateEnd) {
                    continue;
                }
            }

            /* Render product */

            $spid = $product->need('spid');

            $quantityOptions = explode(',', $product->need('options'));

            $html .= '<div class="d-flex align-items-center gap-2 mb-2">';

            $html .= '<select id="sb-product-' . $spid . '" class="sb-product form-select form-select-sm" style="width: auto;" data-spid="' . $spid . '">';
            $html .= '<option value="0" selected="selected">' . $view->t('None') . '</option>';

            foreach ($quantityOptions as $quantityOption) {
                $html .= '<option value="' . $quantityOption . '">' . $quantityOption . '</option>';
            }

            $html .= '</select>';

            $html .= '<div class="flex-grow-1 text-start">';
            $html .= '<strong><label for="sb-product-' . $spid . '">' . $product->need('name') . '</label></strong>';

            if ($product->get('description')) {
                $html .= '<div class="small text-muted">' . $product->get('description') . '</div>';
            }

            $html .= '</div>';

            $html .= '<div class="text-nowrap">';
            $html .= $view->priceFormat($product->need('price'), $product->need('rate'), $product->need('gross'), null, null, 'per item');
            $html .= '</div>';

            $html .= '</div>';
        }

        if ($html) {
            $html = sprintf('<div>%s</div>', $html);
        }

        return $html;
    }

}