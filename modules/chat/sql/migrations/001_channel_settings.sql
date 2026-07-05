-- Migration 001: Channel settings and message retention
ALTER TABLE `channels` ADD COLUMN `is_readonly` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_general`;
ALTER TABLE `channels` ADD COLUMN `retention_days` INT UNSIGNED NULL DEFAULT NULL AFTER `is_readonly`;
ALTER TABLE `channels` ADD COLUMN `slow_mode_seconds` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `retention_days`;
ALTER TABLE `channels` ADD COLUMN `max_pinned` INT UNSIGNED NOT NULL DEFAULT 50 AFTER `slow_mode_seconds`;
ALTER TABLE `channels` ADD COLUMN `allow_threads` TINYINT(1) NOT NULL DEFAULT 1 AFTER `max_pinned`;
