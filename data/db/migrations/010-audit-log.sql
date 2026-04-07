-- Migration 010: Audit log table

CREATE TABLE IF NOT EXISTS bs_audit_log (
    alid INT UNSIGNED NOT NULL AUTO_INCREMENT,
    created TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    category VARCHAR(32) NOT NULL,
    action VARCHAR(64) NOT NULL,
    user_id INT UNSIGNED DEFAULT NULL,
    user_name VARCHAR(64) DEFAULT NULL,
    entity_type VARCHAR(32) DEFAULT NULL,
    entity_id INT UNSIGNED DEFAULT NULL,
    message VARCHAR(512) NOT NULL,
    detail TEXT DEFAULT NULL,
    ip VARCHAR(45) DEFAULT NULL,
    PRIMARY KEY (alid),
    INDEX idx_created (created),
    INDEX idx_category_action (category, action),
    INDEX idx_user_id (user_id),
    INDEX idx_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
