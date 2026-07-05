-- ============================================================
-- MÓDULO MANUTENÇÃO (man_) — Schema consolidado
-- Plataforma Unificada — banco único, tabelas prefixadas.
--
-- Consolida o database.sql v5 do legado + migrations/*.sql
-- (calibrações, limpeza, roles/histórico/QR, melhorias em lote).
--
-- Tabelas que DEIXARAM de existir no módulo (agora são do núcleo):
--   users, notifications, audit_log, login_attempts.
-- FKs de usuário apontam para a tabela GLOBAL users(id) do núcleo
-- (INT UNSIGNED) — execute sql/schema.sql ANTES deste arquivo.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS + seeds condicionais.
-- Compatível com MySQL 5.7+ / MariaDB 10.3+.
-- ============================================================

SET NAMES utf8mb4;

-- 1. HOSPITAIS (unidades do módulo — hospital_id das demais tabelas)
CREATE TABLE IF NOT EXISTS `man_hospitals` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(200) NOT NULL,
    `cnpj` VARCHAR(20) DEFAULT NULL,
    `address` VARCHAR(255) DEFAULT NULL,
    `city` VARCHAR(100) DEFAULT NULL,
    `state` VARCHAR(2) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `contact_person` VARCHAR(150) DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. SETORES
CREATE TABLE IF NOT EXISTS `man_sectors` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_sectors_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_sectors_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. CATEGORIAS DE EQUIPAMENTO
CREATE TABLE IF NOT EXISTS `man_equipment_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_eqcat_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_eqcat_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. EQUIPAMENTOS (inclui colunas de lifecycle da migration 2026_04_19)
CREATE TABLE IF NOT EXISTS `man_equipment` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED DEFAULT NULL,
    `category_id` INT UNSIGNED DEFAULT NULL,
    `code` VARCHAR(50) DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `manufacturer` VARCHAR(150) DEFAULT NULL,
    `model` VARCHAR(150) DEFAULT NULL,
    `serial_number` VARCHAR(100) DEFAULT NULL,
    `acquisition_date` DATE DEFAULT NULL,
    `installation_date` DATE DEFAULT NULL,
    `useful_life_years` SMALLINT UNSIGNED DEFAULT NULL,
    `deactivation_date` DATETIME DEFAULT NULL,
    `deactivation_reason` TEXT DEFAULT NULL,
    `criticality` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `status` ENUM('active','maintenance','inactive','broken') NOT NULL DEFAULT 'active',
    `description` TEXT DEFAULT NULL,
    `qr_token` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_equip_hospital` (`hospital_id`),
    KEY `idx_man_equip_sector` (`sector_id`),
    KEY `idx_man_equip_category` (`category_id`),
    KEY `idx_man_equip_status` (`status`),
    CONSTRAINT `fk_man_equip_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_equip_sector` FOREIGN KEY (`sector_id`) REFERENCES `man_sectors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_man_equip_category` FOREIGN KEY (`category_id`) REFERENCES `man_equipment_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ORDENS DE SERVIÇO
--    assigned_to / created_by → tabela GLOBAL users(id) do núcleo
CREATE TABLE IF NOT EXISTS `man_service_orders` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `equipment_id` INT UNSIGNED DEFAULT NULL,
    `os_number` VARCHAR(30) NOT NULL,
    `type` ENUM('preventive','corrective','predictive','calibration','inspection') NOT NULL DEFAULT 'corrective',
    `priority` ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `status` ENUM('open','in_progress','waiting_part','completed','cancelled') NOT NULL DEFAULT 'open',
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `solution` TEXT DEFAULT NULL,
    `observation` TEXT DEFAULT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `signature_data` MEDIUMTEXT DEFAULT NULL,
    `assigned_to` INT UNSIGNED DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `scheduled_date` DATE DEFAULT NULL,
    `started_at` DATETIME DEFAULT NULL,
    `completed_at` DATETIME DEFAULT NULL,
    `downtime_start` DATETIME DEFAULT NULL,
    `downtime_end` DATETIME DEFAULT NULL,
    `estimated_hours` DECIMAL(6,2) DEFAULT NULL,
    `actual_hours` DECIMAL(6,2) DEFAULT NULL,
    `labor_hours` DECIMAL(6,2) DEFAULT NULL,
    `labor_cost_per_hour` DECIMAL(10,2) DEFAULT NULL,
    `cost` DECIMAL(12,2) DEFAULT 0.00,
    `checklist_template_id` INT UNSIGNED DEFAULT NULL,
    `checklist_checked` TEXT DEFAULT NULL,
    `anonymous_token` VARCHAR(64) DEFAULT NULL,
    `tracking_token` VARCHAR(64) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_man_os_number` (`os_number`),
    KEY `idx_man_os_hospital` (`hospital_id`),
    KEY `idx_man_os_equipment` (`equipment_id`),
    KEY `idx_man_os_status` (`status`),
    KEY `idx_man_os_assigned` (`assigned_to`),
    KEY `idx_man_os_created_by` (`created_by`),
    KEY `idx_man_os_anonymous` (`anonymous_token`),
    KEY `idx_man_os_tracking` (`tracking_token`),
    CONSTRAINT `fk_man_os_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_os_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `man_equipment` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_man_os_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_man_os_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. PEÇAS
CREATE TABLE IF NOT EXISTS `man_parts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `code` VARCHAR(50) DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `unit` VARCHAR(20) DEFAULT 'un',
    `quantity` INT NOT NULL DEFAULT 0,
    `min_quantity` INT NOT NULL DEFAULT 5,
    `unit_cost` DECIMAL(12,2) DEFAULT 0.00,
    `supplier` VARCHAR(150) DEFAULT NULL,
    `location` VARCHAR(100) DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_parts_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_parts_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. MOVIMENTAÇÕES DE ESTOQUE (created_by → users global)
CREATE TABLE IF NOT EXISTS `man_stock_movements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `part_id` INT UNSIGNED NOT NULL,
    `type` ENUM('entry','exit','adjustment') NOT NULL,
    `quantity` INT NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_sm_hospital` (`hospital_id`),
    KEY `idx_man_sm_part` (`part_id`),
    CONSTRAINT `fk_man_sm_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_sm_part` FOREIGN KEY (`part_id`) REFERENCES `man_parts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 8. TÉCNICOS (user_id → users global, opcional)
CREATE TABLE IF NOT EXISTS `man_technicians` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `specialty` VARCHAR(150) DEFAULT NULL,
    `phone` VARCHAR(20) DEFAULT NULL,
    `email` VARCHAR(150) DEFAULT NULL,
    `crea` VARCHAR(50) DEFAULT NULL,
    `status` ENUM('available','busy','off','inactive') NOT NULL DEFAULT 'available',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_tech_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_tech_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_tech_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 9. PLANOS DE MANUTENÇÃO PREVENTIVA
CREATE TABLE IF NOT EXISTS `man_maintenance_plans` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `equipment_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `frequency` ENUM('daily','weekly','biweekly','monthly','quarterly','semiannual','annual') NOT NULL DEFAULT 'monthly',
    `next_date` DATE DEFAULT NULL,
    `last_executed` DATETIME DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_mp_hospital` (`hospital_id`),
    KEY `idx_man_mp_equipment` (`equipment_id`),
    KEY `idx_man_mp_next_date` (`next_date`),
    CONSTRAINT `fk_man_mp_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_mp_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `man_equipment` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- (NOTIFICAÇÕES: agora na tabela global `notifications` do núcleo,
--  com module='manutencao' — não existe man_notifications.)

-- 10. CALIBRAÇÕES (created_by → users global)
CREATE TABLE IF NOT EXISTS `man_equipment_calibrations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `equipment_id` INT UNSIGNED NOT NULL,
    `calibration_date` DATE NOT NULL,
    `next_date` DATE NOT NULL,
    `responsible_body` VARCHAR(200) DEFAULT NULL,
    `responsible_person` VARCHAR(200) DEFAULT NULL,
    `result` ENUM('conforme','nao_conforme','conforme_com_ressalvas') NOT NULL DEFAULT 'conforme',
    `certificate_path` VARCHAR(255) DEFAULT NULL,
    `observations` TEXT DEFAULT NULL,
    `cost` DECIMAL(12,2) DEFAULT 0.00,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_calib_hospital` (`hospital_id`),
    KEY `idx_man_calib_equipment` (`equipment_id`),
    KEY `idx_man_calib_next_date` (`next_date`),
    CONSTRAINT `fk_man_calib_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_calib_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `man_equipment` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_calib_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 11. CHECKLISTS DE LIMPEZA
CREATE TABLE IF NOT EXISTS `man_cleaning_schedules` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED DEFAULT NULL,
    `title` VARCHAR(200) NOT NULL,
    `type` ENUM('concurrent','terminal','preparatory') NOT NULL DEFAULT 'concurrent',
    `frequency` ENUM('daily','weekly','biweekly','monthly','on_demand') NOT NULL DEFAULT 'daily',
    `checklist_items` TEXT DEFAULT NULL,
    `instructions` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_cs_hospital` (`hospital_id`),
    KEY `idx_man_cs_sector` (`sector_id`),
    CONSTRAINT `fk_man_cs_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_cs_sector` FOREIGN KEY (`sector_id`) REFERENCES `man_sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 12. EXECUÇÕES DE LIMPEZA (executed_by_user → users global)
CREATE TABLE IF NOT EXISTS `man_cleaning_executions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `schedule_id` INT UNSIGNED DEFAULT NULL,
    `sector_id` INT UNSIGNED DEFAULT NULL,
    `type` ENUM('concurrent','terminal','preparatory') NOT NULL DEFAULT 'concurrent',
    `executed_by_name` VARCHAR(200) NOT NULL,
    `executed_by_user` INT UNSIGNED DEFAULT NULL,
    `executed_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `checked_items` TEXT DEFAULT NULL,
    `compliance_pct` TINYINT UNSIGNED DEFAULT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `signature_data` MEDIUMTEXT DEFAULT NULL,
    `observation` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_ce_hospital` (`hospital_id`),
    KEY `idx_man_ce_schedule` (`schedule_id`),
    KEY `idx_man_ce_sector` (`sector_id`),
    KEY `idx_man_ce_executed_at` (`executed_at`),
    CONSTRAINT `fk_man_ce_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_ce_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `man_cleaning_schedules` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_man_ce_sector` FOREIGN KEY (`sector_id`) REFERENCES `man_sectors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_man_ce_user` FOREIGN KEY (`executed_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 13. HISTÓRICO DE OS (user_id informativo — sem FK, como no legado)
CREATE TABLE IF NOT EXISTS `man_os_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `user_name` VARCHAR(200) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_osh_os` (`os_id`),
    CONSTRAINT `fk_man_osh_os` FOREIGN KEY (`os_id`) REFERENCES `man_service_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 14. LOCAIS COM QR CODE
CREATE TABLE IF NOT EXISTS `man_qr_locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `token` VARCHAR(64) NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_man_qr_token` (`token`),
    KEY `idx_man_qr_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_qr_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_qr_sector` FOREIGN KEY (`sector_id`) REFERENCES `man_sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 15. PEÇAS POR OS
CREATE TABLE IF NOT EXISTS `man_os_parts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `part_id` INT UNSIGNED NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_osp_os` (`os_id`),
    KEY `idx_man_osp_part` (`part_id`),
    CONSTRAINT `fk_man_osp_os` FOREIGN KEY (`os_id`) REFERENCES `man_service_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_osp_part` FOREIGN KEY (`part_id`) REFERENCES `man_parts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 16. TEMPLATES DE CHECKLIST POR TIPO DE OS
CREATE TABLE IF NOT EXISTS `man_os_checklists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `os_type` ENUM('preventive','corrective','predictive','calibration','inspection') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `items` TEXT NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_oscl_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_oscl_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 17. ANEXOS POR OS (uploaded_by → users global)
CREATE TABLE IF NOT EXISTS `man_os_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_name` VARCHAR(200) NOT NULL,
    `type` VARCHAR(50) DEFAULT 'photo',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_osa_os` (`os_id`),
    CONSTRAINT `fk_man_osa_os` FOREIGN KEY (`os_id`) REFERENCES `man_service_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_osa_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 18. ROTAS DE INSPEÇÃO
CREATE TABLE IF NOT EXISTS `man_inspection_routes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `locations` TEXT NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_ir_hospital` (`hospital_id`),
    CONSTRAINT `fk_man_ir_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 19. EXECUÇÕES DE INSPEÇÃO (executed_by → users global)
CREATE TABLE IF NOT EXISTS `man_inspection_executions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `route_id` INT UNSIGNED NOT NULL,
    `executed_by` INT UNSIGNED DEFAULT NULL,
    `executed_by_name` VARCHAR(200) DEFAULT NULL,
    `results` TEXT NOT NULL,
    `photo_path` VARCHAR(255) DEFAULT NULL,
    `observation` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_man_ie_route` (`route_id`),
    CONSTRAINT `fk_man_ie_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `man_hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_ie_route` FOREIGN KEY (`route_id`) REFERENCES `man_inspection_routes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_man_ie_user` FOREIGN KEY (`executed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- SEEDS MÍNIMOS
-- ============================================================

-- Unidade padrão (hospital_id=1) — o núcleo popula $_SESSION['hospital_id']
-- a partir de settings.default_hospital_id (padrão '1').
INSERT INTO `man_hospitals` (`id`, `name`, `status`)
SELECT 1, 'Unidade Principal', 'active'
WHERE NOT EXISTS (SELECT 1 FROM `man_hospitals` WHERE `id` = 1);
