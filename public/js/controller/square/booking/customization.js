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
        var isChecked = $("#guest-player").is(":checked");
        var sbButton = $("#sb-button");
        // Debugging
        console.log("isChecked: " + isChecked);


        if (sbButton.length) {
            var oldHref = sbButton.attr("href");
            var withGuest = isChecked ? "1" : "0";

            // Update the guest parameter (gp) in the URL
            if (oldHref.indexOf("gp=") > -1) {
                // Replace existing parameter
                var newHref = oldHref.replace(/gp=[01]/, "gp=" + withGuest);
                sbButton.attr("href", newHref);
                // Debugging
                console.log("newHref: " + newHref);

            } else {
                // Add new parameter
                sbButton.attr("href", oldHref + "&gp=" + withGuest);
            }
        }
    }
    // Debugging
    console.log("guest player: " + $("#guest-player").is(":checked"));

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
