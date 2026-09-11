-- ============================================================================
-- MÓDULO DOCUMENTOS (gestão documental / qualidade) — schema consolidado
-- Plataforma Unificada · prefixo doc_ · MySQL 5.7+/MariaDB 10.3+ · utf8mb4
--
-- Requer sql/schema.sql (núcleo) ANTES: users e intra_layouts (layouts).
-- Consolida o schema legado + migrations 001–007 + migração 004 da plataforma
-- (controlados/não controlados e editor com layouts):
--   001 inicial · 002 segurança/índices · 003 indicadores v2 (variáveis,
--   fórmula, metas) · 004 system_settings · 005 indicadores v3 + histórico
--   de documentos · 006 setores · 007 workflow/revisão/PDCA/ciência digital
--
-- Tabelas que NÃO existem mais no módulo (agora são do núcleo):
--   users, notifications (module='documentos'), audit_log (via Core\Audit),
--   login_attempts, password_resets.
-- FKs de usuário (created_by, approved_by, recorded_by, responsible_user_id,
-- user_id em doc_user_sectors/doc_document_acknowledgments) apontam para a
-- tabela GLOBAL users(id) do núcleo (sql/schema.sql — aplicar antes).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS + seeds com proteção.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ════════════════════════════════════════════════════════════════════════════
--  1. HOSPITAIS / UNIDADES (aba "Unidades" descontinuada — unidade única
--     id=1; tabela mantida pela compatibilidade das FKs)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_hospitals` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(200)    NOT NULL,
    `cnpj`       VARCHAR(20)     DEFAULT NULL,
    `address`    VARCHAR(300)    DEFAULT NULL,
    `phone`      VARCHAR(20)     DEFAULT NULL,
    `email`      VARCHAR(150)    DEFAULT NULL,
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_doc_hospitals_active` (`is_active`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
--  2. SETORES (departamentos/unidades) + vínculo usuário↔setor
--     (doc_user_sectors: a aba "Usuários & Setores" foi descontinuada — o
--      seletor de setor mostra todos os setores ativos; a tabela é mantida
--      apenas para importação de dados legados)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_sectors` (
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
    INDEX `idx_doc_sectors_hospital` (`hospital_id`),
    INDEX `idx_doc_sectors_active` (`is_active`, `deleted_at`),
    CONSTRAINT `fk_doc_sectors_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `doc_hospitals` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- user_id → tabela GLOBAL users(id) do núcleo
CREATE TABLE IF NOT EXISTS `doc_user_sectors` (
    `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`   INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_user_sector` (`user_id`, `sector_id`),
    INDEX `idx_doc_us_sector` (`sector_id`),
    CONSTRAINT `fk_doc_us_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_doc_us_sector` FOREIGN KEY (`sector_id`) REFERENCES `doc_sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
--  3. CATEGORIAS DE DOCUMENTOS (taxonomia ONA)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_document_categories` (
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

-- ════════════════════════════════════════════════════════════════════════════
--  4. DOCUMENTOS (com workflow, revisão periódica, metadados e versão)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_documents` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id`         INT UNSIGNED    NOT NULL,
    `sector_id`           INT UNSIGNED    DEFAULT NULL,
    `status`              ENUM('draft','pending_review','approved','expired','archived')
                          NOT NULL DEFAULT 'approved'
                          COMMENT 'Workflow: rascunho > em revisão > aprovado > vencido > arquivado',
    `is_controlled`       TINYINT(1)      NOT NULL DEFAULT 1
                          COMMENT '1 = controlado (status, validade, revisão); 0 = não controlado (apenas armazenado)',
    `title`               VARCHAR(250)    NOT NULL,
    `document_code`       VARCHAR(50)     DEFAULT NULL COMMENT 'Código interno: POP-UTI-001',
    `category`            VARCHAR(100)    NOT NULL,
    `responsible`         VARCHAR(150)    DEFAULT NULL,
    `issuing_body`        VARCHAR(150)    DEFAULT NULL COMMENT 'Órgão emissor / autor',
    `legal_basis`         VARCHAR(255)    DEFAULT NULL COMMENT 'Base legal / normativa',
    `confidentiality`     ENUM('public','internal','restricted','confidential')
                          NOT NULL DEFAULT 'internal',
    `expiration_date`     DATE            DEFAULT NULL COMMENT 'Obrigatória apenas em documentos controlados',
    `notify_days_before`  INT UNSIGNED    NOT NULL DEFAULT 30,
    `review_interval_months` INT UNSIGNED NOT NULL DEFAULT 12
                          COMMENT 'Intervalo de revisão obrigatória em meses',
    `last_reviewed_at`    DATETIME        DEFAULT NULL,
    `next_review_date`    DATE            DEFAULT NULL,
    `observations`        TEXT            DEFAULT NULL,
    `file_name`           VARCHAR(255)    DEFAULT NULL,
    `file_path`           VARCHAR(255)    DEFAULT NULL,
    `file_size`           BIGINT UNSIGNED DEFAULT NULL,
    `file_type`           VARCHAR(10)     DEFAULT NULL,
    `mime_type`           VARCHAR(100)    DEFAULT NULL,
    `source`              ENUM('upload','editor') NOT NULL DEFAULT 'upload'
                          COMMENT 'upload = arquivo enviado; editor = escrito no sistema',
    `content_html`        MEDIUMTEXT      DEFAULT NULL COMMENT 'Conteúdo do editor (source=editor)',
    `cover_html`          MEDIUMTEXT      DEFAULT NULL COMMENT 'Conteúdo da capa (opcional)',
    `layout_id`           INT UNSIGNED    DEFAULT NULL COMMENT 'Layout de página (intra_layouts)',
    `cover_layout_id`     INT UNSIGNED    DEFAULT NULL COMMENT 'Layout de capa (intra_layouts)',
    `font_family`         VARCHAR(80)     DEFAULT NULL,
    `font_size`           VARCHAR(10)     DEFAULT NULL,
    `current_version`     INT UNSIGNED    NOT NULL DEFAULT 1,
    `created_by`          INT UNSIGNED    DEFAULT NULL,
    `approved_by`         INT UNSIGNED    DEFAULT NULL,
    `approved_at`         DATETIME        DEFAULT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`          DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_doc_docs_hospital` (`hospital_id`),
    INDEX `idx_doc_docs_sector` (`sector_id`),
    INDEX `idx_doc_docs_status` (`status`),
    INDEX `idx_doc_docs_expiration` (`expiration_date`),
    INDEX `idx_doc_docs_category` (`category`),
    INDEX `idx_doc_docs_deleted` (`deleted_at`),
    INDEX `idx_doc_docs_hosp_del_exp` (`hospital_id`, `deleted_at`, `expiration_date`),
    INDEX `idx_doc_docs_controlled` (`is_controlled`, `deleted_at`),
    CONSTRAINT `fk_doc_docs_layout`
        FOREIGN KEY (`layout_id`) REFERENCES `intra_layouts` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_doc_docs_cover_layout`
        FOREIGN KEY (`cover_layout_id`) REFERENCES `intra_layouts` (`id`)
        ON DELETE SET NULL,
    CONSTRAINT `fk_doc_docs_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `doc_hospitals` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_docs_sector`
        FOREIGN KEY (`sector_id`) REFERENCES `doc_sectors` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_docs_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_docs_approved_by`
        FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de versões de documentos (migration 005)
CREATE TABLE IF NOT EXISTS `doc_document_versions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`  INT UNSIGNED NOT NULL,
    `version`      INT UNSIGNED NOT NULL COMMENT 'Número sequencial da versão',
    `file_name`    VARCHAR(255) DEFAULT NULL,
    `file_path`    VARCHAR(255) DEFAULT NULL,
    `file_size`    BIGINT UNSIGNED DEFAULT NULL,
    `file_type`    VARCHAR(10)  DEFAULT NULL,
    `mime_type`    VARCHAR(100) DEFAULT NULL,
    `content_html` MEDIUMTEXT   DEFAULT NULL COMMENT 'Conteúdo do editor nesta versão',
    `cover_html`   MEDIUMTEXT   DEFAULT NULL,
    `layout_id`    INT UNSIGNED DEFAULT NULL,
    `cover_layout_id` INT UNSIGNED DEFAULT NULL,
    `font_family`  VARCHAR(80)  DEFAULT NULL,
    `font_size`    VARCHAR(10)  DEFAULT NULL,
    `expiration_date` DATE      DEFAULT NULL COMMENT 'Validade no momento desta versão',
    `notes`        TEXT         DEFAULT NULL COMMENT 'Anotações da mudança',
    `created_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_doc_version` (`document_id`, `version`),
    INDEX `idx_doc_dv_document` (`document_id`),
    CONSTRAINT `fk_doc_dv_document` FOREIGN KEY (`document_id`)
        REFERENCES `doc_documents` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_dv_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ciência digital: quem leu o documento (migration 007)
CREATE TABLE IF NOT EXISTS `doc_document_acknowledgments` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id`  INT UNSIGNED NOT NULL,
    `user_id`      INT UNSIGNED NOT NULL,
    `ip_address`   VARCHAR(45)  DEFAULT NULL,
    `acknowledged_at` DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_ack` (`document_id`, `user_id`),
    INDEX `idx_doc_ack_doc` (`document_id`),
    INDEX `idx_doc_ack_user` (`user_id`),
    CONSTRAINT `fk_doc_ack_doc` FOREIGN KEY (`document_id`)
        REFERENCES `doc_documents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_doc_ack_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
--  5. INDICADORES (v3: variáveis, fórmula, metas, benchmark, responsável)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_indicators` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED    NOT NULL,
    `sector_id`   INT UNSIGNED    DEFAULT NULL,
    `name`        VARCHAR(200)    NOT NULL,
    `type`        ENUM('daily','weekly','monthly','quarterly','semester','yearly')
                  NOT NULL DEFAULT 'monthly',
    `unit`        VARCHAR(50)     DEFAULT NULL,
    `goal`        VARCHAR(50)     DEFAULT NULL,
    `variables`   VARCHAR(500)    DEFAULT NULL COMMENT 'Formato legado (texto livre)',
    `formula`     VARCHAR(500)    DEFAULT NULL COMMENT 'Ex: (a/b)*100',
    `goal_numeric`   DECIMAL(15,4) DEFAULT NULL,
    `goal_direction` ENUM('higher_better','lower_better','target') NOT NULL DEFAULT 'higher_better',
    `goal_tolerance` DECIMAL(15,4) NOT NULL DEFAULT 0,
    `benchmark_value`   DECIMAL(15,4) DEFAULT NULL COMMENT 'Referência externa (ex: ANVISA, ANAHP)',
    `benchmark_source`  VARCHAR(200) DEFAULT NULL COMMENT 'Origem do benchmark',
    `responsible_user_id` INT UNSIGNED DEFAULT NULL COMMENT 'Usuário responsável pelo acompanhamento',
    `accreditation`     VARCHAR(200) DEFAULT NULL COMMENT 'Acreditações: ONA, JCI, ANVISA, etc',
    `template_slug`     VARCHAR(100) DEFAULT NULL COMMENT 'Template usado na criação',
    `chart_type`     ENUM('line','bar','area') NOT NULL DEFAULT 'line',
    `category`       VARCHAR(100) DEFAULT NULL,
    `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 2,
    `description` TEXT            DEFAULT NULL,
    `created_by`  INT UNSIGNED    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_doc_ind_hospital` (`hospital_id`),
    INDEX `idx_doc_ind_sector` (`sector_id`),
    INDEX `idx_doc_ind_type` (`type`),
    INDEX `idx_doc_ind_deleted` (`deleted_at`),
    INDEX `idx_doc_ind_hosp_del_name` (`hospital_id`, `deleted_at`, `name`),
    INDEX `idx_doc_ind_responsible` (`responsible_user_id`),
    INDEX `idx_doc_ind_accreditation` (`accreditation`),
    CONSTRAINT `fk_doc_ind_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `doc_hospitals` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_ind_sector`
        FOREIGN KEY (`sector_id`) REFERENCES `doc_sectors` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_ind_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_ind_responsible`
        FOREIGN KEY (`responsible_user_id`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Variáveis do indicador (ex.: numerador, denominador)
CREATE TABLE IF NOT EXISTS `doc_indicator_variables` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `indicator_id`  INT UNSIGNED    NOT NULL,
    `code`          VARCHAR(30)     NOT NULL,
    `label`         VARCHAR(150)    NOT NULL,
    `unit`          VARCHAR(50)     DEFAULT NULL,
    `display_order` INT             NOT NULL DEFAULT 0,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_indvar_code` (`indicator_id`, `code`),
    INDEX `idx_doc_indvar_indicator` (`indicator_id`),
    CONSTRAINT `fk_doc_indvar_indicator`
        FOREIGN KEY (`indicator_id`) REFERENCES `doc_indicators` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `doc_indicator_data` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `indicator_id`   INT UNSIGNED    NOT NULL,
    `reference_date` DATE            NOT NULL,
    `value`          DECIMAL(15,4)   NOT NULL,
    `observations`   TEXT            DEFAULT NULL,
    `recorded_by`    INT UNSIGNED    DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_inddata_indicator_date` (`indicator_id`, `reference_date`),
    INDEX `idx_doc_inddata_indicator` (`indicator_id`),
    INDEX `idx_doc_inddata_date` (`reference_date`),
    CONSTRAINT `fk_doc_inddata_indicator`
        FOREIGN KEY (`indicator_id`) REFERENCES `doc_indicators` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_inddata_recorded_by`
        FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores das variáveis por lançamento
CREATE TABLE IF NOT EXISTS `doc_indicator_data_values` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `data_id`     INT UNSIGNED    NOT NULL,
    `variable_id` INT UNSIGNED    NOT NULL,
    `value`       DECIMAL(18,6)   NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_datavar` (`data_id`, `variable_id`),
    INDEX `idx_doc_idv_data` (`data_id`),
    INDEX `idx_doc_idv_variable` (`variable_id`),
    CONSTRAINT `fk_doc_idv_data`
        FOREIGN KEY (`data_id`) REFERENCES `doc_indicator_data` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_idv_variable`
        FOREIGN KEY (`variable_id`) REFERENCES `doc_indicator_variables` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Planos de ação (PDCA) vinculados a indicadores (migrations 005 + 007)
CREATE TABLE IF NOT EXISTS `doc_indicator_actions` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `indicator_id` INT UNSIGNED NOT NULL,
    `data_id`      INT UNSIGNED DEFAULT NULL COMMENT 'Opcional: lançamento que originou',
    `title`        VARCHAR(200) NOT NULL,
    `description`  TEXT         DEFAULT NULL,
    `action_type`  ENUM('corrective','preventive','improvement') NOT NULL DEFAULT 'corrective',
    `root_cause`   TEXT         DEFAULT NULL COMMENT 'Análise de causa raiz',
    `verification` TEXT         DEFAULT NULL COMMENT 'Verificação de eficácia',
    `verified_at`  DATETIME     DEFAULT NULL,
    `responsible`  VARCHAR(150) DEFAULT NULL,
    `due_date`     DATE         DEFAULT NULL,
    `status`       ENUM('pending','in_progress','done','cancelled') NOT NULL DEFAULT 'pending',
    `completed_at` DATETIME     DEFAULT NULL,
    `created_by`   INT UNSIGNED DEFAULT NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_doc_ia_indicator` (`indicator_id`),
    INDEX `idx_doc_ia_status` (`status`),
    INDEX `idx_doc_ia_due` (`due_date`),
    CONSTRAINT `fk_doc_ia_indicator` FOREIGN KEY (`indicator_id`)
        REFERENCES `doc_indicators` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_ia_data` FOREIGN KEY (`data_id`)
        REFERENCES `doc_indicator_data` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_doc_ia_created_by` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Log de importação em lote de indicadores (migration 007)
CREATE TABLE IF NOT EXISTS `doc_indicator_imports` (
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
    INDEX `idx_doc_ii_indicator` (`indicator_id`),
    CONSTRAINT `fk_doc_ii_indicator` FOREIGN KEY (`indicator_id`)
        REFERENCES `doc_indicators` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_doc_ii_imported_by` FOREIGN KEY (`imported_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ════════════════════════════════════════════════════════════════════════════
--  6. CONFIGURAÇÕES FUNCIONAIS DO MÓDULO (key-value)
--     (As configurações visuais do legado saíram — tema é do núcleo.)
-- ════════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `doc_system_settings` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT         DEFAULT NULL,
    `updated_by`    INT UNSIGNED DEFAULT NULL,
    `updated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════════════════════
--  DADOS INICIAIS (seeds mínimos, idempotentes)
-- ════════════════════════════════════════════════════════════════════════════

-- Unidade padrão
INSERT INTO `doc_hospitals` (`id`, `name`, `is_active`)
VALUES (1, 'Unidade Principal', 1)
ON DUPLICATE KEY UPDATE `id` = `id`;

-- Setor padrão "Geral" da unidade principal
INSERT INTO `doc_sectors` (`hospital_id`, `name`, `code`, `description`)
SELECT 1, 'Geral', 'GERAL', 'Setor padrão'
WHERE NOT EXISTS (SELECT 1 FROM `doc_sectors` WHERE `code` = 'GERAL' AND `hospital_id` = 1);

-- Categorias pré-definidas (taxonomia ONA)
INSERT INTO `doc_document_categories` (`name`, `description`, `icon`, `sort_order`) VALUES
    ('Políticas',          'Políticas institucionais e diretrizes',                     'bi-shield-check',      1),
    ('Protocolos Clínicos','Protocolos assistenciais e clínicos',                       'bi-heart-pulse',       2),
    ('POPs',               'Procedimentos Operacionais Padrão',                         'bi-list-check',        3),
    ('Manuais',            'Manuais técnicos e de equipamentos',                        'bi-book',              4),
    ('Regulamentos',       'Regulamentos internos e normas',                            'bi-file-earmark-ruled',5),
    ('Contratos',          'Contratos com fornecedores e parceiros',                    'bi-file-earmark-text', 6),
    ('Alvarás e Licenças', 'Alvarás de funcionamento, licenças sanitárias',             'bi-patch-check',       7),
    ('Certificações',      'Certificados de acreditação, ISO, etc',                     'bi-award',             8),
    ('Normas Técnicas',    'ABNT, ANVISA, MS e outras normas',                          'bi-journal-check',     9),
    ('Prontuários',        'Modelos e formulários de prontuário',                       'bi-clipboard2-pulse', 10),
    ('Outros',             'Documentos que não se enquadram nas categorias anteriores', 'bi-folder',           99)
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
