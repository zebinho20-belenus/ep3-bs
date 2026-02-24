(function() {

    $(document).ready(function() {

        var $container = $("#opening-times-container");
        var $template = $("#opening-times-template");

        /* --- Add new date range (top-level rule) --- */
        $container.on("click", ".opening-times-date-range-new", function(event) {
            event.preventDefault();

            var $rule = $template.children(".opening-times-rule").clone();
            $rule.hide();
            $(this).before($rule);
            $rule.fadeIn();

            $rule.find(".datepicker").datepicker();
        });

        /* --- Add new time block within existing date range --- */
        $container.on("click", ".opening-times-time-new", function(event) {
            event.preventDefault();

            var $timeBlock = $template.find(".opening-times-time-block").first().clone();
            $timeBlock.hide();
            $(this).before($timeBlock);
            $timeBlock.fadeIn();
        });

        /* --- Delete handler --- */
        $container.on("click", ".opening-times-delete", function(event) {
            event.preventDefault();

            var fadeTime = 200;
            var $this = $(this);

            // Deleting a time block line
            var $timeBlock = $this.closest(".opening-times-time-block");
            if ($timeBlock.length) {
                var $rule = $timeBlock.closest(".opening-times-rule");
                if ($rule.find(".opening-times-time-block").length > 1) {
                    $timeBlock.fadeOut(fadeTime, function() { $(this).remove(); });
                    return;
                }
                // Only one time block left — delete entire rule
                $rule.fadeOut(fadeTime, function() { $(this).remove(); });
                return;
            }

            // Deleting a date range (top-level rule)
            var $rule = $this.closest(".opening-times-rule");
            if ($rule.length) {
                $rule.fadeOut(fadeTime, function() { $(this).remove(); });
            }
        });

        /* --- Save handler --- */
        $("#opening-times-save").on("click", function(event) {
            $("#opening-times-form-rules input").remove();

            var i = 0;

            $container.find(".opening-times-time-block").each(function(index, element) {
                var $timeBlock = $(element);
                var $rule = $timeBlock.closest(".opening-times-rule");

                var dateStart = $rule.find(".opening-times-dateStart").first().val();
                var dateEnd = $rule.find(".opening-times-dateEnd").first().val();
                var timeStart = $timeBlock.find(".opening-times-timeStart").first().val();
                var timeEnd = $timeBlock.find(".opening-times-timeEnd").first().val();
                var sid = $timeBlock.find(".opening-times-sid").first().val();

                var priority = index;

                // Check date
                if (! dateStart.match(/^(0[1-9]|[1-2][0-9]|3[0-1])\.(0[1-9]|1[0-2])\.[0-9]{4}$/)) {
                    window.alert("Hinweis: Das Datum \"" + dateStart + "\" ist ungültig - Format: 24.12.2014");

                    event.preventDefault();
                    return;
                }

                if (! dateEnd.match(/^(0[1-9]|[1-2][0-9]|3[0-1])\.(0[1-9]|1[0-2])\.[0-9]{4}$/)) {
                    window.alert("Hinweis: Das Datum \"" + dateEnd + "\" ist ungültig - Format: 24.12.2014");

                    event.preventDefault();
                    return;
                }

                if (timeStart.match(/^[0-9]{0,1}[0-9]$/)) {
                    timeStart += ":00";
                }

                if (! timeStart.match(/^[0-9]{0,1}[0-9]:[0-9][0-9]$/)) {
                    window.alert("Hinweis: Die Uhrzeit \"" + timeStart + "\" ist ungültig - Format: 23:15");

                    event.preventDefault();
                    return;
                }

                if (timeEnd.match(/^[0-9]{0,1}[0-9]$/)) {
                    timeEnd += ":00";
                }

                if (! timeEnd.match(/^[0-9]{0,1}[0-9]:[0-9][0-9]$/)) {
                    window.alert("Hinweis: Die Uhrzeit \"" + timeEnd + "\" ist ungültig - Format: 23:15");

                    event.preventDefault();
                    return;
                }

                var data = JSON.stringify( [sid, priority, dateStart, dateEnd, timeStart, timeEnd] );

                $("#opening-times-form-rules").append('<input type="hidden" name="opening-times-rule-' + index + '" value="' + encodeURI(data) + '">');

                i++;
            });

            $("#opening-times-rules-count").val(i);
        });

        /* --- Reconstruct existing rules from DB --- */

        var rules = window.openingTimesRules;

        var latestStartEndDate;

        $.each(rules, function(index, element) {
            var sid = element[1];
            var dateStart = element[3];
            var dateEnd = element[4];
            var timeStart = element[5];
            var timeEnd = element[6];

            if (! sid) {
                sid = "null";
            }

            var thisStartEndDate = "" + dateStart + dateEnd;

            if (thisStartEndDate !== latestStartEndDate) {
                // New date range — clone full rule
                var $rule = $template.children(".opening-times-rule").clone();
                $container.find(".opening-times-date-range-new").before($rule);
            } else {
                // New time block within existing date range
                var $timeBlock = $template.find(".opening-times-time-block").first().clone();
                $container.find(".opening-times-rule:last .opening-times-time-new").before($timeBlock);
            }

            $container.find(".opening-times-dateStart:last").val(dateStart);
            $container.find(".opening-times-dateEnd:last").val(dateEnd);
            $container.find(".opening-times-timeStart:last").val(timeStart.substring(0, 5));
            $container.find(".opening-times-timeEnd:last").val(timeEnd.substring(0, 5));
            $container.find(".opening-times-sid:last").val(sid);

            latestStartEndDate = thisStartEndDate;
        });

        $(".datepicker").datepicker();
    });

})();
