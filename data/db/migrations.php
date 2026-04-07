<?php

/**
 * Database migration registry.
 *
 * Each migration has:
 * - 'name'  : Human-readable identifier
 * - 'check' : SQL query — if it returns rows, migration is already applied
 * - 'file'  : Path to SQL file (relative to application root)
 *
 * Migrations are executed in order of their numeric key.
 * The current schema version is stored in bs_options (key: 'schema.version').
 */
return [
    1 => [
        'name' => 'add-indexes',
        'check' => "SHOW INDEX FROM bs_reservations WHERE Key_name = 'idx_date_time'",
        'file' => 'data/db/migrations/001-add-indexes.sql',
    ],
    2 => [
        'name' => 'member-emails',
        'check' => "SHOW TABLES LIKE 'bs_member_emails'",
        'file' => 'data/db/migrations/002-member-emails.sql',
    ],
    3 => [
        'name' => 'cleanup-interval',
        'check' => "SELECT * FROM information_schema.EVENTS WHERE EVENT_NAME = 'remove_unpaid_bookings' AND INTERVAL_VALUE = '15' AND EVENT_SCHEMA = DATABASE()",
        'file' => 'data/db/migrations/003-cleanup-interval.sql',
    ],
    4 => [
        'name' => 'cleanup-interval-reset',
        'check' => "SELECT * FROM information_schema.EVENTS WHERE EVENT_NAME = 'remove_unpaid_bookings' AND INTERVAL_VALUE = '15' AND EVENT_SCHEMA = DATABASE()",
        'file' => 'data/db/migrations/004-cleanup-interval-reset.sql',
    ],
    5 => [
        'name' => 'opening-times',
        'check' => "SHOW TABLES LIKE 'bs_squares_opening_times'",
        'file' => 'data/db/migrations/005-opening-times.sql',
    ],
    6 => [
        'name' => 'reservation-status',
        'check' => "SHOW COLUMNS FROM bs_reservations LIKE 'status'",
        'file' => 'data/db/migrations/006-reservation-status.sql',
    ],
    7 => [
        'name' => 'remove-legacy-md5',
        'check' => "SELECT 1 FROM bs_users_meta WHERE `key` = 'legacy-pw' HAVING COUNT(*) = 0",
        'file' => 'data/db/migrations/007-remove-legacy-md5.sql',
    ],
    8 => [
        'name' => 'convert-serialized-player-names',
        'check' => "SELECT 1 FROM bs_bookings_meta WHERE `key` = 'player-names' AND value LIKE 'a:%' HAVING COUNT(*) = 0",
        'file' => 'data/db/migrations/008-convert-serialized-player-names.sql',
    ],
    9 => [
        'name' => 'add-performance-indexes',
        'check' => "SHOW INDEX FROM bs_bookings WHERE Key_name = 'idx_uid_status'",
        'file' => 'data/db/migrations/009-add-performance-indexes.sql',
    ],
    10 => [
        'name' => 'audit-log',
        'check' => "SHOW TABLES LIKE 'bs_audit_log'",
        'file' => 'data/db/migrations/010-audit-log.sql',
    ],
    11 => [
        'name' => 'audit-log-cleanup',
        'check' => "SELECT * FROM information_schema.EVENTS WHERE EVENT_NAME = 'cleanup_audit_log' AND EVENT_SCHEMA = DATABASE()",
        'file' => 'data/db/migrations/011-audit-log-cleanup.sql',
    ],
];