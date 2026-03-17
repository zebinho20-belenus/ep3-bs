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
        $(document).on("updateLayout", updateCalendarCols);

        /* Update calendar events */

        updateCalendarEvents();
        $(document).on("updateLayout", updateCalendarEvents);

        /* Debounced resize: hide overlays immediately, recalculate after settle */
        var resizeTimer;
        function debouncedCalendarUpdate() {
            $(".calendar-event-overlay").hide();
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function() {
                updateCalendarCols();
                updateCalendarEvents();
            }, 150);
        }
        $(window).on("resize orientationchange", debouncedCalendarUpdate);

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
        $(".calendar-event-overlay").remove();

        $(".calendar-date-col").each(function(dateIndex) {
            var calendarDateCol = $(this);
            var dateWrapper = calendarDateCol.find(".calendar-date-wrapper");
            if (!dateWrapper.length) return;

            var wrapperEl = dateWrapper[0];

            // Helper: get position of a td relative to dateWrapper using getBoundingClientRect
            function tdRect(td) {
                var wR = wrapperEl.getBoundingClientRect();
                var tR = td[0].getBoundingClientRect();
                return {
                    left:   tR.left   - wR.left,
                    top:    tR.top    - wR.top,
                    right:  tR.right  - wR.left,
                    bottom: tR.bottom - wR.top,
                    width:  tR.width,
                    height: tR.height
                };
            }

            // Helper: create and position one overlay
            function createOverlay(id, sourceCell, x, y, w, h) {
                if (w <= 0 || h <= 0) return null;
                var overlay = sourceCell.clone();
                overlay.appendTo(dateWrapper);
                overlay.attr("id", id);
                overlay.addClass("calendar-event-overlay");
                overlay.removeClass(function(i, c) { return (c.match(/cc-group-\d+/) || []).join(' '); });
                overlay.css({
                    "position": "absolute", "z-index": 256,
                    "display": "block",
                    "left": Math.round(x), "top": Math.round(y),
                    "width": Math.round(w), "height": Math.round(h),
                    "padding": 0, "text-align": "center",
                    "pointer-events": "auto"
                });
                var label = overlay.find(".cc-label");
                label.css({
                    "visibility": "visible",
                    "height": "auto",
                    "font-size": "12px",
                    "line-height": 1.5,
                    "text-align": "center",
                    "position": "relative"
                });
                // Vertically center label after it's in DOM
                setTimeout(function() {
                    label.css("top", Math.round((h / 2) - (label.outerHeight() / 2)));
                }, 0);
                return overlay;
            }

            // Collect unique event groups
            var eventGroups = [];
            calendarDateCol.find(".cc-event").each(function() {
                var m = $(this).attr("class").match(/cc-group-\d+/);
                if (m && $.inArray(m[0], eventGroups) === -1) {
                    eventGroups.push(m[0]);
                }
            });

            for (var i = 0; i < eventGroups.length; i++) {
                var eventGroup = eventGroups[i];

                // Hide original cell labels
                calendarDateCol.find("." + eventGroup + " .cc-label").css({
                    "visibility": "hidden", "font-size": "0"
                });

                // Group cells by column index
                var cellsByCol = {};
                calendarDateCol.find("." + eventGroup).each(function() {
                    var cell = $(this);
                    var td = cell.closest("td");
                    var idx = td.index();
                    if (!cellsByCol[idx]) cellsByCol[idx] = [];
                    cellsByCol[idx].push(cell);
                });

                var colKeys = Object.keys(cellsByCol);
                if (!colKeys.length) continue;

                if (colKeys.length > 1) {
                    // Multi-column: one wide overlay spanning all columns
                    var firstColCells = cellsByCol[colKeys[0]];
                    var lastColCells  = cellsByCol[colKeys[colKeys.length - 1]];
                    if (!firstColCells.length) continue;

                    var r0 = tdRect(firstColCells[0].closest("td"));
                    var r1 = tdRect(lastColCells[lastColCells.length - 1].closest("td"));
                    var rB = tdRect(firstColCells[firstColCells.length - 1].closest("td"));

                    createOverlay(
                        eventGroup + "-wide-overlay-" + dateIndex,
                        firstColCells[0],
                        r0.left, r0.top,
                        r1.right - r0.left,
                        rB.bottom - r0.top
                    );
                } else {
                    // Single-column: one overlay per time-slot group
                    $.each(cellsByCol, function(colIdx, cells) {
                        if (cells.length < 2) return;
                        var r0 = tdRect(cells[0].closest("td"));
                        var rL = tdRect(cells[cells.length - 1].closest("td"));
                        createOverlay(
                            eventGroup + "-c" + colIdx + "-overlay-" + dateIndex,
                            cells[0],
                            r0.left, r0.top,
                            r0.width,
                            rL.bottom - r0.top
                        );
                    });

                    // Multiple per-column overlays: show label only in middle one
                    var allOvl = $("[id^='" + eventGroup + "-c'][id$='-overlay-" + dateIndex + "']");
                    if (allOvl.length > 1) {
                        allOvl.find(".cc-label").css("visibility", "hidden");
                        allOvl.eq(Math.floor(allOvl.length / 2)).find(".cc-label").css("visibility", "visible");
                    }
                }
            }
        });
    }

})();