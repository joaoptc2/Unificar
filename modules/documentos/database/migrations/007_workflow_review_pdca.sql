-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 007 — Melhorias P1+P2: workflow, revisão, categorias,       ║
-- ║  PDCA, ciência digital, metadados, conformidade                        ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Workflow de aprovação de documentos
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `documents`
    ADD COLUMN `status` ENUM('draft','pending_review','approved','expired','archived')
              NOT NULL DEFAULT 'approved'
              COMMENT 'Workflow: rascunho > em revisão > aprovado > vencido > arquivado'
              AFTER `hospital_id`,
    ADD COLUMN `approved_by` INT UNSIGNED DEFAULT NULL AFTER `created_by`,
    ADD COLUMN `approved_at` DATETIME DEFAULT NULL AFTER `approved_by`;

ALTER TABLE `documents`
    ADD INDEX `idx_docs_status` (`status`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Ciclo de revisão periódica
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `documents`
    ADD COLUMN `review_interval_months` INT UNSIGNED NOT NULL DEFAULT 12
              COMMENT 'Intervalo de revisão obrigatória em meses' AFTER `notify_days_before`,
    ADD COLUMN `last_reviewed_at` DATETIME DEFAULT NULL AFTER `review_interval_months`,
    ADD COLUMN `next_review_date` DATE DEFAULT NULL AFTER `last_reviewed_at`;

-- Preenche next_review_date para docs existentes (12 meses após criação)
UPDATE `documents`
   SET `next_review_date` = DATE_ADD(`created_at`, INTERVAL 12 MONTH)
 WHERE `next_review_date` IS NULL AND `deleted_at` IS NULL;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Metadados extras de documentos
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `documents`
    ADD COLUMN `document_code` VARCHAR(50) DEFAULT NULL
              COMMENT 'Código interno: POP-UTI-001' AFTER `title`,
    ADD COLUMN `issuing_body` VARCHAR(150) DEFAULT NULL
              COMMENT 'Órgão emissor / autor' AFTER `responsible`,
    ADD COLUMN `legal_basis` VARCHAR(255) DEFAULT NULL
              COMMENT 'Base legal / normativa' AFTER `issuing_body`,
    ADD COLUMN `confidentiality` ENUM('public','internal','restricted','confidential')
              NOT NULL DEFAULT 'internal' AFTER `legal_basis`;

-- ═══════════════════════════════════════════════════════════════════════════
-- 4. Categorias pré-definidas (taxonomia ONA)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `document_categories` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    `icon`        VARCHAR(50)  DEFAULT NULL COMMENT 'Bootstrap Icon class',
    `sort_order`  INT NOT NULL DEFAULT 0,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_cat_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `document_categories` (`name`, `description`, `icon`, `sort_order`) VALUES
    ('Políticas',          'Políticas institucionais e diretrizes',                     'bi-shield-check',     1),
    ('Protocolos Clínicos','Protocolos assistenciais e clínicos',                       'bi-heart-pulse',      2),
    ('POPs',               'Procedimentos Operacionais Padrão',                         'bi-list-check',       3),
    ('Manuais',            'Manuais técnicos e de equipamentos',                        'bi-book',             4),
    ('Regulamentos',       'Regulamentos internos e normas',                            'bi-file-earmark-ruled',5),
    ('Contratos',          'Contratos com fornecedores e parceiros',                    'bi-file-earmark-text',6),
    ('Alvarás e Licenças', 'Alvarás de funcionamento, licenças sanitárias',             'bi-patch-check',      7),
    ('Certificações',      'Certificados de acreditação, ISO, etc',                     'bi-award',            8),
    ('Normas Técnicas',    'ABNT, ANVISA, MS e outras normas',                          'bi-journal-check',    9),
    ('Prontuários',        'Modelos e formulários de prontuário',                       'bi-clipboard2-pulse', 10),
    ('Outros',             'Documentos que não se enquadram nas categorias anteriores',  'bi-folder',           99)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);

-- ═══════════════════════════════════════════════════════════════════════════
-- 5. Ciência digital (quem leu o documento)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `document_acknowledgments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`  INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `ip_address`   VARCHAR(45)  DEFAULT NULL,
    `acknowledged_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_ack` (`document_id`, `user_id`),
    INDEX `idx_ack_doc` (`document_id`),
    INDEX `idx_ack_user` (`user_id`),
    CONSTRAINT `fk_ack_doc` FOREIGN KEY (`document_id`)
        REFERENCES `documents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ack_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 6. Plano de ação (PDCA) — campos extras se tabela já existe
-- ═══════════════════════════════════════════════════════════════════════════
-- A tabela indicator_actions já existe da migration 005.
-- Adicionamos campos PDCA se faltam:
ALTER TABLE `indicator_actions`
    ADD COLUMN `action_type` ENUM('corrective','preventive','improvement')
              NOT NULL DEFAULT 'corrective' AFTER `description`,
    ADD COLUMN `root_cause` TEXT DEFAULT NULL
              COMMENT 'Análise de causa raiz' AFTER `action_type`,
    ADD COLUMN `verification` TEXT DEFAULT NULL
              COMMENT 'Verificação de eficácia' AFTER `root_cause`,
    ADD COLUMN `verified_at` DATETIME DEFAULT NULL AFTER `verification`;

-- ═══════════════════════════════════════════════════════════════════════════
-- 7. Log de importação em lote de indicadores
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicator_imports` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `indicator_id` INT UNSIGNED NOT NULL,
    `file_name`    VARCHAR(255) NOT NULL,
    `rows_total`   INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_imported`INT UNSIGNED NOT NULL DEFAULT 0,
    `rows_errors`  INT UNSIGNED NOT NULL DEFAULT 0,
    `error_log`    TEXT DEFAULT NULL,
    `imported_by`  INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_ii_indicator` (`indicator_id`),
    CONSTRAINT `fk_ii_indicator` FOREIGN KEY (`indicator_id`)
        REFERENCES `indicators` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
