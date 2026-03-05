-- Migration 002: Member email list for auto-registration (#17)
-- Run: docker compose exec -T mariadb mariadb -u <user> -p<password> <database> < data/db/migrations/002-member-emails.sql

CREATE TABLE IF NOT EXISTS `bs_member_emails` (
  `meid` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `firstname` varchar(255) DEFAULT NULL,
  `lastname` varchar(255) DEFAULT NULL,
  `created` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`meid`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;