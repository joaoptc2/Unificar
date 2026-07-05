-- ============================================================
-- PLATAFORMA UNIFICADA — Módulo RH (Recursos Humanos hospitalar)
-- Schema consolidado (schema legado + migrações 001–007), com:
--   • prefixo rh_ em todas as tabelas do módulo;
--   • FKs de usuário apontando para a tabela GLOBAL users(id);
--   • rh_user_profile: vínculo usuário ↔ funcionário/departamento
--     (substitui users.employee_id / users.department_id / users.role);
--   • SEM tabelas que agora são do núcleo: users, notifications,
--     audit_log, login_attempts, password_resets, user_2fa, settings.
-- Idempotente (CREATE TABLE IF NOT EXISTS; seeds com NOT EXISTS).
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- rh_departments (Departamentos/Setores)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_rh_department_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_job_positions (Cargos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_job_positions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `description` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_position_dept` (`department_id`),
    CONSTRAINT `fk_rh_position_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employees (Funcionários)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `full_name` VARCHAR(200) NOT NULL,
    `cpf` VARCHAR(14) NOT NULL,
    `birth_date` DATE NOT NULL,
    `gender` ENUM('M','F','O') NOT NULL,
    `phone` VARCHAR(20) NULL,
    `email` VARCHAR(200) NULL,
    `address_street` VARCHAR(255) NULL,
    `address_number` VARCHAR(20) NULL,
    `address_complement` VARCHAR(100) NULL,
    `address_neighborhood` VARCHAR(100) NULL,
    `address_city` VARCHAR(100) NULL,
    `address_state` VARCHAR(2) NULL,
    `address_zip` VARCHAR(10) NULL,
    `job_position_id` INT UNSIGNED NULL,
    `department_id` INT UNSIGNED NULL,
    `admission_date` DATE NOT NULL,
    `contract_type` ENUM('CLT','PJ','Temporario','Estagio','Terceirizado') NOT NULL DEFAULT 'CLT',
    `status` ENUM('ativo','afastado','desligado') NOT NULL DEFAULT 'ativo',
    `termination_date` DATE NULL,
    `leave_date` DATE NULL,
    `return_date` DATE NULL,
    `regional_council` VARCHAR(50) NULL,
    `council_number` VARCHAR(50) NULL,
    `council_expiry` DATE NULL,
    `aso_admissional_date` DATE NULL,
    `aso_next_date` DATE NULL,
    `photo` VARCHAR(255) NULL,
    `notes` TEXT NULL,
    `anonymized_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_rh_employee_cpf` (`cpf`),
    KEY `idx_rh_employee_name` (`full_name`),
    KEY `idx_rh_employee_status` (`status`),
    KEY `idx_rh_employee_dept` (`department_id`),
    KEY `idx_rh_employee_position` (`job_position_id`),
    KEY `idx_rh_employee_admission` (`admission_date`),
    CONSTRAINT `fk_rh_employee_position` FOREIGN KEY (`job_position_id`) REFERENCES `rh_job_positions`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_employee_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_employee_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_user_profile — vínculo do usuário GLOBAL com o módulo RH.
-- Substitui as colunas legadas users.employee_id/department_id.
-- (O papel do usuário no módulo vem do RBAC central: user_module_access.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_user_profile` (
    `user_id` INT UNSIGNED PRIMARY KEY,
    `employee_id` INT UNSIGNED NULL,
    `department_id` INT UNSIGNED NULL,
    UNIQUE KEY `uk_rh_profile_employee` (`employee_id`),
    KEY `idx_rh_profile_dept` (`department_id`),
    CONSTRAINT `fk_rh_profile_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_profile_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_profile_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employee_documents (Documentos do funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employee_documents` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `doc_type` ENUM('RG','CPF','Contrato','Certificado','Atestado','Treinamento','EPI','Outro') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_original_name` VARCHAR(255) NULL,
    `file_size` INT UNSIGNED NULL,
    `notes` TEXT NULL,
    `uploaded_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_doc_employee` (`employee_id`),
    KEY `idx_rh_doc_type` (`doc_type`),
    CONSTRAINT `fk_rh_doc_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_doc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employee_records (Histórico / Ficha Funcional)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employee_records` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `record_type` ENUM('admissao','promocao','transferencia','afastamento','retorno','desligamento','alteracao','observacao') NOT NULL,
    `description` TEXT NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `record_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_record_employee` (`employee_id`),
    KEY `idx_rh_record_type` (`record_type`),
    KEY `idx_rh_record_date` (`record_date`),
    CONSTRAINT `fk_rh_record_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_record_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_medical_certificates (Atestados médicos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_medical_certificates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `issue_date` DATE NOT NULL,
    `days` INT UNSIGNED NOT NULL DEFAULT 1,
    `cid` VARCHAR(20) NULL,
    `doctor_name` VARCHAR(200) NULL,
    `doctor_crm` VARCHAR(50) NULL,
    `notes` TEXT NULL,
    `file_path` VARCHAR(500) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_cert_employee` (`employee_id`),
    KEY `idx_rh_cert_date` (`issue_date`),
    CONSTRAINT `fk_rh_cert_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_cert_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_expirations (Vencimentos e Obrigações)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_expirations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `type` ENUM('exame_periodico','aso_admissional','aso_demissional','aso_periodico','certificacao','treinamento','conselho_regional','outro') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `issue_date` DATE NULL,
    `expiry_date` DATE NOT NULL,
    `status` ENUM('valido','proximo_vencimento','vencido') NOT NULL DEFAULT 'valido',
    `alert_days` INT UNSIGNED NOT NULL DEFAULT 30,
    `file_path` VARCHAR(500) NULL,
    `renewed` TINYINT(1) NOT NULL DEFAULT 0,
    `notified_at` DATETIME NULL,
    `notified_expired_at` DATETIME NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_exp_employee` (`employee_id`),
    KEY `idx_rh_exp_type` (`type`),
    KEY `idx_rh_exp_expiry` (`expiry_date`),
    KEY `idx_rh_exp_status` (`status`),
    CONSTRAINT `fk_rh_exp_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_exp_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_expiration_history (Histórico de renovações)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_expiration_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expiration_id` INT UNSIGNED NOT NULL,
    `old_expiry_date` DATE NOT NULL,
    `new_expiry_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `renewed_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_exphist_exp` (`expiration_id`),
    CONSTRAINT `fk_rh_exphist_exp` FOREIGN KEY (`expiration_id`) REFERENCES `rh_expirations`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_exphist_user` FOREIGN KEY (`renewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_schedules (Agenda e Compromissos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_schedules` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `event_date` DATE NOT NULL,
    `event_time` TIME NULL,
    `end_time` TIME NULL,
    `event_type` ENUM('compromisso','vencimento','reuniao','treinamento','outro') NOT NULL DEFAULT 'compromisso',
    `expiration_id` INT UNSIGNED NULL,
    `color` VARCHAR(7) NULL DEFAULT '#0d6efd',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_sched_date` (`event_date`),
    KEY `idx_rh_sched_employee` (`employee_id`),
    CONSTRAINT `fk_rh_sched_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_sched_expiration` FOREIGN KEY (`expiration_id`) REFERENCES `rh_expirations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_sched_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_recruitment_jobs (Vagas de Processos Seletivos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_recruitment_jobs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `requirements` TEXT NULL,
    `department_id` INT UNSIGNED NULL,
    `status` ENUM('aberta','fechada') NOT NULL DEFAULT 'aberta',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_job_status` (`status`),
    CONSTRAINT `fk_rh_job_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_job_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_recruitment_steps (Etapas do Processo Seletivo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_recruitment_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `step_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `description` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_step_job` (`job_id`),
    CONSTRAINT `fk_rh_step_job` FOREIGN KEY (`job_id`) REFERENCES `rh_recruitment_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_candidates (Candidatos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_candidates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NULL,
    `full_name` VARCHAR(200) NOT NULL,
    `email` VARCHAR(200) NOT NULL,
    `phone` VARCHAR(20) NULL,
    `cpf` VARCHAR(14) NULL,
    `area` VARCHAR(100) NULL,
    `experience` TEXT NULL,
    `resume_path` VARCHAR(500) NULL,
    `current_step_id` INT UNSIGNED NULL,
    `status` ENUM('inscrito','em_andamento','aprovado','reprovado','banco_talentos') NOT NULL DEFAULT 'inscrito',
    `notes` TEXT NULL,
    `access_token` VARCHAR(64) NULL,
    `in_talent_pool` TINYINT(1) NOT NULL DEFAULT 0,
    `lgpd_consent_at` DATETIME NULL,
    `lgpd_consent_ip` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_cand_job` (`job_id`),
    KEY `idx_rh_cand_status` (`status`),
    KEY `idx_rh_cand_talent` (`in_talent_pool`),
    KEY `idx_rh_cand_token` (`access_token`),
    CONSTRAINT `fk_rh_cand_job` FOREIGN KEY (`job_id`) REFERENCES `rh_recruitment_jobs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_cand_step` FOREIGN KEY (`current_step_id`) REFERENCES `rh_recruitment_steps`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_candidate_progress (Progresso do candidato nas etapas)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_candidate_progress` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `candidate_id` INT UNSIGNED NOT NULL,
    `step_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pendente','aprovado','reprovado') NOT NULL DEFAULT 'pendente',
    `notes` TEXT NULL,
    `evaluated_by` INT UNSIGNED NULL,
    `evaluated_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_progress_cand` (`candidate_id`),
    KEY `idx_rh_progress_step` (`step_id`),
    CONSTRAINT `fk_rh_progress_cand` FOREIGN KEY (`candidate_id`) REFERENCES `rh_candidates`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_progress_step` FOREIGN KEY (`step_id`) REFERENCES `rh_recruitment_steps`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_progress_eval` FOREIGN KEY (`evaluated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employee_scores (Pontuação interna por funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employee_scores` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL,
    `reason` TEXT NOT NULL,
    `category` VARCHAR(50) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_score_employee` (`employee_id`, `created_at`),
    CONSTRAINT `fk_rh_score_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_score_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employee_compliments (Elogios recebidos por funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employee_compliments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `compliment_from` VARCHAR(200) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_compliment_employee` (`employee_id`, `created_at`),
    CONSTRAINT `fk_rh_compliment_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_compliment_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_public_submissions (Rate-limit do recrutamento público)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_public_submissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `job_id` INT UNSIGNED NULL,
    `email` VARCHAR(200) NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_pub_ip` (`ip_address`, `submitted_at`),
    KEY `idx_rh_pub_email` (`email`, `job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_vacations (Gestão de Férias)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_vacations` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `period_start` DATE NOT NULL,
    `period_end` DATE NOT NULL,
    `start_date` DATE NULL,
    `end_date` DATE NULL,
    `days` INT UNSIGNED NOT NULL DEFAULT 30,
    `sold_days` INT UNSIGNED NOT NULL DEFAULT 0,
    `installment` TINYINT UNSIGNED NOT NULL DEFAULT 1,
    `status` ENUM('planejada','solicitada','aprovada','em_gozo','concluida','rejeitada') NOT NULL DEFAULT 'planejada',
    `approved_by` INT UNSIGNED NULL,
    `approved_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_vac_employee` (`employee_id`, `period_start`),
    KEY `idx_rh_vac_status` (`status`),
    KEY `idx_rh_vac_dates` (`start_date`, `end_date`),
    CONSTRAINT `fk_rh_vac_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_vac_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_vac_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_shift_templates (Templates de escalas)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_shift_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `work_hours` INT UNSIGNED NOT NULL DEFAULT 12,
    `rest_hours` INT UNSIGNED NOT NULL DEFAULT 36,
    `color` VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_shifts (Escalas de plantão)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_shifts` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `template_id` INT UNSIGNED NULL,
    `shift_date` DATE NOT NULL,
    `start_time` TIME NOT NULL,
    `end_time` TIME NOT NULL,
    `type` ENUM('regular','extra','sobreaviso','cobertura') NOT NULL DEFAULT 'regular',
    `status` ENUM('agendado','confirmado','realizado','falta','troca_pendente','trocado') NOT NULL DEFAULT 'agendado',
    `swap_with_id` BIGINT UNSIGNED NULL,
    `notes` TEXT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_shift_emp_date` (`employee_id`, `shift_date`),
    KEY `idx_rh_shift_dept_date` (`department_id`, `shift_date`),
    KEY `idx_rh_shift_status` (`status`),
    CONSTRAINT `fk_rh_shift_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_shift_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_shift_template` FOREIGN KEY (`template_id`) REFERENCES `rh_shift_templates`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_shift_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_onboarding_templates / items / progress
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_onboarding_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `type` ENUM('onboarding','offboarding') NOT NULL DEFAULT 'onboarding',
    `department_id` INT UNSIGNED NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rh_obt_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_onboarding_template_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `responsible_role` VARCHAR(50) NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rh_obti_template` FOREIGN KEY (`template_id`) REFERENCES `rh_onboarding_templates`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_onboarding_progress` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `template_id` INT UNSIGNED NOT NULL,
    `item_id` INT UNSIGNED NOT NULL,
    `completed` TINYINT(1) NOT NULL DEFAULT 0,
    `completed_by` INT UNSIGNED NULL,
    `completed_at` DATETIME NULL,
    `notes` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_rh_ob_progress` (`employee_id`, `item_id`),
    CONSTRAINT `fk_rh_obp_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_obp_template` FOREIGN KEY (`template_id`) REFERENCES `rh_onboarding_templates`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_obp_item` FOREIGN KEY (`item_id`) REFERENCES `rh_onboarding_template_items`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_obp_user` FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_training_catalog + rh_training_records
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_training_catalog` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `category` VARCHAR(100) NULL,
    `hours` DECIMAL(5,1) NULL,
    `mandatory` TINYINT(1) NOT NULL DEFAULT 0,
    `validity_months` INT UNSIGNED NULL,
    `department_id` INT UNSIGNED NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_rh_tc_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_training_records` (
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
    KEY `idx_rh_tr_employee` (`employee_id`),
    KEY `idx_rh_tr_expires` (`expires_at`),
    CONSTRAINT `fk_rh_tr_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_tr_catalog` FOREIGN KEY (`catalog_id`) REFERENCES `rh_training_catalog`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_tr_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_announcements + rh_announcement_reads
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_announcements` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `body` TEXT NOT NULL,
    `type` ENUM('informativo','urgente','celebracao') NOT NULL DEFAULT 'informativo',
    `department_id` INT UNSIGNED NULL,
    `published_at` DATETIME NULL,
    `expires_at` DATE NULL,
    `pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rh_ann_published` (`published_at`),
    CONSTRAINT `fk_rh_ann_dept` FOREIGN KEY (`department_id`) REFERENCES `rh_departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_ann_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_announcement_reads` (
    `announcement_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `read_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`announcement_id`, `user_id`),
    CONSTRAINT `fk_rh_annr_ann` FOREIGN KEY (`announcement_id`) REFERENCES `rh_announcements`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_annr_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_surveys + rh_survey_questions + rh_survey_responses
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_surveys` (
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
    CONSTRAINT `fk_rh_survey_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_survey_questions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT UNSIGNED NOT NULL,
    `question` TEXT NOT NULL,
    `type` ENUM('rating','text','choice') NOT NULL DEFAULT 'rating',
    `options` JSON NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_rh_sq_survey` FOREIGN KEY (`survey_id`) REFERENCES `rh_surveys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rh_survey_responses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `rating` INT NULL,
    `answer` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_sr_survey` (`survey_id`),
    CONSTRAINT `fk_rh_sr_survey` FOREIGN KEY (`survey_id`) REFERENCES `rh_surveys`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_sr_question` FOREIGN KEY (`question_id`) REFERENCES `rh_survey_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_salary_history
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_salary_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `salary` DECIMAL(12,2) NOT NULL,
    `reason` VARCHAR(200) NULL,
    `effective_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_sh_employee` (`employee_id`, `effective_date`),
    CONSTRAINT `fk_rh_sh_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_sh_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_requests (Solicitações self-service)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_requests` (
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
    KEY `idx_rh_req_employee` (`employee_id`),
    KEY `idx_rh_req_status` (`status`),
    CONSTRAINT `fk_rh_req_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_req_responder` FOREIGN KEY (`responded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_digital_signatures
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_digital_signatures` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `document_type` VARCHAR(100) NOT NULL,
    `document_id` INT UNSIGNED NULL,
    `document_title` VARCHAR(200) NOT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `signer_ip` VARCHAR(45) NOT NULL,
    `signer_user_agent` VARCHAR(500) NULL,
    `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_rh_sig_employee` (`employee_id`),
    KEY `idx_rh_sig_doctype` (`document_type`, `document_id`),
    CONSTRAINT `fk_rh_sig_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_employee_dependents
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_employee_dependents` (
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
    KEY `idx_rh_dep_employee` (`employee_id`),
    CONSTRAINT `fk_rh_dep_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- rh_warnings (Advertências)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rh_warnings` (
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
    KEY `idx_rh_warn_employee` (`employee_id`),
    CONSTRAINT `fk_rh_warn_employee` FOREIGN KEY (`employee_id`) REFERENCES `rh_employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rh_warn_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_rh_warn_sig` FOREIGN KEY (`signature_id`) REFERENCES `rh_digital_signatures`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Seeds mínimos (idempotentes)
-- ------------------------------------------------------------

-- Templates de escalas
INSERT INTO `rh_shift_templates` (`name`, `work_hours`, `rest_hours`, `color`)
SELECT * FROM (
    SELECT '12x36 Dia' AS name, 12 AS work_hours, 36 AS rest_hours, '#0d6efd' AS color UNION ALL
    SELECT '12x36 Noite',      12, 36, '#6610f2' UNION ALL
    SELECT '6x1',               8, 16, '#198754' UNION ALL
    SELECT 'Diarista (8h)',     8, 16, '#fd7e14' UNION ALL
    SELECT 'Plantonista 24h',  24, 72, '#dc3545'
) seed
WHERE NOT EXISTS (SELECT 1 FROM `rh_shift_templates`);

-- Templates de onboarding/offboarding
INSERT INTO `rh_onboarding_templates` (`name`, `type`)
SELECT 'Admissao Geral', 'onboarding'
WHERE NOT EXISTS (SELECT 1 FROM `rh_onboarding_templates` WHERE `name` = 'Admissao Geral');

INSERT INTO `rh_onboarding_templates` (`name`, `type`)
SELECT 'Desligamento Geral', 'offboarding'
WHERE NOT EXISTS (SELECT 1 FROM `rh_onboarding_templates` WHERE `name` = 'Desligamento Geral');

INSERT INTO `rh_onboarding_template_items` (`template_id`, `title`, `responsible_role`, `sort_order`)
SELECT t.id, seed.title, seed.role, seed.ord
FROM (
    SELECT 'Admissao Geral' AS tpl, 'Documentos pessoais entregues' AS title, 'rh' AS role, 1 AS ord UNION ALL
    SELECT 'Admissao Geral', 'Exame admissional (ASO) realizado', 'rh', 2 UNION ALL
    SELECT 'Admissao Geral', 'Contrato assinado', 'rh', 3 UNION ALL
    SELECT 'Admissao Geral', 'EPIs entregues', 'rh', 4 UNION ALL
    SELECT 'Admissao Geral', 'Treinamento NR-32 realizado', 'rh', 5 UNION ALL
    SELECT 'Admissao Geral', 'Cracha/acesso entregue', 'rh', 6 UNION ALL
    SELECT 'Admissao Geral', 'Apresentacao ao setor', 'gestor', 7 UNION ALL
    SELECT 'Admissao Geral', 'Acesso ao sistema criado', 'admin', 8 UNION ALL
    SELECT 'Desligamento Geral', 'Exame demissional realizado', 'rh', 1 UNION ALL
    SELECT 'Desligamento Geral', 'Devolucao de cracha e chaves', 'rh', 2 UNION ALL
    SELECT 'Desligamento Geral', 'Devolucao de EPIs', 'rh', 3 UNION ALL
    SELECT 'Desligamento Geral', 'Devolucao de uniformes', 'rh', 4 UNION ALL
    SELECT 'Desligamento Geral', 'Revogacao de acessos', 'admin', 5 UNION ALL
    SELECT 'Desligamento Geral', 'Entrevista de desligamento', 'rh', 6 UNION ALL
    SELECT 'Desligamento Geral', 'Documentos rescisorios entregues', 'rh', 7
) seed
JOIN `rh_onboarding_templates` t ON t.name = seed.tpl
WHERE NOT EXISTS (
    SELECT 1 FROM `rh_onboarding_template_items` i
    WHERE i.template_id = t.id AND i.title = seed.title
);

SET FOREIGN_KEY_CHECKS = 1;
