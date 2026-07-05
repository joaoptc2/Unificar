-- Migration 002: Polls, custom emojis, channel categories, 2FA, export logs
-- Run on existing databases. schema.sql already includes these for new installs.

-- ----------------------------------------------------------------
-- ENQUETES / POLLS (#1)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `polls` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NULL,
    `user_id` INT UNSIGNED NULL,
    `question` VARCHAR(500) NOT NULL,
    `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0,
    `is_multiple` TINYINT(1) NOT NULL DEFAULT 0,
    `closes_at` DATETIME NULL,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_polls_channel` (`channel_id`),
    CONSTRAINT `fk_polls_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_polls_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_polls_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `text` VARCHAR(300) NOT NULL,
    `order_num` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_po_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `option_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_poll_vote` (`poll_id`, `option_id`, `user_id`),
    CONSTRAINT `fk_pv_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pv_option` FOREIGN KEY (`option_id`) REFERENCES `poll_options`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EMOJIS PERSONALIZADOS (#29)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `custom_emojis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `image_path` VARCHAR(500) NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ce_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- GRUPOS DE CANAIS / CATEGORIAS (#30)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channel_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `order_num` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_collapsed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cc_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `channels` ADD COLUMN `category_id` INT UNSIGNED NULL DEFAULT NULL AFTER `allow_threads`;
-- FK not added to avoid issues if channels table has no category_id yet

-- ----------------------------------------------------------------
-- 2FA (#9)
-- ----------------------------------------------------------------
ALTER TABLE `users` ADD COLUMN `two_factor_secret` VARCHAR(64) NULL DEFAULT NULL AFTER `is_active`;
ALTER TABLE `users` ADD COLUMN `two_factor_enabled` TINYINT(1) NOT NULL DEFAULT 0 AFTER `two_factor_secret`;
ALTER TABLE `users` ADD COLUMN `two_factor_recovery` TEXT NULL DEFAULT NULL AFTER `two_factor_enabled`;

-- ----------------------------------------------------------------
-- EXPORT LOGS (#11)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `export_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `type` VARCHAR(50) NOT NULL,
    `params` JSON NULL,
    `file_path` VARCHAR(500) NULL,
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    CONSTRAINT `fk_el_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FAVORITOS DE CANAIS (para sidebar) - suporte a #30
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channel_favorites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `channel_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_fav` (`user_id`, `channel_id`),
    CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fav_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
