<?php

namespace Square\View\Helper;

use Base\Manager\OptionManager;
use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;

class QuantityChoice extends AbstractHelper
{

    protected $optionManager;

    public function __construct(OptionManager $optionManager)
    {
        $this->optionManager = $optionManager;
    }

    public function __invoke(Square $square, array $bookings)
    {
        $quantityAvailable = $square->need('capacity');

        foreach ($bookings as $booking) {
            $quantityAvailable -= $booking->need('quantity');
        }

        $view = $this->getView();
        $html = '';

        $quantityChoiceSelect = "visible";

        if ($quantityAvailable == 1) { $quantityChoiceSelect = "hidden"; } 

        $html .= '<label for="sb-quantity" style="margin-right: 8px; visibility:' . $quantityChoiceSelect  . '" >';
        $html .= sprintf($view->t('How many %s?'), $this->optionManager->need('subject.square.unit.plural'));
        $html .= '</label>';

        // Add onchange event to toggle the guest player checkbox
        $html .= '<select id="sb-quantity" name="sb-quantity" style="min-width: 64px;  visibility:' . $quantityChoiceSelect  . '" onchange="toggleGuestPlayerCheckbox(this.value)">';
        //$html .= '<select id="sb-quantity" style="min-width: 64px;  visibility:' . $quantityChoiceSelect  . '" >';

        for ($i = 1; $i <= $quantityAvailable; $i++) {
            $html .= sprintf('<option value="%1$s">%1$s</option>', $i);
        }

        $html .= '</select>';

        // Define the guest player checkbox, initially hidden
        $guestPlayerCheckbox = '<div id="guest-player-checkbox" style="margin-top: 8px; display: none;">
                                    <label for="guest-player">
                                        <input type="checkbox" id="guest-player" name="guest-player" value="1" onchange="togglePaymentNotice(this)">
                                        ' . $view->t('Guest player') . '
                                    </label>
                                </div>';

        // Define the payment notice, initially hidden
        $paymentNotice = '<div id="payment-notice" style="margin-top: 8px; display: none; border: 1px solid red; padding: 8px;">
                              ' . $view->t('Please transfer the amount as a PayPal friends payment to cy@xy.de.') . '
                          </div>';

        $quantityOccupied = $square->need('capacity') - $quantityAvailable;

        $capacityInfo = $view->squareCapacityInfo($square, $quantityOccupied, 'span');

        if ($capacityInfo) {
            $html .= '<span style="margin-left: 8px;">' . $capacityInfo . '</span>';
        }

        $askNames = $square->getMeta('capacity-ask-names');

        if ($askNames && $quantityAvailable > 1) {
            $askNamesSegments = explode('-', $askNames);

            $html .= '<div class="sb-player-names">';

            $html .= '<div class="separator separator-line"></div>';

            if (isset($askNamesSegments[0]) && $askNamesSegments[0] == 'optional') {
                $html .= sprintf('<p class="sb-player-names-mode gray" data-mode="optional">%s</p>',
                    $this->view->translate('The names of the other players are <b>optional</b>'));
            } else {
                $html .= sprintf('<p class="sb-player-names-mode gray" data-mode="required">%s</p>',
                    $this->view->translate('The names of the other players are <b>required</b>'));
            }

            for ($i = 2; $i <= $quantityAvailable; $i++) {
                $html .= sprintf('<div class="sb-player-name sb-player-name-%s" style="margin-bottom: 4px;">', $i);

                $html .= sprintf('<input type="text" name="sb-player-name-%1$s" id="sb-name-%1$s" value="" placeholder="%1$s. %2$s" style="min-width: 160px;">',
                    $i, $this->view->translate('Player\'s name'));

                if (isset($askNamesSegments[2]) && $askNamesSegments[2] == 'email') {

                    $html .= sprintf(' <input type="text" name="sb-player-email-%1$s" id="sb-player-email-%1$s" value="" placeholder="...%2$s" style="min-width: 160px;">',
                        $i, $this->view->translate('and email address'));
                }

                if ((isset($askNamesSegments[2]) && $askNamesSegments[2] == 'phone') ||
                    (isset($askNamesSegments[3]) && $askNamesSegments[3] == 'phone')) {

                    $html .= sprintf(' <input type="text" name="sb-player-phone-%1$s" id="sb-player-phone-%1$s" value="" placeholder="...%2$s" style="min-width: 160px;">',
                        $i, $this->view->translate('and phone number'));
                }

                $html .= '</div>';
            }

            $html .= '</div>';
        }
        // Append the guest player checkbox and payment notice to the main HTML
        $html .= $guestPlayerCheckbox;
        $html .= $paymentNotice;

        // JavaScript to handle showing/hiding the guest player checkbox and payment notice
        $html .= '<script>
                    function toggleGuestPlayerCheckbox(quantity) {
                        var checkboxDiv = document.getElementById("guest-player-checkbox");
                        if (quantity >= 2 && quantity <= 4) {
                            checkboxDiv.style.display = "block";
                        } else {
                            checkboxDiv.style.display = "none";
                            document.getElementById("guest-player").checked = false;
                            togglePaymentNotice({checked: false});
                        }
                    }

                    function togglePaymentNotice(checkbox) {
                        var paymentNotice = document.getElementById("payment-notice");
                        if (checkbox.checked) {
                            paymentNotice.style.display = "block";
                        } else {
                            paymentNotice.style.display = "none";
                        }
                    }
                  </script>';
        return $html;
    }

}
