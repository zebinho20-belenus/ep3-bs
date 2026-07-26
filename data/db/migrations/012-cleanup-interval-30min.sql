-- Migration 012: Unpaid booking cleanup after 30 minutes, checked every 5 minutes.
--
-- Rationale: with a 3 hour grace period an abandoned PayPal checkout blocks the court long
-- enough to be played without ever being paid. 30 minutes keeps the court available and is
-- still long enough for the payment reminder (scripts/payments.php remind, sent after ~10 min)
-- to be acted upon.
--
-- Bookings whose PayPal payment is under review carry directpay='false' and are therefore
-- never touched by this event. Pay-later bills never get directpay='true' at all.

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
    );

-- Defaults for the payment CLI (overridable via bs_options).
-- bs_options.key has no unique index, so guard each insert instead of ON DUPLICATE KEY.
INSERT INTO bs_options (`key`, `value`)
SELECT 'service.payment.unpaid-grace-minutes', '30' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM bs_options WHERE `key` = 'service.payment.unpaid-grace-minutes');

INSERT INTO bs_options (`key`, `value`)
SELECT 'service.payment.reminder-after-minutes', '10' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM bs_options WHERE `key` = 'service.payment.reminder-after-minutes');

INSERT INTO bs_options (`key`, `value`)
SELECT 'service.payment.review-alert-days', '10' FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM bs_options WHERE `key` = 'service.payment.review-alert-days');
