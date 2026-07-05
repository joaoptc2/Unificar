-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 006 — Setores (departamentos/unidades)                        ║
-- ║  Substitui a lógica multi-hospital por hospital único + múltiplos setores║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Tabela de setores
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `sectors` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED    NOT NULL,
    `name`        VARCHAR(150)    NOT NULL,
    `code`        VARCHAR(30)     DEFAULT NULL COMMENT 'Sigla: UTI, CC, PS, etc',
    `description` TEXT            DEFAULT NULL,
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_sectors_hospital` (`hospital_id`),
    INDEX `idx_sectors_active` (`is_active`, `deleted_at`),
    CONSTRAINT `fk_sectors_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Associação usuário ↔ setor (muitos-para-muitos)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `user_sectors` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`   INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_user_sector` (`user_id`, `sector_id`),
    INDEX `idx_us_sector` (`sector_id`),
    CONSTRAINT `fk_us_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_us_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Setor nos documentos e indicadores (opcional, para filtro)
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `documents` ADD COLUMN `sector_id` INT UNSIGNED DEFAULT NULL AFTER `hospital_id`;
ALTER TABLE `documents` ADD INDEX `idx_docs_sector` (`sector_id`);

ALTER TABLE `indicators` ADD COLUMN `sector_id` INT UNSIGNED DEFAULT NULL AFTER `hospital_id`;
ALTER TABLE `indicators` ADD INDEX `idx_indicators_sector` (`sector_id`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 4. Setor padrão "Geral" para o primeiro hospital ativo
-- ═══════════════════════════════════════════════════════════════════════════
INSERT INTO `sectors` (`hospital_id`, `name`, `code`, `description`)
SELECT `id`, 'Geral', 'GERAL', 'Setor padrão do hospital'
FROM `hospitals`
WHERE `deleted_at` IS NULL AND `is_active` = 1
LIMIT 1;

-- Associa todos os usuários existentes ao setor "Geral"
INSERT IGNORE INTO `user_sectors` (`user_id`, `sector_id`)
SELECT u.id, s.id
FROM `users` u
CROSS JOIN `sectors` s
WHERE u.deleted_at IS NULL AND s.code = 'GERAL'
LIMIT 1000;
