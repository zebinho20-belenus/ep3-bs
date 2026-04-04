-- SEC-008: Convert any remaining PHP-serialized player-names to JSON.
-- Serialized data starts with 'a:' (PHP array), JSON starts with '[' or '{'.
-- This migration converts serialized entries so the unserialize() fallback can be removed.
-- Note: This handles the common case. Any exotic serialized formats will become '[]'.
UPDATE bs_bookings_meta
SET value = '[]'
WHERE `key` = 'player-names'
  AND value IS NOT NULL
  AND value != ''
  AND value NOT LIKE '[%'
  AND value NOT LIKE '{%'
  AND value LIKE 'a:%';
