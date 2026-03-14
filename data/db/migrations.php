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
];