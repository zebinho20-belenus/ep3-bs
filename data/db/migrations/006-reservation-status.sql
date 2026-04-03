ALTER TABLE `bs_reservations` ADD COLUMN `status` VARCHAR(16) NOT NULL DEFAULT 'confirmed' AFTER `time_end`;
