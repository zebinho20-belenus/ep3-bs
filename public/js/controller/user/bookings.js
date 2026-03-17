$(function () {

    $('#bookings-filter input[name="bookings-filter"]').on('change', function () {
        var filter = $(this).val();
        var visibleCount = 0;

        $('#bookings-table .booking-row').each(function () {
            var show = (filter === 'all')
                || (filter === 'upcoming' && $(this).data('time') === 'future')
                || (filter === 'pending' && $(this).data('status') === 'pending');

            $(this).attr('data-filtered', show ? 'false' : 'true');
            if (show) visibleCount++;
        });

        // Separator only visible when showing all
        $('#bookings-table .separator-row').attr('data-filtered', filter === 'all' ? 'false' : 'true');

        // No results message
        if (visibleCount === 0) {
            $('#bookings-table .no-results-row').show();
        } else {
            $('#bookings-table .no-results-row').hide();
        }
    });

});
