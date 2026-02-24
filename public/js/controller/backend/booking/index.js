(function() {

    $(document).ready(function() {

        /* Remove notes col if no notes */

        $(".notes-col").hide();

        $("td.notes-col").each(function() {
            if ($(this).text() !== '-') {
                $(".notes-col").show();
            }
        });

        /* Show actions on row hover */

        if ($(".actions-col").length > 1) {
            $(".actions-col").css("opacity", 0.5);

            $("tr").hover(function() {
                $(this).find(".actions-col").fadeTo(100, 1.0);
            }, function() {
                $(this).find(".actions-col").fadeTo(100, 0.5);
            });
        }

        /* Bulk select */

        var $selectAll = $("#bulk-select-all");
        var $bulkActions = $("#bulk-actions");
        var $reactivateBtn = $("#bulk-reactivate-btn");

        function updateBulkState() {
            var checked = $(".bulk-check:checked").length;
            if (checked > 0) {
                $bulkActions.show();
            } else {
                $bulkActions.hide();
            }

            // Show reactivate button only when cancelled bookings are selected
            var cancelledChecked = $(".bulk-check:checked").filter('[data-status="cancelled"]').length;
            if (cancelledChecked > 0) {
                $reactivateBtn.show();
            } else {
                $reactivateBtn.hide();
            }

            var total = $(".bulk-check:visible").length;
            $selectAll.prop("checked", checked > 0 && checked === total);
            $selectAll.prop("indeterminate", checked > 0 && checked < total);
        }

        $selectAll.on("change", function() {
            $(".bulk-check:visible").prop("checked", this.checked);
            updateBulkState();
        });

        $(document).on("change", ".bulk-check", updateBulkState);

        /* Confirm dialog */

        $("#bulk-form").on("submit", function(e) {
            var action = $(document.activeElement).val();
            var count = $(".bulk-check:checked").length;
            var msg;
            if (action === "delete") {
                msg = count + " Buchung(en) endgültig löschen? (Aktive Buchungen werden dabei storniert und gelöscht)";
            } else if (action === "reactivate") {
                msg = count + " Buchung(en) reaktivieren?";
            } else {
                msg = count + " Buchung(en) stornieren?";
            }
            if (!confirm(msg)) {
                e.preventDefault();
            }
        });

    });

})();
