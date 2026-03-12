-- Migration 003: Shorten unpaid booking cleanup interval to 3 minutes (testing) (#85)
-- TEMPORARY: Revert to 3 HOUR after testing is complete

DROP EVENT IF EXISTS remove_unpaid_bookings;

CREATE EVENT remove_unpaid_bookings
ON SCHEDULE EVERY 1 MINUTE
ON COMPLETION PRESERVE
DO DELETE FROM bs_bookings
  WHERE `status` = 'single'
    AND `status_billing` = 'pending'
    AND created < (NOW() - INTERVAL 3 MINUTE)
    AND bid IN (
      SELECT bid FROM bs_bookings_meta
      WHERE `key` = 'directpay' AND `value` = 'true'
    );
