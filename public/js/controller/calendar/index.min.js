(function() {

    var calendar;

    var squarebox;
    var squareboxShutdown = false;

    var squareboxOverlay;

    var loadingDelay = 300;
    var animationDuration = 350;

    $(document).ready(function() {

        calendar = $(".calendar-table");

        /* Squarebox */

        calendar.on("click", "a.calendar-cell", function(event) {
            var that = $(this);

            if (! that.hasClass("squarebox-external-link")) {
                event.preventDefault();

                if (!squarebox) {
                    event.stopPropagation();

                    loadSquarebox(that.attr("href"));
                }
            }
        });

        $(window).resize(updateSquarebox);

        $(window).on("squarebox.update", updateSquarebox);

        $("body").on("click", "#squarebox-overlay", function() {
            removeSquarebox();
        });

        /* Group highlighting */

        $("a.calendar-cell").hover(function() {
            var that = $(this);
            var classes = that.attr("class");
            var group = classes.match(/cc-group-\d+/);

            if (group) {
                var groupMembers = $("a." + group);

                groupMembers.each(function() {
                    $(this).data("original-style", $(this).attr("style"));
                });

                groupMembers.css({"opacity": 0.9, "background-color": that.css("background-color")});

                that.css("opacity", 1.0);
            }
        }, function() {
            var that = $(this);
            var classes = that.attr("class");
            var group = classes.match(/cc-group-\d+/);

            if (group) {
                var groupMembers = $("a." + group);

                groupMembers.each(function() {
                    $(this).attr("style", $(this).data("original-style"));
                });
            }
        });

        /* Update calendar */

        updateCalendarCols();
        $(window).resize(updateCalendarCols);
        $(document).on("updateLayout", updateCalendarCols);

        /* Update calendar events */

        updateCalendarEvents();
        $(window).resize(updateCalendarEvents);
        $(document).on("updateLayout", updateCalendarEvents);

    });

    function loadSquarebox(href)
    {
        var calendarSquareboxTemplate = $("#calendar-squarebox-template");

        if (calendarSquareboxTemplate.length) {
            populateSquarebox( calendarSquareboxTemplate.html() );
        } else {
            populateSquarebox('<div class="padded">...</p>');
        }

        squarebox.clearQueue().delay(loadingDelay).queue(function() {
            $.ajax({
                "cache": false,
                "data": { "ajax": true },
                "dataType": "html",
                "error": function() {
                    if (squarebox && ! squareboxShutdown) {
                        window.location.href = href;
                    }
                },
                "success": function (data) {
                    if (squarebox && ! squareboxShutdown) {
                        populateSquarebox(data);

                        squarebox.find(".no-ajax").remove();

                        /* Initialize booking edit form (#91) */
                        if (typeof window.initBookingEditForm === 'function') {
                            window.initBookingEditForm(squarebox[0]);
                        } else {
                            squarebox.find(".datepicker").datepicker();
                        }

                        squarebox.find(".inline-label-container").each(function() {
                            updateInlineLabel( $(this) );
                        });

                        squarebox.prepend('<a href="#" class="squarebox-primary-close-link squarebox-close-link">&times;</a>');

                        updateSquarebox();

                        /* Recognize squarebox internal links */

                        squarebox.on("click", "a.squarebox-internal-link", function(event) {
                            event.preventDefault();

                            loadSquarebox( $(this).attr("href") );
                        });

                        /* Recognize squarebox close links */

                        squarebox.on("click", "a.squarebox-close-link", function(event) {
                            event.preventDefault();

                            removeSquarebox();
                        });
                    }
                },
                "url": href
            });

            $(this).dequeue();
        });
    }


     function prepareSquarebox()
        {
            if (! squareboxOverlay) {
                if (window.matchMedia('(min-width: 1024px)').matches) {
                    squareboxOverlay = $('<div id="squarebox-overlay"></div>').css({
                        "position": "absolute",
                        "z-index": 1532,
                        "opacity": 0.00,
                        "width": $(document).width(), "height": $(document).height(),
                        "left": 0, "top": 0,
                        "background": "#333"
                    });
                } else {
                    squareboxOverlay = $('<div id="squarebox-overlay"></div>').css({
                        "position": "fixed",
                        "z-index": 1532,
                        "opacity": 0.00,
                        "width": "100%", "height": "100%",
                        "left": 0, "top": 0,
                        "background": "#333"
                    });
                }
                $("body").prepend(squareboxOverlay);
            }


        if (! squarebox) {
            if (window.matchMedia('(min-width: 1024px)').matches) {
                squarebox = $('<div class="panel squarebox-desktop"></div>').css({
                    "position": "absolute",
                    "z-index": 1536,
                    "max-width": "720px",
                    "max-height": "90vh",
                    "overflow-y": "auto",
                    "transition": "top 0.3s ease, left 0.3s ease"
                });
            } else {
                squarebox = $('<div class="panel squarebox-mobile"></div>').css({
                    "position": "fixed",
                    "z-index": 1536,
                    "top": "5vh",
                    "left": "5vw",
                    "width": "90vw",
                    "max-height": "90vh",
                    "overflow-y": "auto",
                    "-webkit-overflow-scrolling": "touch"
                });
            }
            $("body").prepend(squarebox);
        }
    }

    function populateSquarebox(content)
    {
        prepareSquarebox();

        squarebox.clearQueue();
        squarebox.css("opacity", 0.01);
        squarebox.html(content);

        updateSquarebox();

        squarebox.fadeTo(animationDuration, 1.00);

        fadeOutContent();
    }

    function updateSquarebox()
    {
        if (squarebox) {
            /* On mobile, position: fixed handles centering via CSS */
            if (squarebox.hasClass("squarebox-mobile")) {
                return;
            }

            squarebox.position({
                "my": "center",
                "at": "center",
                "of": window,
                "collision": "fit"
            });
        }
    }

    function removeSquarebox()
    {
        if (squarebox) {
            squareboxShutdown = true;

            squarebox.clearQueue().fadeOut(animationDuration, function() {
                if (squarebox) {
                    squarebox.remove();
                    squarebox = undefined;
                }

                squareboxShutdown = false;
            });

            fadeInContent();
        }
    }

    function fadeOutContent()
    {
        if (squareboxOverlay) {
            squareboxOverlay.clearQueue().fadeTo(animationDuration, 0.75);
        }
    }

    function fadeInContent()
    {
        if (squareboxOverlay) {
            squareboxOverlay.clearQueue().fadeTo(animationDuration, 0.00, function() {
                if (squareboxOverlay) {
                    squareboxOverlay.remove();
                    squareboxOverlay = undefined;
                }
            });
        }
    }

    function updateCalendarCols()
    {
        var calendarWidth = $("#calendar").width();
        var calendarLegendColWidth = $(".calendar-time-col, .calendar-square-col").width();

        var calendarDateCols = $(".calendar-date-col:visible");

        if (calendarWidth && calendarLegendColWidth && calendarDateCols.length) {
            calendarDateCols.width( Math.floor((calendarWidth - calendarLegendColWidth) / calendarDateCols.length) );
        }
    }

    function updateCalendarEvents()
    {
        // Remove all existing overlays before recreating
        $("[id$='-overlay-']").remove();

        $(".calendar-date-col").each(function(dateIndex) {
            var calendarDateCol = $(this);
            var dateWrapper = calendarDateCol.find(".calendar-date-wrapper");

            if (! dateWrapper.length) return;

            // Collect unique event groups
            var eventGroups = [];

            calendarDateCol.find(".cc-event").each(function() {
                var classes = $(this).attr("class");
                var eventGroupMatch = classes.match(/cc-group-\d+/);

                if (eventGroupMatch) {
                    var eventGroupName = eventGroupMatch[0];
                    if ($.inArray(eventGroupName, eventGroups) === -1) {
                        eventGroups.push(eventGroupName);
                    }
                }
            });

            // For each event group, create one overlay per court column
            for (var i = 0; i < eventGroups.length; i++) {
                var eventGroup = eventGroups[i];

                // Group cells by their column index (= court)
                var cellsByCol = {};

                calendarDateCol.find("." + eventGroup).each(function() {
                    var cell = $(this);
                    var td = cell.closest("td");
                    var colIndex = td.index();

                    if (! cellsByCol[colIndex]) {
                        cellsByCol[colIndex] = [];
                    }
                    cellsByCol[colIndex].push(cell);
                });

                // Create overlays: one wide overlay for multi-column events,
                // or one overlay per column for single-column events
                var wrapperOffset = dateWrapper.offset();
                var colKeys = Object.keys(cellsByCol);

                if (colKeys.length > 1) {
                    // Multi-column event: one wide overlay spanning all columns
                    var firstColCells = cellsByCol[colKeys[0]];
                    var lastColCells = cellsByCol[colKeys[colKeys.length - 1]];

                    if (firstColCells.length < 2) continue; // safety

                    var firstCell = firstColCells[0];
                    var lastCell = lastColCells[lastColCells.length - 1];
                    var firstColLastCell = firstColCells[firstColCells.length - 1];

                    var firstTd = firstCell.closest("td");
                    var lastTd = lastCell.closest("td");
                    var firstColLastTd = firstColLastCell.closest("td");

                    var firstTdOff = firstTd.offset();
                    var lastTdOff = lastTd.offset();
                    var firstColLastTdOff = firstColLastTd.offset();

                    var startX = Math.floor(firstTdOff.left - wrapperOffset.left);
                    var startY = Math.floor(firstTdOff.top - wrapperOffset.top);
                    var eventWidth = Math.round((lastTdOff.left + lastTd.outerWidth()) - firstTdOff.left);
                    var eventHeight = Math.round((firstColLastTdOff.top + firstColLastTd.outerHeight()) - firstTdOff.top);

                    var overlayId = eventGroup + "-wide-overlay-" + dateIndex;

                    var eventGroupOverlay = firstCell.clone();
                    eventGroupOverlay.appendTo(dateWrapper);
                    eventGroupOverlay.attr("id", overlayId);
                    eventGroupOverlay.removeClass(eventGroup);

                    var eventGroupOverlayLabel = eventGroupOverlay.find(".cc-label");

                    eventGroupOverlay.css({
                        "position": "absolute",
                        "z-index": 128,
                        "display": "block",
                        "left": startX, "top": startY,
                        "width": eventWidth,
                        "height": eventHeight,
                        "padding": 0,
                        "text-align": "center"
                    });

                    eventGroupOverlayLabel.css({
                        "height": "auto",
                        "font-size": "12px",
                        "line-height": 1.5,
                        "text-align": "center"
                    });

                    eventGroupOverlayLabel.css({
                        "position": "relative",
                        "top": Math.round((eventHeight / 2) - (eventGroupOverlayLabel.height() / 2))
                    });
                } else {
                    // Single-column event: one overlay per column
                    $.each(cellsByCol, function(colIndex, cells) {
                        if (cells.length < 2) return; // single cell needs no overlay

                        var firstCell = cells[0];
                        var lastCell = cells[cells.length - 1];

                        var firstTd = firstCell.closest("td");
                        var lastTd = lastCell.closest("td");
                        var firstTdOff = firstTd.offset();
                        var lastTdOff = lastTd.offset();

                        var startX = Math.floor(firstTdOff.left - wrapperOffset.left);
                        var startY = Math.floor(firstTdOff.top - wrapperOffset.top);
                        var eventWidth = firstTd.outerWidth();
                        var eventHeight = Math.round((lastTdOff.top + lastTd.outerHeight()) - firstTdOff.top);

                        var overlayId = eventGroup + "-c" + colIndex + "-overlay-" + dateIndex;

                        var eventGroupOverlay = firstCell.clone();
                        eventGroupOverlay.appendTo(dateWrapper);
                        eventGroupOverlay.attr("id", overlayId);
                        eventGroupOverlay.removeClass(eventGroup);

                        var eventGroupOverlayLabel = eventGroupOverlay.find(".cc-label");

                        eventGroupOverlay.css({
                            "position": "absolute",
                            "z-index": 128,
                            "left": startX, "top": startY,
                            "width": eventWidth,
                            "height": eventHeight,
                            "padding": 0
                        });

                        eventGroupOverlayLabel.css({
                            "height": "auto",
                            "font-size": "12px",
                            "line-height": 1.5
                        });

                        eventGroupOverlayLabel.css({
                            "position": "relative",
                            "top": Math.round((eventHeight / 2) - (eventGroupOverlayLabel.height() / 2))
                        });
                    });
                }
            }
        });
    }

})();