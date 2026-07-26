-- Migration 013: unpaid-booking cleanup only deletes bookings whose court time is still ahead.
--
-- Purpose of the event is to free a court blocked by an abandoned checkout. A booking whose
-- reservation is already in the past cannot be freed any more — deleting it only destroys the
-- record the club needs to collect the money (DELETE cascades to bs_reservations,
-- bs_bookings_bills and bs_bookings_meta). Those stay 'pending' and are reported by
-- `diagnose.php` (payment.stuck-pending) instead.
--
-- Discovered when enabling event_scheduler on production for the first time: the pending queue
-- held five unpaid bookings from Feb 2025 onwards (102 EUR total) that the unguarded event
-- would have erased in its first run.

DROP EVENT IF EXISTS remove_unpaid_bookings;

CREATE EVENT remove_unpaid_bookings
ON SCHEDULE EVERY 5 MINUTE
ON COMPLETION PRESERVE
DO DELETE FROM bs_bookings
  WHERE `status` = 'single'
    AND `status_billing` = 'pending'
    AND created < (NOW() - INTERVAL 30 MINUTE)
    AND bid IN (
      SELECT bid FROM bs_bookings_meta
      WHERE `key` = 'directpay' AND `value` = 'true'
    )
    AND NOT EXISTS (
      SELECT 1 FROM bs_reservations r
      WHERE r.bid = bs_bookings.bid AND r.date < CURDATE()
    );
