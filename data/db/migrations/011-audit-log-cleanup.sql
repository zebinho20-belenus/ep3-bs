-- Migration 011: Scheduled cleanup for audit log (configurable retention)
-- Retention days configurable via bs_options key 'service.audit.retention-days' (default 90)

CREATE EVENT IF NOT EXISTS cleanup_audit_log
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 3 HOUR
DO
  DELETE FROM bs_audit_log WHERE created < DATE_SUB(NOW(), INTERVAL
    COALESCE((SELECT CAST(value AS UNSIGNED) FROM bs_options WHERE `key` = 'service.audit.retention-days' LIMIT 1), 90)
  DAY);
