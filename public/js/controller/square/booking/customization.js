(function() {

    $(document).ready(function() {

        $("#sb-customization-panel-warning").remove();
        $("#sb-customization-panel").show();

        $("#sb-quantity").on("change keyup focusout", onQuantityChange);

        onQuantityChange();

        $(".sb-player-names input").on("change keyup focusout", onPlayerNameUpdate);

        $(".sb-product").on("change", onProductChange);

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

            var playerNameMode = $(".sb-player-names-mode").data("mode");
            var playerNameInputs = $(".sb-player-names input:visible");

            if (quantity > 1) {
                var playerNameData = playerNameInputs.serializeArray();
                var playerNameJson = JSON.stringify(playerNameData);
                var playerNameQuery = "pn=" + encodeURIComponent(playerNameJson);
            } else {
                var playerNameQuery = "pn=0";
            }

            sbButton.css({ opacity: 1 });

            if (playerNameMode == "required") {
                playerNameInputs.each(function() {
                    if (! $(this).val()) {
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
        var isChecked = $("#with_guest").is(":checked");
        console.log("Guest checkbox is checked:", isChecked);  // Debugging line

        var sbButton = $("#sb-button");

        if (sbButton.length) {
            var oldHref = sbButton.attr("href");
            var withGuest = isChecked ? "1" : "0";
            console.log("withGuest value:", withGuest);  // Debugging line

            // Check if with_guest parameter already exists in the URL
            if (oldHref.indexOf("with_guest=") > -1) {
                // Replace existing parameter
                var newHref = oldHref.replace(/with_guest=[01]/, "with_guest=" + withGuest);
                sbButton.attr("href", newHref);
            } else {
                // Add new parameter
                sbButton.attr("href", oldHref + "&with_guest=" + withGuest);
            }

            console.log("Updated href:", sbButton.attr("href"));  // Debugging line
        }
    }

    $(document).ready(function() {
        // Other initialization code...

        // Add event handler for the with_guest checkbox
        $("#with_guest").on("change", function() {
            console.log("Checkbox changed, new state:", $(this).is(":checked"));  // Debugging line
            onGuestCheckboxChange();
        });

        // Also trigger it once on page load to set initial state
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
