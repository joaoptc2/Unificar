-- ============================================================
-- Migração 004 — Autenticação em duas etapas TOTP (P3.1)
-- ============================================================

CREATE TABLE IF NOT EXISTS `user_2fa` (
    `user_id` INT UNSIGNED PRIMARY KEY,
    `secret` VARCHAR(64) NOT NULL,
    `enabled` TINYINT(1) NOT NULL DEFAULT 0,
    `recovery_codes` TEXT NULL,
    `last_used_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_2fa_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
