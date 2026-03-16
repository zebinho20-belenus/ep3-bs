(function() {

    /**
     * Initialize the booking edit form within a given scope.
     * Called from calendar/index.js for squarebox context,
     * or from $(document).ready() for standalone page context.
     *
     * @param {Element} scope - DOM element to scope all selectors to
     */
    window.initBookingEditForm = function(scope) {
        var $scope = $(scope);

        /* Guard against double initialization */
        if ($scope.data('bf-initialized')) return;
        $scope.data('bf-initialized', true);

        var urlProvider = $scope.find("#bf-url-provider");
        var tagProvider = $scope.find("#bf-tag-provider");

        /* Only run if this is the booking edit form */
        var userInput = $scope.find("#bf-user");
        if (!userInput.length) {
            /* Still init datepickers for other forms (e.g. event edit) */
            $scope.find(".datepicker").datepicker();
            return;
        }

        /* Autocomplete for user — destroy first as safety net */
        if ($.fn.autocomplete) {
            if (userInput.data('ui-autocomplete')) {
                userInput.autocomplete('destroy');
            }
            if (urlProvider.length) {
                userInput.autocomplete({
                    "minLength": 1,
                    "source": urlProvider.data("user-autocomplete-url")
                });
            }
        }

        /* Datepicker */
        $scope.find("#bf-date-start, #bf-date-end").datepicker();

        /* Update Form */
        $scope.find("#bf-repeat").on("change", updateForm);
        updateForm();

        /* Quantity → Guest + Player Names toggle */
        $scope.find("#bf-quantity").on("change", updateQuantityDependents);
        updateQuantityDependents();

        /* Exclusive edit fields */
        var $editUser = $scope.find('input[name="bf-edit-user"]');
        var $editBills = $scope.find('input[name="bf-edit-bills"]');

        if ($editUser.length && $editBills.length) {
            $editUser.on('change', function() {
                $editBills.prop('checked', false);
            });

            $editBills.on('change', function() {
                $editUser.prop('checked', false);
            });
        }

        /* Enable form on submit */
        var formSubmit = $scope.find("#bf-submit");
        var form = formSubmit.closest("form");

        form.on("submit", function() {
            form.find(":disabled").removeAttr("disabled");
        });

        /* --- scoped helper functions --- */

        function updateForm()
        {
            var dateEnd = $scope.find("#bf-date-end");
            var repeat = $scope.find("#bf-repeat");

            if (repeat.val() === "0") {
                disableFormElement(dateEnd);
            } else {
                enableFormElement(dateEnd);
            }

            var editMode = tagProvider.data("edit-mode-tag");

            if (editMode == "no_subscr") {
                disableFormElement(repeat);
                disableFormElement($scope.find("#bf-date-end"));
            }

            var rid = $scope.find("#bf-rid");

            if (rid.val()) {
                disableFormElement(repeat);

                if (editMode == "booking") {
                    disableFormElement($scope.find("#bf-time-start"));
                    disableFormElement($scope.find("#bf-time-end"));
                    disableFormElement($scope.find("#bf-date-start"));
                    disableFormElement($scope.find("#bf-date-end"));
                } else if (editMode == "reservation") {
                    disableFormElement($scope.find("#bf-user"));
                    disableFormElement($scope.find("#bf-sid"));
                    disableFormElement($scope.find("#bf-status-billing"));
                    disableFormElement($scope.find("#bf-quantity"));
                    disableFormElement($scope.find("#bf-notes"));
                }
            }
        }

        function updateQuantityDependents()
        {
            var quantity = parseInt($scope.find("#bf-quantity").val()) || 1;
            var guestContainer = $scope.find("#bf-guest-player-container");
            var namesContainer = $scope.find("#bf-player-names-container");

            if (quantity > 1) {
                guestContainer.show();
                namesContainer.show();
            } else {
                guestContainer.hide();
                namesContainer.hide();
                $scope.find("#bf-guest-player").prop("checked", false);
            }

            $scope.find(".bf-player-name-row").each(function() {
                var playerNum = parseInt($(this).data("player"));
                $(this).toggle(playerNum <= quantity);
            });
        }

        function disableFormElement(element)
        {
            if (typeof element == "string") {
                element = $scope.find(element);
            }

            element.attr("disabled", "disabled");
            element.css("opacity", 0.5);
        }

        function enableFormElement(element)
        {
            if (typeof element == "string") {
                element = $scope.find(element);
            }

            element.removeAttr("disabled");
            element.css("opacity", 1.0);
        }
    };

    /* Auto-init on page load, but NOT inside squarebox (calendar/index.js handles that) */
    $(document).ready(function() {
        var bf = $("#bf");
        if (bf.length && !bf.closest('.squarebox-desktop, .squarebox-mobile').length) {
            window.initBookingEditForm(document);
        }
    });

})();
