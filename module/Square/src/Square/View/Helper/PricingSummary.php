<?php

namespace Square\View\Helper;

use Base\Manager\OptionManager;
use DateTime;
use Square\Entity\Square;
use Square\Manager\SquarePricingManager;
use User\Manager\UserSessionManager;
use Zend\View\Helper\AbstractHelper;

class PricingSummary extends AbstractHelper
{

    protected $optionManager;
    protected $squarePricingManager;
    protected $user;

    public function __construct(OptionManager $optionManager,
        SquarePricingManager $squarePricingManager,
        UserSessionManager $userSessionManager)
    {
        $this->optionManager = $optionManager;
        $this->squarePricingManager = $squarePricingManager;
        $this->user = $userSessionManager->getSessionUser();
    }

    public function __invoke(DateTime $dateStart, DateTime $dateEnd, Square $square, $quantity = 1, array $products = array(), $guestPlayer = null)
    {
        $pricingVisibility = $this->optionManager->get('service.pricing.visibility', 'private');

        if ($pricingVisibility == 'never') {
            return null;
        }

        $member = 0;
        if ($this->user != null && $this->user->getMeta('member') != null) {
           $member = $this->user->getMeta('member');
        }

        $finalPricing = $this->squarePricingManager->getFinalPricingInRange($dateStart, $dateEnd, $square, $quantity, $member);

        if (! $finalPricing) {
            return null;
        }

        $total = 0;
        // Use controller-provided guest player parameter only
        $guestPlayer = (bool) $guestPlayer;

        // Member with guest: half of non-member price
        if ($member && $guestPlayer && $finalPricing['price'] == 0) {
            $nonMemberPricing = $this->squarePricingManager->getFinalPricingInRange($dateStart, $dateEnd, $square, $quantity, false);
            if ($nonMemberPricing) {
                $finalPricing['price'] = $nonMemberPricing['price'] / 2;
            }
        }
        // Non-member with guest: full price (no discount)

        $view = $this->getView();
        $html = '';

        $html .= '<table class="table table-bordered align-middle mb-0 pricing-summary-table">';
        $html .= '<tr class="table-light">';

        if ($quantity == 1) {
            $squareUnit = $this->optionManager->need('subject.square.unit');
        } else {
            $squareUnit = $this->optionManager->need('subject.square.unit.plural');
        }

        $prettyTimeTxt = $view->prettyTime($finalPricing['seconds']);
        $quantityTxt   = $quantity > 1 ? $view->numberFormat($quantity) . ' ' . $squareUnit : '';
        $metaLine      = implode(' · ', array_filter([$prettyTimeTxt, $quantityTxt]));

        $html .= sprintf('<td><b>%s %s</b><div class="small-text">%s</div><div class="ps-meta">%s</div></td>',
            $this->optionManager->need('subject.square.type'),
            $view->t($square->need('name')),
            $view->dateRange($dateStart, $dateEnd),
            $view->escapeHtml($metaLine));

        $html .= sprintf('<td class="ps-detail-col">%s</td>', $prettyTimeTxt);

        if ($quantity > 1) {
            $html .= sprintf('<td class="ps-detail-col">%s %s</td>',
                $view->numberFormat($quantity), $squareUnit);
        } else {
            $html .= '<td class="ps-detail-col"></td>';
        }

        $html .= sprintf('<td>%s</td>',
            $view->priceFormat($finalPricing['price'], $finalPricing['rate'], $finalPricing['gross']));

        $html .= '</tr>';

        $total += $finalPricing['price'];

        /* Render additional square products */

        foreach ($products as $product) {
            $html .= '<tr>';

            $productTotal = $product->need('price') * $product->needExtra('amount');
            if ($member && $guestPlayer) {
                // Only members with guest get half price on products
                $productTotal = $productTotal / 2;
            }

            $html .= '<td>' . $product->need('name') . '</td>';
            $html .= '<td colspan="2">' . sprintf($view->t('%s items'), $product->needExtra('amount')) . '</td>';
            $html .= '<td>' . $view->priceFormat($productTotal, $product->need('rate'), $product->need('gross')) . '</td>';

            $total += $productTotal;

            $html .= '</tr>';
        }

        /* Render total */

        $html .= '<tr class="table-active fw-bold">';
        $html .= '<td>' . $view->t('Total') . '</td>';
        $html .= '<td class="ps-detail-col" colspan="2"></td>';
        $html .= '<td>' . $view->priceFormat($total) . '</td>';
        $html .= '</tr>';

        $html .= '</table>';

        return $html;
    }

}
