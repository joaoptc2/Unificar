-- ============================================================
-- RH Hospital - Schema SQL Completo
-- Sistema de Gestão de Recursos Humanos Hospitalar
-- Compatível com MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- Tabela: departments (Departamentos/Setores)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `departments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_department_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: job_positions (Cargos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `job_positions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(150) NOT NULL,
    `department_id` INT UNSIGNED NULL,
    `description` TEXT NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_position_dept` (`department_id`),
    CONSTRAINT `fk_position_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: users (Utilizadores do sistema)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `email` VARCHAR(200) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','rh','gestor','visualizador','funcionario') NOT NULL DEFAULT 'visualizador',
    `department_id` INT UNSIGNED NULL,
    `employee_id` INT UNSIGNED NULL,
    `theme_preference` VARCHAR(10) NOT NULL DEFAULT 'light',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `last_login` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_user_email` (`email`),
    KEY `idx_user_role` (`role`),
    KEY `idx_user_employee` (`employee_id`),
    CONSTRAINT `fk_user_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: employees (Funcionários)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employees` (
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
    UNIQUE KEY `uk_employee_cpf` (`cpf`),
    KEY `idx_employee_name` (`full_name`),
    KEY `idx_employee_status` (`status`),
    KEY `idx_employee_dept` (`department_id`),
    KEY `idx_employee_position` (`job_position_id`),
    KEY `idx_employee_admission` (`admission_date`),
    CONSTRAINT `fk_employee_position` FOREIGN KEY (`job_position_id`) REFERENCES `job_positions`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_employee_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_employee_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: employee_documents (Documentos do funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_documents` (
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
    KEY `idx_doc_employee` (`employee_id`),
    KEY `idx_doc_type` (`doc_type`),
    CONSTRAINT `fk_doc_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_doc_uploader` FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: employee_records (Histórico / Ficha Funcional)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_records` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `record_type` ENUM('admissao','promocao','transferencia','afastamento','retorno','desligamento','alteracao','observacao') NOT NULL,
    `description` TEXT NOT NULL,
    `old_value` TEXT NULL,
    `new_value` TEXT NULL,
    `record_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_record_employee` (`employee_id`),
    KEY `idx_record_type` (`record_type`),
    KEY `idx_record_date` (`record_date`),
    CONSTRAINT `fk_record_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_record_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: medical_certificates (Atestados médicos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `medical_certificates` (
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
    KEY `idx_cert_employee` (`employee_id`),
    KEY `idx_cert_date` (`issue_date`),
    CONSTRAINT `fk_cert_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cert_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: expirations (Vencimentos e Obrigações)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expirations` (
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
    KEY `idx_exp_employee` (`employee_id`),
    KEY `idx_exp_type` (`type`),
    KEY `idx_exp_expiry` (`expiry_date`),
    KEY `idx_exp_status` (`status`),
    CONSTRAINT `fk_exp_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exp_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: expiration_history (Histórico de renovações)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `expiration_history` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expiration_id` INT UNSIGNED NOT NULL,
    `old_expiry_date` DATE NOT NULL,
    `new_expiry_date` DATE NOT NULL,
    `notes` TEXT NULL,
    `renewed_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_exphist_exp` (`expiration_id`),
    CONSTRAINT `fk_exphist_exp` FOREIGN KEY (`expiration_id`) REFERENCES `expirations`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_exphist_user` FOREIGN KEY (`renewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: schedules (Agenda e Compromissos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schedules` (
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
    KEY `idx_sched_date` (`event_date`),
    KEY `idx_sched_employee` (`employee_id`),
    CONSTRAINT `fk_sched_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sched_expiration` FOREIGN KEY (`expiration_id`) REFERENCES `expirations`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_sched_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: recruitment_jobs (Vagas de Processos Seletivos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recruitment_jobs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `requirements` TEXT NULL,
    `department_id` INT UNSIGNED NULL,
    `status` ENUM('aberta','fechada') NOT NULL DEFAULT 'aberta',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_job_status` (`status`),
    CONSTRAINT `fk_job_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_job_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: recruitment_steps (Etapas do Processo Seletivo)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `recruitment_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `job_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(100) NOT NULL,
    `step_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `description` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_step_job` (`job_id`),
    CONSTRAINT `fk_step_job` FOREIGN KEY (`job_id`) REFERENCES `recruitment_jobs`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: candidates (Candidatos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `candidates` (
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
    KEY `idx_cand_job` (`job_id`),
    KEY `idx_cand_status` (`status`),
    KEY `idx_cand_talent` (`in_talent_pool`),
    KEY `idx_cand_token` (`access_token`),
    CONSTRAINT `fk_cand_job` FOREIGN KEY (`job_id`) REFERENCES `recruitment_jobs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_cand_step` FOREIGN KEY (`current_step_id`) REFERENCES `recruitment_steps`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: candidate_progress (Progresso do candidato nas etapas)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `candidate_progress` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `candidate_id` INT UNSIGNED NOT NULL,
    `step_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pendente','aprovado','reprovado') NOT NULL DEFAULT 'pendente',
    `notes` TEXT NULL,
    `evaluated_by` INT UNSIGNED NULL,
    `evaluated_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_progress_cand` (`candidate_id`),
    KEY `idx_progress_step` (`step_id`),
    CONSTRAINT `fk_progress_cand` FOREIGN KEY (`candidate_id`) REFERENCES `candidates`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_progress_step` FOREIGN KEY (`step_id`) REFERENCES `recruitment_steps`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_progress_eval` FOREIGN KEY (`evaluated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: notifications (Notificações)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT NOT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'info',
    `link` VARCHAR(500) NULL,
    `reference_type` VARCHAR(50) NULL,
    `reference_id` INT UNSIGNED NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `read_at` DATETIME NULL,
    `sent_email` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_notif_user` (`user_id`),
    KEY `idx_notif_read` (`is_read`),
    KEY `idx_notif_type` (`type`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: audit_log (Log de Auditoria)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(100) NOT NULL,
    `table_name` VARCHAR(100) NULL,
    `record_id` INT UNSIGNED NULL,
    `old_data` JSON NULL,
    `new_data` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `user_agent` VARCHAR(500) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_table` (`table_name`),
    KEY `idx_audit_date` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: login_attempts (Controle de tentativas de login)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabela: employee_scores (Pontuação interna por funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_scores` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `points` INT NOT NULL,
    `reason` TEXT NOT NULL,
    `category` VARCHAR(50) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_score_employee` (`employee_id`, `created_at`),
    CONSTRAINT `fk_score_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_score_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: employee_compliments (Elogios recebidos por funcionário)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `employee_compliments` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `message` TEXT NOT NULL,
    `compliment_from` VARCHAR(200) NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_compliment_employee` (`employee_id`, `created_at`),
    CONSTRAINT `fk_compliment_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_compliment_creator`  FOREIGN KEY (`created_by`)  REFERENCES `users`(`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: user_2fa (Autenticação em duas etapas — TOTP)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabela: password_resets (Tokens de redefinição de senha)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `password_resets` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `expires_at` DATETIME NOT NULL,
    `used_at` DATETIME NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_pwreset_token` (`token_hash`),
    KEY `idx_pwreset_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_pwreset_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: public_submissions (Controle de envios no recrutamento público)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `public_submissions` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `ip_address` VARCHAR(45) NOT NULL,
    `job_id` INT UNSIGNED NULL,
    `email` VARCHAR(200) NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_pub_ip` (`ip_address`, `submitted_at`),
    KEY `idx_pub_email` (`email`, `job_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: settings (Configurações do sistema)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(100) NOT NULL,
    `setting_value` TEXT NULL,
    `description` VARCHAR(255) NULL,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Dados iniciais: Configurações padrão
-- ------------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`, `description`) VALUES
('hospital_name', 'Hospital Central', 'Nome do hospital'),
('system_email', 'rh@hospital.com.br', 'E-mail do sistema para envio de notificações'),
('upload_max_size', '5242880', 'Tamanho máximo de upload em bytes (5MB)'),
('expiry_alert_days', '30', 'Dias de antecedência para alertas de vencimento'),
('items_per_page', '20', 'Itens por página na listagem');

-- ------------------------------------------------------------
-- Tabela: vacations (Gestao de Ferias)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `vacations` (
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
    KEY `idx_vac_employee` (`employee_id`, `period_start`),
    KEY `idx_vac_status` (`status`),
    KEY `idx_vac_dates` (`start_date`, `end_date`),
    CONSTRAINT `fk_vac_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_vac_approver` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_vac_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: shift_templates (Templates de escalas)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shift_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `work_hours` INT UNSIGNED NOT NULL DEFAULT 12,
    `rest_hours` INT UNSIGNED NOT NULL DEFAULT 36,
    `color` VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: shifts (Escalas de plantao)
-- ------------------------------------------------------------
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
    `swap_with_id` BIGINT UNSIGNED NULL,
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

-- ------------------------------------------------------------
-- Tabela: onboarding_templates
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `onboarding_templates` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `type` ENUM('onboarding','offboarding') NOT NULL DEFAULT 'onboarding',
    `department_id` INT UNSIGNED NULL,
    `active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_obt_dept` FOREIGN KEY (`department_id`) REFERENCES `departments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `onboarding_template_items` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `template_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT NULL,
    `responsible_role` VARCHAR(50) NULL,
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

-- ------------------------------------------------------------
-- Tabela: training_catalog + training_records
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `training_catalog` (
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

-- ------------------------------------------------------------
-- Tabela: announcements + announcement_reads
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `announcements` (
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

-- ------------------------------------------------------------
-- Tabela: surveys + survey_questions + survey_responses
-- ------------------------------------------------------------
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
    `options` JSON NULL,
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_sq_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `survey_responses` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `survey_id` INT UNSIGNED NOT NULL,
    `question_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `rating` INT NULL,
    `answer` TEXT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sr_survey` (`survey_id`),
    CONSTRAINT `fk_sr_survey` FOREIGN KEY (`survey_id`) REFERENCES `surveys`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sr_question` FOREIGN KEY (`question_id`) REFERENCES `survey_questions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: salary_history
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `salary_history` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `salary` DECIMAL(12,2) NOT NULL,
    `reason` VARCHAR(200) NULL,
    `effective_date` DATE NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sh_employee` (`employee_id`, `effective_date`),
    CONSTRAINT `fk_sh_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sh_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: requests (Solicitacoes self-service)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabela: digital_signatures
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `digital_signatures` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `employee_id` INT UNSIGNED NOT NULL,
    `document_type` VARCHAR(100) NOT NULL,
    `document_id` INT UNSIGNED NULL,
    `document_title` VARCHAR(200) NOT NULL,
    `content_hash` CHAR(64) NOT NULL,
    `signer_ip` VARCHAR(45) NOT NULL,
    `signer_user_agent` VARCHAR(500) NULL,
    `signed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_sig_employee` (`employee_id`),
    KEY `idx_sig_doctype` (`document_type`, `document_id`),
    CONSTRAINT `fk_sig_employee` FOREIGN KEY (`employee_id`) REFERENCES `employees`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Tabela: employee_dependents
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Tabela: warnings (Advertencias)
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
-- Dados iniciais: Templates de escalas
-- ------------------------------------------------------------
INSERT INTO `shift_templates` (`name`, `work_hours`, `rest_hours`, `color`) VALUES
('12x36 Dia', 12, 36, '#0d6efd'),
('12x36 Noite', 12, 36, '#6610f2'),
('6x1', 8, 16, '#198754'),
('Diarista (8h)', 8, 16, '#fd7e14'),
('Plantonista 24h', 24, 72, '#dc3545');

-- ------------------------------------------------------------
-- Dados iniciais: Templates de onboarding
-- ------------------------------------------------------------
INSERT INTO `onboarding_templates` (`name`, `type`) VALUES
('Admissao Geral', 'onboarding'),
('Desligamento Geral', 'offboarding');

INSERT INTO `onboarding_template_items` (`template_id`, `title`, `responsible_role`, `sort_order`) VALUES
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Documentos pessoais entregues', 'rh', 1),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Exame admissional (ASO) realizado', 'rh', 2),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Contrato assinado', 'rh', 3),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'EPIs entregues', 'rh', 4),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Treinamento NR-32 realizado', 'rh', 5),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Cracha/acesso entregue', 'rh', 6),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Apresentacao ao setor', 'gestor', 7),
((SELECT id FROM onboarding_templates WHERE name='Admissao Geral' LIMIT 1), 'Acesso ao sistema criado', 'admin', 8),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Exame demissional realizado', 'rh', 1),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de cracha e chaves', 'rh', 2),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de EPIs', 'rh', 3),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Devolucao de uniformes', 'rh', 4),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Revogacao de acessos', 'admin', 5),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Entrevista de desligamento', 'rh', 6),
((SELECT id FROM onboarding_templates WHERE name='Desligamento Geral' LIMIT 1), 'Documentos rescisorios entregues', 'rh', 7);

SET FOREIGN_KEY_CHECKS = 1;
