-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 005 — Indicadores v3 + Histórico de Documentos              ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Indicators: benchmark, responsável, acreditação
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `indicators`
    ADD COLUMN `benchmark_value`   DECIMAL(15,4) DEFAULT NULL
        COMMENT 'Referência externa (ex: ANVISA, ANAHP)' AFTER `goal_tolerance`,
    ADD COLUMN `benchmark_source`  VARCHAR(200) DEFAULT NULL
        COMMENT 'Origem do benchmark' AFTER `benchmark_value`,
    ADD COLUMN `responsible_user_id` INT UNSIGNED DEFAULT NULL
        COMMENT 'Usuário responsável pelo acompanhamento' AFTER `benchmark_source`,
    ADD COLUMN `accreditation`     VARCHAR(200) DEFAULT NULL
        COMMENT 'Acreditações: ONA, JCI, ANVISA, etc' AFTER `responsible_user_id`,
    ADD COLUMN `template_slug`     VARCHAR(100) DEFAULT NULL
        COMMENT 'Template usado na criação, para rastreabilidade' AFTER `accreditation`;

-- ALTER ENUM com suporte a mais periodicidades
ALTER TABLE `indicators`
    MODIFY COLUMN `type` ENUM('daily','weekly','monthly','quarterly','semester','yearly')
                  NOT NULL DEFAULT 'monthly';

-- FK para usuário responsável (opcional)
ALTER TABLE `indicators`
    ADD CONSTRAINT `fk_indicators_responsible`
        FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `indicators`
    ADD INDEX `idx_indicators_responsible` (`responsible_user_id`),
    ADD INDEX `idx_indicators_accreditation` (`accreditation`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Planos de ação vinculados a indicadores
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicator_actions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `indicator_id` INT UNSIGNED NOT NULL,
    `data_id`      INT UNSIGNED DEFAULT NULL COMMENT 'Opcional: lançamento que originou',
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `responsible`  VARCHAR(150) DEFAULT NULL,
    `due_date`     DATE         DEFAULT NULL,
    `status`       ENUM('pending','in_progress','done','cancelled') NOT NULL DEFAULT 'pending',
    `completed_at` DATETIME     DEFAULT NULL,
    `created_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_ia_indicator` (`indicator_id`),
    INDEX `idx_ia_status` (`status`),
    INDEX `idx_ia_due` (`due_date`),
    CONSTRAINT `fk_ia_indicator` FOREIGN KEY (`indicator_id`)
        REFERENCES `indicators` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_ia_data` FOREIGN KEY (`data_id`)
        REFERENCES `indicator_data` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_ia_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Histórico de versões de documentos
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `document_versions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`  INT UNSIGNED NOT NULL,
    `version`      INT UNSIGNED NOT NULL COMMENT 'Número sequencial da versão',
    `file_name`    VARCHAR(255) DEFAULT NULL,
    `file_path`    VARCHAR(255) DEFAULT NULL,
    `file_size`    BIGINT UNSIGNED DEFAULT NULL,
    `file_type`    VARCHAR(10)  DEFAULT NULL,
    `mime_type`    VARCHAR(100) DEFAULT NULL,
    `expiration_date` DATE      DEFAULT NULL COMMENT 'Validade no momento desta versão',
    `notes`        TEXT         DEFAULT NULL COMMENT 'Anotações da mudança',
    `created_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_version` (`document_id`, `version`),
    INDEX `idx_dv_document` (`document_id`),
    CONSTRAINT `fk_dv_document` FOREIGN KEY (`document_id`)
        REFERENCES `documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_dv_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adiciona contador de versão na tabela documents
ALTER TABLE `documents`
    ADD COLUMN `current_version` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `mime_type`;
