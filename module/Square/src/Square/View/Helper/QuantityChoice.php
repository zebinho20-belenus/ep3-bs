<?php

namespace Square\View\Helper;

use Base\Manager\OptionManager;
use Square\Entity\Square;
use Zend\View\Helper\AbstractHelper;
use User\Manager\UserSessionManager;

class QuantityChoice extends AbstractHelper
{
    protected $optionManager;
    protected $user;

    public function __construct(OptionManager $optionManager, UserSessionManager $userSessionManager)
    {
        $this->optionManager = $optionManager;
        $this->user = $userSessionManager->getSessionUser();
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

        if ($quantityAvailable == 1) {
            $quantityChoiceSelect = "hidden";
        }

        $html .= '<label for="sb-quantity" class="me-2" style="visibility:' . $quantityChoiceSelect . '">';
        $html .= sprintf($view->t('How many %s?'), $this->optionManager->need('subject.square.unit.plural'));
        $html .= '</label>';

        // Add onchange event to toggle the guest player checkbox
        $html .= '<select id="sb-quantity" name="sb-quantity" class="form-select form-select-sm" style="display: inline-block; width: auto; min-width: 64px; visibility:' . $quantityChoiceSelect . '" onchange="toggleGuestPlayerCheckbox(this.value)">';
        //$html .= '<select id="sb-quantity" style="min-width: 64px; visibility:' . $quantityChoiceSelect . '" >';

        for ($i = 1; $i <= $quantityAvailable; $i++) {
            // Starte bei 2 statt bei 1
            for ($i = 2; $i <= $quantityAvailable; $i++) {
                $html .= sprintf('<option value="%1$s">%1$s</option>', $i);
            }
        } // Hier fehlt die schließende Klammer

        $html .= '</select>';

        if ($this->user && !$this->user->getMeta('member')) {
            $guestPlayerCheckbox = '';
            $paymentNotice = '';
        } elseif ($this->user && $this->user->getMeta('member')) {
            $guestPlayerCheckbox = '<div id="guest-player-checkbox" class="mt-2 form-check d-inline-block" style="display: none;">'
                . '<input type="checkbox" id="guest-player" name="guest-player" value="1" class="form-check-input" onchange="togglePaymentNotice(this)">'
                . '<label for="guest-player" class="form-check-label">' . $view->t('Guest player') . '</label>'
                . '</div>';

            $paymentNotice = '<div id="payment-notice" class="guest-payment-notice" style="display: none;">
                                  <b>ℹ ' . $view->t('Guest booking info') . '</b><br>
                                  ' . $view->t('As a member with a guest, you pay half the court fee. You can pay directly via PayPal or later at the office.') . '
                              </div>';
        } else {
            $guestPlayerCheckbox = '';
            $paymentNotice = '';
        }

        $quantityOccupied = $square->need('capacity') - $quantityAvailable;

        $capacityInfo = $view->squareCapacityInfo($square, $quantityOccupied, 'span');

        if ($capacityInfo) {
            $html .= '<span class="ms-2">' . $capacityInfo . '</span>';
        }

        $askNames = $square->getMeta('capacity-ask-names');

        if ($askNames && $quantityAvailable > 1) {
            $askNamesSegments = explode('-', $askNames);

            $html .= '<div class="sb-player-names">';

            $html .= '<hr class="my-2">';

            if (isset($askNamesSegments[0]) && $askNamesSegments[0] == 'optional') {
                $html .= sprintf('<p class="sb-player-names-mode gray" data-mode="optional">%s</p>',
                    $this->view->translate('The names of the other players are <b>optional</b>'));
            } else {
                $html .= sprintf('<p class="sb-player-names-mode gray" data-mode="required">%s</p>',
                    $this->view->translate('The names of the other players are <b>required</b>'));
            }

            for ($i = 2; $i <= $quantityAvailable; $i++) {
                $html .= sprintf('<div class="sb-player-name sb-player-name-%s mb-1">', $i);

                $html .= sprintf('<input type="text" name="sb-player-name-%1$s" id="sb-name-%1$s" value="" placeholder="%1$s. %2$s" class="form-control form-control-sm d-inline-block" style="min-width: 160px;">',
                    $i, $this->view->translate('Player\'s name'));

                if (isset($askNamesSegments[2]) && $askNamesSegments[2] == 'email') {
                    $html .= sprintf(' <input type="text" name="sb-player-email-%1$s" id="sb-player-email-%1$s" value="" placeholder="...%2$s" class="form-control form-control-sm d-inline-block" style="min-width: 160px;">',
                        $i, $this->view->translate('and email address'));
                }

                if ((isset($askNamesSegments[2]) && $askNamesSegments[2] == 'phone') ||
                    (isset($askNamesSegments[3]) && $askNamesSegments[3] == 'phone')) {
                    $html .= sprintf(' <input type="text" name="sb-player-phone-%1$s" id="sb-player-phone-%1$s" value="" placeholder="...%2$s" class="form-control form-control-sm d-inline-block" style="min-width: 160px;">',
                        $i, $this->view->translate('and phone number'));
                }

                $html .= '</div>';
            }

            $html .= '</div>';
        }

        $html .= $guestPlayerCheckbox;
        $html .= $paymentNotice;

        $html .= '<script>
            (function() {
                var checkboxDiv = document.getElementById("guest-player-checkbox");
                if (checkboxDiv) {
                    checkboxDiv.style.display = "block";
                }
            })();

            function toggleGuestPlayerCheckbox(quantity) {
                var checkboxDiv = document.getElementById("guest-player-checkbox");
                if (checkboxDiv) {
                    if (quantity >= 2 && quantity <= 4) {
                        checkboxDiv.style.display = "block";
                    } else {
                        checkboxDiv.style.display = "none";
                        var guestCheckbox = document.getElementById("guest-player");
                        if (guestCheckbox) {
                            guestCheckbox.checked = false;
                            togglePaymentNotice(guestCheckbox);
                        }
                    }
                }
            }

            function togglePaymentNotice(checkbox) {
                var paymentNotice = document.getElementById("payment-notice");
                if (paymentNotice) {
                    paymentNotice.style.display = checkbox.checked ? "block" : "none";
                }
            }

            document.addEventListener("DOMContentLoaded", function() {
                var quantitySelect = document.getElementById("sb-quantity");
                if (quantitySelect) {
                    if (quantitySelect.options.length >= 2) {
                        quantitySelect.value = "2";
                    }
                    toggleGuestPlayerCheckbox(parseInt(quantitySelect.value));
                }
            });
        </script>';

        return $html;
    }
}