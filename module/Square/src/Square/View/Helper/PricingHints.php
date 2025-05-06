<?php

namespace Square\View\Helper;

use Base\Manager\OptionManager;
use DateTime;
use Square\Entity\Square;
use Square\Manager\SquarePricingManager;
use User\Manager\UserSessionManager;
use Zend\View\Helper\AbstractHelper;

class PricingHints extends AbstractHelper
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

    public function __invoke(DateTime $dateStart, DateTime $dateEnd, Square $square)
    {
        $pricingVisibility = $this->optionManager->get('service.pricing.visibility', 'private');

        if ($pricingVisibility == 'public' || ($this->user && $pricingVisibility == 'private')) {

            $member = 0;
            if ($this->user != null && $this->user->getMeta('member') != null) {
                   $member = $this->user->getMeta('member');
            }
            
            $pricing = $this->squarePricingManager->getFinalPricingInRange($dateStart, $dateEnd, $square, 1, $member);

            // Check guest player checkbox
            $guestPlayerCheckbox = isset($_GET['gp']) && $_GET['gp'] == '1';

            if ($pricing) {
                if ($guestPlayerCheckbox) {
                    $pricing['price'] /= 2;
                }
                return $this->getView()->priceFormat($pricing['price'], $pricing['rate'], $pricing['gross'], $pricing['seconds'], $pricing['per_quantity']);
            }
        }

        return null;
    }

}
