-- Migration 011: Scheduled cleanup for audit log (90 days retention)

CREATE EVENT IF NOT EXISTS cleanup_audit_log
ON SCHEDULE EVERY 1 DAY
STARTS CURRENT_DATE + INTERVAL 3 HOUR
DO
  DELETE FROM bs_audit_log WHERE created < DATE_SUB(NOW(), INTERVAL 90 DAY);
