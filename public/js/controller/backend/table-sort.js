(function ($) {
    'use strict';

    if (window._tableSortInit) return;
    window._tableSortInit = true;

    $(function () {
        // --- Sortable headers ---
        $('th[data-sort-type]').each(function () {
            var $th = $(this);
            if ($th.data('sort-type') === 'none') return;
            $th.addClass('sortable');
        });

        $('th.sortable').on('click', function (e) {
            // Ignore clicks on the filter input
            if ($(e.target).hasClass('col-filter')) return;

            var $th   = $(this);
            var table = $th.closest('table')[0];
            var tbody = table.tBodies[0];
            if (!tbody) return;

            var colIdx = $th.index();
            var type   = $th.data('sort-type') || 'text';
            var asc    = !$th.hasClass('sort-asc');

            // Reset siblings
            $th.closest('tr').find('th').removeClass('sort-asc sort-desc');
            $th.addClass(asc ? 'sort-asc' : 'sort-desc');

            var rows = Array.prototype.slice.call(tbody.rows);

            rows.sort(function (a, b) {
                var cellA = a.cells[colIdx];
                var cellB = b.cells[colIdx];
                var valA  = (cellA ? cellA.textContent : '').trim();
                var valB  = (cellB ? cellB.textContent : '').trim();

                var cmp = compare(valA, valB, type);
                return asc ? cmp : -cmp;
            });

            for (var i = 0; i < rows.length; i++) {
                tbody.appendChild(rows[i]);
            }
        });

        // --- Per-column filter inputs ---
        $('table').has('th.sortable').each(function () {
            var $table = $(this);
            var $thead = $table.find('thead');
            var $headerRow = $thead.find('tr').first();
            var $filterRow = $('<tr class="filter-row no-print"></tr>');

            $headerRow.find('th').each(function () {
                var $th = $(this);
                var sortType = $th.data('sort-type');
                var $td = $('<td></td>');

                if (sortType && sortType !== 'none') {
                    var $input = $('<input type="text" class="col-filter form-control form-control-sm" placeholder="Filter...">');
                    $input.on('input', function () {
                        applyFilters($table);
                    });
                    $td.append($input);
                }
                $filterRow.append($td);
            });

            $thead.append($filterRow);
        });
    });

    function applyFilters($table) {
        var filters = [];
        $table.find('.filter-row .col-filter').each(function () {
            var $input = $(this);
            var colIdx = $input.closest('td').index();
            var val = $input.val().toLowerCase().trim();
            if (val) {
                filters.push({ col: colIdx, text: val });
            }
        });

        $table.find('tbody tr').each(function () {
            var $row = $(this);
            var show = true;

            for (var i = 0; i < filters.length; i++) {
                var cell = $row[0].cells[filters[i].col];
                var cellText = (cell ? cell.textContent : '').toLowerCase();
                if (cellText.indexOf(filters[i].text) === -1) {
                    show = false;
                    break;
                }
            }

            $row.toggle(show);
        });
    }

    function compare(a, b, type) {
        switch (type) {
            case 'number':
            case 'price':
                return toNumber(a) - toNumber(b);
            case 'date-de':
                return parseDateDe(a) - parseDateDe(b);
            default: // text
                return a.localeCompare(b, 'de', { sensitivity: 'base' });
        }
    }

    function toNumber(s) {
        // German format: 1.500,00 EUR → 1500.00
        s = s.replace(/[^\d.,\-]/g, '');
        s = s.replace(/\./g, '');
        s = s.replace(',', '.');
        var n = parseFloat(s);
        return isNaN(n) ? 0 : n;
    }

    function parseDateDe(s) {
        // "16.07.2026" or "16.07.2026, 08:00"
        var m = s.match(/(\d{2})\.(\d{2})\.(\d{4})/);
        if (!m) return 0;
        var d = new Date(parseInt(m[3], 10), parseInt(m[2], 10) - 1, parseInt(m[1], 10));
        var t = s.match(/(\d{2}):(\d{2})/);
        if (t) {
            d.setHours(parseInt(t[1], 10), parseInt(t[2], 10));
        }
        return d.getTime();
    }

})(jQuery);
