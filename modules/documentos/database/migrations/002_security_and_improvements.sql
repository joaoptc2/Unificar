-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 002 — Segurança, performance e novos recursos                 ║
-- ║  Aplicar após 001_initial.sql                                           ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Tentativas de login (rate limiting)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`      VARCHAR(150)    DEFAULT NULL,
    `ip_address` VARCHAR(45)     NOT NULL,
    `success`    TINYINT(1)      NOT NULL DEFAULT 0,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_la_ip_created` (`ip_address`, `created_at`),
    INDEX `idx_la_email`      (`email`),
    INDEX `idx_la_created`    (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Tokens de recuperação de senha
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED    NOT NULL,
    `token_hash` CHAR(64)        NOT NULL,
    `ip_address` VARCHAR(45)     DEFAULT NULL,
    `expires_at` DATETIME        NOT NULL,
    `used_at`    DATETIME        DEFAULT NULL,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_pr_token` (`token_hash`),
    INDEX `idx_pr_user` (`user_id`),
    CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Users: força troca de senha no primeiro acesso / após reset admin
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `users`
    ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `is_active`;

-- Admin padrão deve ser forçado a trocar (caso instalação antiga)
UPDATE `users` SET `force_password_change` = 1
 WHERE `password` = '$2y$12$LJ3m4ys3Gzf0U5OUQqGxneFBjRCAJRo8Ahi/B1/4FpiWCiJfmGHKu';

-- ═══════════════════════════════════════════════════════════════════════════
-- 4. Documents: mime_type persistido + índice composto para dashboard
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `documents`
    ADD COLUMN `mime_type` VARCHAR(100) DEFAULT NULL AFTER `file_type`;

-- Índice composto acelera o dashboard (filtra por hospital + validade + deleted)
ALTER TABLE `documents`
    ADD INDEX `idx_docs_hosp_del_exp` (`hospital_id`, `deleted_at`, `expiration_date`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 5. Indicator_data: evita duplicidade na mesma data/indicador
-- ═══════════════════════════════════════════════════════════════════════════
-- Se houver duplicatas pré-existentes, remove-as primeiro (mantém mais recente).
DELETE d1 FROM `indicator_data` d1
INNER JOIN `indicator_data` d2
  ON d1.indicator_id = d2.indicator_id
 AND d1.reference_date = d2.reference_date
 AND d1.id < d2.id
 AND d1.deleted_at IS NULL AND d2.deleted_at IS NULL;

ALTER TABLE `indicator_data`
    ADD UNIQUE KEY `uk_inddata_indicator_date` (`indicator_id`, `reference_date`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 6. Users: unique key respeitando soft delete
-- ═══════════════════════════════════════════════════════════════════════════
-- Remove a unique key antiga (que impedia reutilização de e-mail após exclusão)
ALTER TABLE `users` DROP INDEX `uk_users_email_hospital`;
-- Adiciona índice simples (sem unique global) + trigger de validação no app
ALTER TABLE `users` ADD INDEX `idx_users_email_hospital` (`email`, `hospital_id`, `deleted_at`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 7. Index no audit_logs para limpeza rápida
-- ═══════════════════════════════════════════════════════════════════════════
-- Já existe idx_audit_date.

-- ═══════════════════════════════════════════════════════════════════════════
-- 8. Indicators: índice para busca por hospital + nome
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `indicators`
    ADD INDEX `idx_indicators_hosp_del_name` (`hospital_id`, `deleted_at`, `name`);
