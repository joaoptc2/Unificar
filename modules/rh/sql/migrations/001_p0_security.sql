-- ============================================================
-- Migração 001 — Melhorias de Segurança P0
-- Aplicar em instalações já existentes (novas instalações já incluem via schema.sql).
-- ============================================================

-- Tabela para rate-limit de login
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `email` VARCHAR(200) NULL,
    `success` TINYINT(1) NOT NULL DEFAULT 0,
    `user_agent` VARCHAR(500) NULL,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_login_ip` (`ip_address`, `attempted_at`),
    KEY `idx_login_email` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Tabela para rate-limit do formulário público de vagas
CREATE TABLE IF NOT EXISTS `public_submissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `job_id` INT UNSIGNED NULL,
    `email` VARCHAR(200) NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pub_ip` (`ip_address`, `submitted_at`),
    KEY `idx_pub_email` (`email`, `job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
