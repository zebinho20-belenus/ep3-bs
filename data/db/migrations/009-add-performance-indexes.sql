-- Migration 009: Additional indexes for performance optimization

-- bs_bookings: composite index for user + status queries (LastBookings, AccountController)
ALTER TABLE bs_bookings ADD INDEX idx_uid_status (uid, status);

-- bs_reservations: index for the status column (added in migration 006)
ALTER TABLE bs_reservations ADD INDEX idx_reservation_status (status);

-- Meta tables: unique constraints prevent duplicate keys and speed up lookups
ALTER TABLE bs_reservations_meta ADD UNIQUE INDEX uniq_rid_key (rid, `key`);
ALTER TABLE bs_squares_meta ADD UNIQUE INDEX uniq_sid_key_locale (sid, `key`, locale);

-- bs_squares_pricing: composite index for date-range + priority lookups
ALTER TABLE bs_squares_pricing ADD INDEX idx_date_priority (date_start, date_end, priority);
