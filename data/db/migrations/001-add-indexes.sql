-- Migration 001: Add composite indexes for performance
-- Run: docker compose exec -T mariadb mariadb -u <user> -p<password> <database> < data/db/migrations/001-add-indexes.sql

-- bs_reservations: speed up date-range lookups (calendar view, backend booking list)
ALTER TABLE bs_reservations ADD INDEX idx_date_time (date, time_start, time_end);
ALTER TABLE bs_reservations ADD INDEX idx_bid_date (bid, date, time_start);

-- bs_bookings: speed up status filtering (backend booking list, auto-cleanup event)
ALTER TABLE bs_bookings ADD INDEX idx_status (status);
ALTER TABLE bs_bookings ADD INDEX idx_status_billing (status_billing);

-- Meta tables: unique constraint prevents duplicate key entries
ALTER TABLE bs_bookings_meta ADD UNIQUE INDEX uniq_bid_key (bid, `key`);
ALTER TABLE bs_users_meta ADD UNIQUE INDEX uniq_uid_key (uid, `key`);
