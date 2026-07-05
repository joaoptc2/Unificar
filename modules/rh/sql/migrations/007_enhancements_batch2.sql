-- ============================================================
-- Migracao 007 — Ferias, Escalas, Onboarding, Pesquisa Clima,
-- Comunicados, Dependentes, Historico Salarial, Advertencias,
-- Solicitacoes, Modo Escuro
-- ============================================================

-- 2. Gestao de Ferias
CREATE TABLE IF NOT EXISTS `vacations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `period_start` DATE NOT NULL COMMENT 'Inicio do periodo aquisitivo',
    `period_end` DATE NOT NULL COMMENT 'Fim do periodo aquisitivo',
    `start_date` DATE NULL COMMENT 'Inicio do gozo',
    `end_date` DATE NULL COMMENT 'Fim do gozo',
    `days` INT UNSIGNED NOT NULL DEFAULT 30,
    `sold_days` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Abono pecuniario',
    `installment` TINYINT UNSIGNED NOT NULL DEFAULT 1 COMMENT '1, 2 ou 3 (fracionamento)',
    `status` ENUM('planejada','solicitada','aprovada','em_gozo','concluida','rejeitada') NOT NULL DEFAULT 'planejada',
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_vac_employee` (`employee_id`, `period_start`),
    KEY `idx_vac_status` (`status`),
    KEY `idx_vac_dates` (`start_date`, `end_date`),
    CONSTRAINT `fk_vac_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vac_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_vac_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Escalas de Plantao
CREATE TABLE IF NOT EXISTS `shift_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL COMMENT 'Ex: 12x36 Dia, 12x36 Noite, 6x1',
    `work_hours` INT UNSIGNED NOT NULL DEFAULT 12,
    `rest_hours` INT UNSIGNED NOT NULL DEFAULT 36,
    `color` VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shifts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `template_id` INT UNSIGNED NULL,
    `shift_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `type` ENUM('regular','extra','sobreaviso','cobertura') NOT NULL DEFAULT 'regular',
    `status` ENUM('agendado','confirmado','realizado','falta','troca_pendente','trocado') NOT NULL DEFAULT 'agendado',
    `swap_with_id` BIGINT UNSIGNED NULL COMMENT 'ID do shift trocado',
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_shift_emp_date` (`employee_id`, `shift_date`),
    KEY `idx_shift_dept_date` (`department_id`, `shift_date`),
    KEY `idx_shift_status` (`status`),
    CONSTRAINT `fk_shift_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_shift_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_shift_template` FOREIGN KEY (`template_id`) REFERENCES `shift_templates`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_shift_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Onboarding / Offboarding
CREATE TABLE IF NOT EXISTS `onboarding_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL COMMENT 'Ex: Admissao Enfermagem, Desligamento Geral',
    `type` ENUM('onboarding','offboarding') NOT NULL DEFAULT 'onboarding',
    `department_id` INT UNSIGNED NULL COMMENT 'NULL = todos os departamentos',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_obt_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding_template_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `responsible_role` VARCHAR(50) NULL COMMENT 'admin, rh, gestor ou NULL=qualquer',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_obti_template` FOREIGN KEY (`template_id`) REFERENCES `onboarding_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding_progress` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `completed` TINYINT(1) NOT NULL DEFAULT 0,
    `completed_by` INT UNSIGNED NULL,
    `completed_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ob_progress` (`employee_id`, `item_id`),
    CONSTRAINT `fk_obp_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_obp_template` FOREIGN KEY (`template_id`) REFERENCES `onboarding_templates`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_obp_item` FOREIGN KEY (`item_id`) REFERENCES `onboarding_template_items`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_obp_user` FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. LMS / Treinamentos
CREATE TABLE IF NOT EXISTS `training_catalog` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(100) NULL COMMENT 'NR-32, BLS/ACLS, CCIH, etc.',
    `hours` DECIMAL(5,1) NULL COMMENT 'Carga horaria',
    `mandatory` TINYINT(1) NOT NULL DEFAULT 0,
    `validity_months` INT UNSIGNED NULL COMMENT 'Meses ate vencimento (NULL=sem vencimento)',
    `department_id` INT UNSIGNED NULL COMMENT 'NULL = todos',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tc_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `training_records` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `catalog_id` INT UNSIGNED NULL,
    `title` VARCHAR(200) NOT NULL,
    `completed_at` DATE NOT NULL,
    `expires_at` DATE NULL,
    `hours` DECIMAL(5,1) NULL,
    `certificate_path` VARCHAR(500) NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_tr_employee` (`employee_id`),
    KEY `idx_tr_expires` (`expires_at`),
    CONSTRAINT `fk_tr_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tr_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `training_catalog`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tr_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. Comunicados / Mural
CREATE TABLE IF NOT EXISTS `announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `body` TEXT NOT NULL,
    `type` ENUM('informativo','urgente','celebracao') NOT NULL DEFAULT 'informativo',
    `department_id` INT UNSIGNED NULL COMMENT 'NULL = todos',
    `published_at` DATETIME NULL,
    `expires_at` DATE NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_ann_published` (`published_at`),
    CONSTRAINT `fk_ann_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `announcement_reads` (
    `announcement_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcement_id`, `user_id`),
    CONSTRAINT `fk_annr_ann` FOREIGN KEY (`announcement_id`) REFERENCES `announcements`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_annr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. Pesquisa de Clima / eNPS
CREATE TABLE IF NOT EXISTS `surveys` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `type` ENUM('clima','enps','pulse','custom') NOT NULL DEFAULT 'clima',
    `anonymous` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('rascunho','ativa','encerrada') NOT NULL DEFAULT 'rascunho',
    `starts_at` DATE NULL,
    `ends_at` DATE NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_survey_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `type` ENUM('rating','text','choice') NOT NULL DEFAULT 'rating',
    `options` JSON NULL COMMENT 'Para type=choice: ["opcao1","opcao2"]',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_sq_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_responses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL COMMENT 'NULL se anonima',
    `rating` INT NULL COMMENT '0-10 para rating/eNPS',
    `answer` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sr_survey` (`survey_id`),
    CONSTRAINT `fk_sr_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sr_question` FOREIGN KEY (`question_id`) REFERENCES `survey_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. Historico Salarial
CREATE TABLE IF NOT EXISTS `salary_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `salary` DECIMAL(12,2) NOT NULL,
    `reason` VARCHAR(200) NULL COMMENT 'Admissao, Reajuste, Promocao, etc.',
    `effective_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sh_employee` (`employee_id`, `effective_date`),
    CONSTRAINT `fk_sh_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sh_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. Solicitacoes Self-Service
CREATE TABLE IF NOT EXISTS `requests` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `type` ENUM('declaracao','alteracao_cadastral','treinamento','ferias','outro') NOT NULL,
    `subject` VARCHAR(200) NOT NULL,
    `body` TEXT NULL,
    `status` ENUM('pendente','em_analise','aprovada','rejeitada') NOT NULL DEFAULT 'pendente',
    `response` TEXT NULL,
    `responded_by` INT UNSIGNED NULL,
    `responded_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_req_employee` (`employee_id`),
    KEY `idx_req_status` (`status`),
    CONSTRAINT `fk_req_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_req_responder` FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. Assinatura Digital
CREATE TABLE IF NOT EXISTS `digital_signatures` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `document_type` VARCHAR(100) NOT NULL COMMENT 'epi_receipt, vacation, warning, etc.',
    `document_id` INT UNSIGNED NULL COMMENT 'ID do registro relacionado',
    `document_title` VARCHAR(200) NOT NULL,
    `content_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 do conteudo no momento da assinatura',
    `signer_ip` VARCHAR(45) NOT NULL,
    `signer_user_agent` VARCHAR(500) NULL,
    `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sig_employee` (`employee_id`),
    KEY `idx_sig_doctype` (`document_type`, `document_id`),
    CONSTRAINT `fk_sig_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. Dependentes
CREATE TABLE IF NOT EXISTS `employee_dependents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `full_name` VARCHAR(200) NOT NULL,
    `cpf` VARCHAR(14) NULL,
    `birth_date` DATE NULL,
    `relationship` ENUM('conjuge','filho','filha','pai','mae','outro') NOT NULL,
    `for_health_plan` TINYINT(1) NOT NULL DEFAULT 0,
    `for_ir` TINYINT(1) NOT NULL DEFAULT 0,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_dep_employee` (`employee_id`),
    CONSTRAINT `fk_dep_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Advertencias (item 6 da lista de prioridade normal = #15 na numeracao)
CREATE TABLE IF NOT EXISTS `warnings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `type` ENUM('verbal','escrita','suspensao') NOT NULL,
    `reason` TEXT NOT NULL,
    `incident_date` DATE NOT NULL,
    `witnesses` VARCHAR(500) NULL,
    `file_path` VARCHAR(500) NULL,
    `signature_id` BIGINT UNSIGNED NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_warn_employee` (`employee_id`),
    CONSTRAINT `fk_warn_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_warn_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_warn_sig` FOREIGN KEY (`signature_id`) REFERENCES `digital_signatures`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Preferencia de tema (modo escuro)
ALTER TABLE `users` ADD COLUMN `theme_preference` VARCHAR(10) NOT NULL DEFAULT 'light' AFTER `employee_id`;

-- Templates padrao de escalas
INSERT INTO `shift_templates` (`name`, `work_hours`, `rest_hours`, `color`) VALUES
('12x36 Dia', 12, 36, '#0d6efd'),
('12x36 Noite', 12, 36, '#6610f2'),
('6x1', 8, 16, '#198754'),
('Diarista (8h)', 8, 16, '#fd7e14'),
('Plantonista 24h', 24, 72, '#dc3545');

-- Template padrao de onboarding
INSERT INTO `onboarding_templates` (`name`, `type`) VALUES
('Admissao Geral', 'onboarding'),
('Desligamento Geral', 'offboarding');

INSERT INTO `onboarding_template_items` (`template_id`, `title`, `responsible_role`, `sort_order`) VALUES
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Documentos pessoais entregues (RG, CPF, CTPS)', 'rh', 1),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Exame admissional (ASO) realizado', 'rh', 2),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Contrato assinado', 'rh', 3),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'EPIs entregues', 'rh', 4),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Treinamento NR-32 realizado', 'rh', 5),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Cracha/acesso entregue', 'rh', 6),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Apresentacao ao setor e equipe', 'gestor', 7),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Acesso ao sistema criado', 'admin', 8),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Exame demissional realizado', 'rh', 1),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de cracha e chaves', 'rh', 2),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de EPIs', 'rh', 3),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de uniformes', 'rh', 4),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Revogacao de acessos ao sistema', 'admin', 5),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Entrevista de desligamento realizada', 'rh', 6),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Documentos rescisorios entregues', 'rh', 7);
