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

        $html .= '<label for="sb-quantity" style="margin-right: 8px; visibility:' . $quantityChoiceSelect . '" >';
        $html .= sprintf($view->t('How many %s?'), $this->optionManager->need('subject.square.unit.plural'));
        $html .= '</label>';

        // Add onchange event to toggle the guest player checkbox
        $html .= '<select id="sb-quantity" name="sb-quantity" style="min-width: 64px; visibility:' . $quantityChoiceSelect . '" onchange="toggleGuestPlayerCheckbox(this.value)">';
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
            $guestPlayerCheckbox = '<div id="guest-player-checkbox" style="margin-top: 8px; display: none;">
                                        <label for="guest-player">
                                            <input type="checkbox" id="guest-player" name="guest-player" value="1" onchange="togglePaymentNotice(this)">
                                            ' . $view->t('Guest player') . '
                                        </label>
                                    </div>';

            $paymentNotice = '<div id="payment-notice" style="margin-top: 8px; display: none; border: 1px solid red; padding: 8px;">
                                  ' . $view->t('Please transfer the amount as a PayPal friends payment to schatzmeister@tcn-kail.de or use the Money letterbox at the office') . '
                              </div>';
        } else {
            $guestPlayerCheckbox = '';
            $paymentNotice = '';
        }

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
                updateBookingUrl(checkbox.checked);
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

            function updateBookingUrl(isGuestPlayer) {
                var sbButton = document.getElementById("sb-button");
                if (!sbButton) return;

                var quantity = document.getElementById("sb-quantity").value;
                var playerData = [];

                for (var i = 2; i <= quantity; i++) {
                    var nameInput = document.getElementById("sb-name-" + i);
                    if (nameInput && nameInput.value.trim()) {
                        var playerName = nameInput.value.trim();
                        if (isGuestPlayer && !playerName.endsWith(" Gastspieler")) {
                            playerName += " Gastspieler";
                        }
                        playerData.push({
                            "name": "sb-player-name-" + i,
                            "value": playerName
                        });
                    }
                }

                var playerNamesJson = JSON.stringify(playerData);

                var currentHref = sbButton.getAttribute("href");
                var newHref = currentHref;

                if (newHref.includes("pn=")) {
                    newHref = newHref.replace(/pn=[^&]+/, "pn=" + encodeURIComponent(playerNamesJson));
                } else {
                    newHref += (newHref.includes("?") ? "&" : "?") + "pn=" + encodeURIComponent(playerNamesJson);
                }

                if (newHref.includes("gp=")) {
                    newHref = newHref.replace(/gp=[^&]+/, "gp=" + (isGuestPlayer ? "1" : "0"));
                } else {
                    newHref += "&gp=" + (isGuestPlayer ? "1" : "0");
                }

                sbButton.setAttribute("href", newHref);
            }

            function handlePlayerNameChange() {
                var guestCheckbox = document.getElementById("guest-player");
                updateBookingUrl(guestCheckbox.checked);
            }

            document.addEventListener("DOMContentLoaded", function() {
                var quantity = document.getElementById("sb-quantity").value;
                toggleGuestPlayerCheckbox(quantity);

                var playerInputs = document.querySelectorAll(".sb-player-names input[type=\'text\']");
                playerInputs.forEach(function(input) {
                    input.addEventListener("input", handlePlayerNameChange);
                });

                var guestCheckbox = document.getElementById("guest-player");
                updateBookingUrl(guestCheckbox.checked);
            });
        </script>';

        return $html;
    }
}