-- Migration 004: Reset unpaid booking cleanup to production values (3 HOUR / 15 MIN) (#85)

DROP EVENT IF EXISTS remove_unpaid_bookings;

CREATE EVENT remove_unpaid_bookings
ON SCHEDULE EVERY 15 MINUTE
ON COMPLETION PRESERVE
DO DELETE FROM bs_bookings
  WHERE `status` = 'single'
    AND `status_billing` = 'pending'
    AND created < (NOW() - INTERVAL 3 HOUR)
    AND bid IN (
      SELECT bid FROM bs_bookings_meta
      WHERE `key` = 'directpay' AND `value` = 'true'
    );
