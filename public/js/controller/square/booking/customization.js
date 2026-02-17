(function() {

    $(document).ready(function() {

        $("#sb-customization-panel-warning").remove();
        $("#sb-customization-panel").show();

        $("#sb-quantity").on("change keyup focusout", onQuantityChange);

        onQuantityChange();

        $(".sb-player-names").on("input", ".sb-player-firstname, .sb-player-lastname", function() {
            var $input = $(this);
            if ($input.val().trim().length >= 2) {
                $input.removeClass("is-invalid");
            }
            onPlayerNameUpdate();
        });

        $(".sb-player-names").on("change keyup focusout", "input:not(.sb-player-firstname):not(.sb-player-lastname)", onPlayerNameUpdate);

        $(".sb-product").on("change", onProductChange);

        // Validate on button click
        $(document).on("click", "#sb-button", function(e) {
            var playerNameMode = $(".sb-player-names-mode").data("mode");
            if (playerNameMode !== "required") return;

            var quantity = $("#sb-quantity").val();
            if (quantity <= 1) return;

            var hasError = false;
            $(".sb-player-name:visible").each(function() {
                var $firstname = $(this).find(".sb-player-firstname");
                var $lastname = $(this).find(".sb-player-lastname");

                if ($firstname.val().trim().length < 2) {
                    $firstname.addClass("is-invalid");
                    hasError = true;
                }
                if ($lastname.val().trim().length < 2) {
                    $lastname.addClass("is-invalid");
                    hasError = true;
                }
            });

            if (hasError) {
                e.preventDefault();
                return false;
            }
        });

    });

    function onQuantityChange() {
        var quantity = $("#sb-quantity").val();
        var sbButton = $("#sb-button");

        if (sbButton.length) {
            var oldHref = sbButton.attr("href");
            var newHref = oldHref.replace(/q=[0-9]+/, "q=" + quantity);

            sbButton.attr("href", newHref);
        }

        var askNamesPanel = $(".sb-player-names");

        if (askNamesPanel.length) {
            if (quantity > 1) {
                $(".sb-player-name").hide();

                for (var i = 2; i <= quantity; i++) {
                    $(".sb-player-name-" + i).show();
                }

                askNamesPanel.show();
            } else {
                askNamesPanel.hide();
            }

            $(window).trigger("squarebox.update");
        }

        onPlayerNameUpdate();
    }

    function onPlayerNameUpdate() {
        var sbButton = $("#sb-button");

        if (sbButton.length) {
            var quantity = $("#sb-quantity").val();
            var isGuestPlayer = $("#guest-player").is(":checked");

            var playerNameMode = $(".sb-player-names-mode").data("mode");

            if (quantity > 1) {
                var playerNameData = [];

                $(".sb-player-name:visible").each(function() {
                    var $row = $(this);
                    var $firstname = $row.find(".sb-player-firstname");
                    var $lastname = $row.find(".sb-player-lastname");

                    var first = $firstname.val() ? $firstname.val().trim() : "";
                    var last = $lastname.val() ? $lastname.val().trim() : "";
                    var combined = "";

                    if (first && last) {
                        combined = first + " " + last;
                    } else if (first) {
                        combined = first;
                    } else if (last) {
                        combined = last;
                    }

                    if (isGuestPlayer && combined && !combined.endsWith(" Gastspieler")) {
                        combined = combined + " Gastspieler";
                    }

                    // Use firstname field name as the key for backward compat
                    playerNameData.push({
                        name: $firstname.attr("name"),
                        value: combined
                    });

                    // Also include email/phone fields if visible
                    $row.find("input:not(.sb-player-firstname):not(.sb-player-lastname)").each(function() {
                        playerNameData.push({
                            name: $(this).attr("name"),
                            value: $(this).val()
                        });
                    });
                });

                var playerNameJson = JSON.stringify(playerNameData);
                var playerNameQuery = "pn=" + encodeURIComponent(playerNameJson);
            } else {
                var playerNameQuery = "pn=0";
            }

            sbButton.css({ opacity: 1 });

            if (playerNameMode == "required") {
                $(".sb-player-name:visible").each(function() {
                    var $firstname = $(this).find(".sb-player-firstname");
                    var $lastname = $(this).find(".sb-player-lastname");

                    if (!$firstname.val() || $firstname.val().trim().length < 2 ||
                        !$lastname.val() || $lastname.val().trim().length < 2) {
                        sbButton.css({ opacity: 0 });
                    }
                });
            }

            var oldHref = sbButton.attr("href");
            var newHref = oldHref.replace(/pn=[^&]+/, playerNameQuery);

            sbButton.attr("href", newHref);
        }
    }
    function onGuestCheckboxChange() {
        var isChecked = $("#guest-player").is(":checked");
        var sbButton = $("#sb-button");

        if (sbButton.length) {
            var oldHref = sbButton.attr("href");
            var withGuest = isChecked ? "1" : "0";

            if (oldHref.indexOf("gp=") > -1) {
                var newHref = oldHref.replace(/gp=[01]/, "gp=" + withGuest);
                sbButton.attr("href", newHref);
            } else {
                sbButton.attr("href", oldHref + "&gp=" + withGuest);
            }
        }

        // Re-serialize player names with/without Gastspieler suffix
        onPlayerNameUpdate();
    }

    $(document).ready(function() {
        // Add event handler for the with_guest checkbox
        $("#guest-player").on("change", onGuestCheckboxChange);

        // Trigger it once on page load to set initial state
        onGuestCheckboxChange();
    });
    function onProductChange() {
        var sbButton = $("#sb-button");

        if (sbButton.length) {
            var products = "";

            $(".sb-product").each(function(index, element) {
                var spid = $(element).data("spid");
                var value = $(element).val();

                if (value > 0) {
                    products += spid + ":" + value + ",";
                }
            });

            if (products) {
                products = products.substr(0, products.length - 1);
            } else {
                products = "0";
            }

            var oldHref = sbButton.attr("href");
            var newHref = oldHref.replace(/p=[0-9\:\,]+/, "p=" + products);

            sbButton.attr("href", newHref);
        }
    }

})();
