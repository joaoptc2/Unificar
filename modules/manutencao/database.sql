-- ============================================================
-- SISTEMA DE GESTÃO DE MANUTENÇÃO HOSPITALAR v5.0
-- Schema completo — 23 tabelas
-- Compatível com MySQL 5.7+ / MariaDB 10.3+
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `inspection_executions`;
DROP TABLE IF EXISTS `inspection_routes`;
DROP TABLE IF EXISTS `os_attachments`;
DROP TABLE IF EXISTS `os_checklists`;
DROP TABLE IF EXISTS `os_parts`;
DROP TABLE IF EXISTS `os_history`;
DROP TABLE IF EXISTS `qr_locations`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `cleaning_executions`;
DROP TABLE IF EXISTS `cleaning_schedules`;
DROP TABLE IF EXISTS `equipment_calibrations`;
DROP TABLE IF EXISTS `maintenance_plans`;
DROP TABLE IF EXISTS `technicians`;
DROP TABLE IF EXISTS `stock_movements`;
DROP TABLE IF EXISTS `parts`;
DROP TABLE IF EXISTS `service_orders`;
DROP TABLE IF EXISTS `equipment`;
DROP TABLE IF EXISTS `equipment_categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `sectors`;
DROP TABLE IF EXISTS `hospitals`;

-- 1. HOSPITAIS
CREATE TABLE `hospitals` (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 2. SETORES
CREATE TABLE `sectors` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sectors_hospital` (`hospital_id`),
    CONSTRAINT `fk_sectors_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 3. USUÁRIOS
CREATE TABLE `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `role` ENUM('admin','manager','maintenance','cleaning','viewer') NOT NULL DEFAULT 'viewer',
    `phone` VARCHAR(20) DEFAULT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `last_login` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email` (`email`),
    KEY `idx_users_hospital` (`hospital_id`),
    CONSTRAINT `fk_users_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 4. CATEGORIAS DE EQUIPAMENTO
CREATE TABLE `equipment_categories` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `name` VARCHAR(150) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_eqcat_hospital` (`hospital_id`),
    CONSTRAINT `fk_eqcat_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 5. EQUIPAMENTOS
CREATE TABLE `equipment` (
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
    KEY `idx_equip_hospital` (`hospital_id`),
    KEY `idx_equip_sector` (`sector_id`),
    KEY `idx_equip_category` (`category_id`),
    KEY `idx_equip_status` (`status`),
    CONSTRAINT `fk_equip_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_equip_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_equip_category` FOREIGN KEY (`category_id`) REFERENCES `equipment_categories` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 6. ORDENS DE SERVIÇO
CREATE TABLE `service_orders` (
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
    UNIQUE KEY `uk_os_number` (`os_number`),
    KEY `idx_os_hospital` (`hospital_id`),
    KEY `idx_os_equipment` (`equipment_id`),
    KEY `idx_os_status` (`status`),
    KEY `idx_os_assigned` (`assigned_to`),
    KEY `idx_os_created_by` (`created_by`),
    KEY `idx_os_anonymous` (`anonymous_token`),
    KEY `idx_os_tracking` (`tracking_token`),
    CONSTRAINT `fk_os_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_os_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_os_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_os_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 7. PEÇAS
CREATE TABLE `parts` (
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
    KEY `idx_parts_hospital` (`hospital_id`),
    CONSTRAINT `fk_parts_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 8. MOVIMENTAÇÕES DE ESTOQUE
CREATE TABLE `stock_movements` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `part_id` INT UNSIGNED NOT NULL,
    `type` ENUM('entry','exit','adjustment') NOT NULL,
    `quantity` INT NOT NULL,
    `reason` VARCHAR(255) DEFAULT NULL,
    `created_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_sm_hospital` (`hospital_id`),
    KEY `idx_sm_part` (`part_id`),
    CONSTRAINT `fk_sm_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sm_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_sm_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 9. TÉCNICOS
CREATE TABLE `technicians` (
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
    KEY `idx_tech_hospital` (`hospital_id`),
    CONSTRAINT `fk_tech_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tech_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 10. PLANOS DE MANUTENÇÃO PREVENTIVA
CREATE TABLE `maintenance_plans` (
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
    KEY `idx_mp_hospital` (`hospital_id`),
    KEY `idx_mp_equipment` (`equipment_id`),
    KEY `idx_mp_next_date` (`next_date`),
    CONSTRAINT `fk_mp_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mp_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 11. NOTIFICAÇÕES
CREATE TABLE `notifications` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `type` VARCHAR(50) NOT NULL DEFAULT 'info',
    `title` VARCHAR(200) NOT NULL,
    `message` TEXT DEFAULT NULL,
    `reference_id` INT UNSIGNED DEFAULT NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notif_hospital` (`hospital_id`),
    KEY `idx_notif_user` (`user_id`),
    KEY `idx_notif_read` (`is_read`),
    CONSTRAINT `fk_notif_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 12. CALIBRAÇÕES
CREATE TABLE `equipment_calibrations` (
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
    KEY `idx_calib_hospital` (`hospital_id`),
    KEY `idx_calib_equipment` (`equipment_id`),
    KEY `idx_calib_next_date` (`next_date`),
    CONSTRAINT `fk_calib_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calib_equipment` FOREIGN KEY (`equipment_id`) REFERENCES `equipment` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_calib_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 13. CHECKLISTS DE LIMPEZA
CREATE TABLE `cleaning_schedules` (
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
    KEY `idx_cs_hospital` (`hospital_id`),
    CONSTRAINT `fk_cs_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 14. EXECUÇÕES DE LIMPEZA
CREATE TABLE `cleaning_executions` (
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
    KEY `idx_ce_hospital` (`hospital_id`),
    KEY `idx_ce_schedule` (`schedule_id`),
    CONSTRAINT `fk_ce_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ce_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `cleaning_schedules` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_user` FOREIGN KEY (`executed_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 15. HISTÓRICO DE OS
CREATE TABLE `os_history` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `user_name` VARCHAR(200) DEFAULT NULL,
    `action` VARCHAR(100) NOT NULL,
    `details` TEXT DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_osh_os` (`os_id`),
    CONSTRAINT `fk_osh_os` FOREIGN KEY (`os_id`) REFERENCES `service_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 16. QR LOCATIONS
CREATE TABLE `qr_locations` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `sector_id` INT UNSIGNED DEFAULT NULL,
    `name` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `token` VARCHAR(64) NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_qr_token` (`token`),
    KEY `idx_qr_hospital` (`hospital_id`),
    CONSTRAINT `fk_qr_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_qr_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 17. PEÇAS POR OS
CREATE TABLE `os_parts` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `part_id` INT UNSIGNED NOT NULL,
    `quantity` INT NOT NULL DEFAULT 1,
    `unit_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_osp_os` (`os_id`),
    KEY `idx_osp_part` (`part_id`),
    CONSTRAINT `fk_osp_os` FOREIGN KEY (`os_id`) REFERENCES `service_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_osp_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 18. CHECKLISTS POR TIPO DE OS
CREATE TABLE `os_checklists` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `os_type` ENUM('preventive','corrective','predictive','calibration','inspection') NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `items` TEXT NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_oscl_hospital` (`hospital_id`),
    CONSTRAINT `fk_oscl_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 19. ANEXOS POR OS
CREATE TABLE `os_attachments` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `os_id` INT UNSIGNED NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `file_name` VARCHAR(200) NOT NULL,
    `type` VARCHAR(50) DEFAULT 'photo',
    `uploaded_by` INT UNSIGNED DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_osa_os` (`os_id`),
    CONSTRAINT `fk_osa_os` FOREIGN KEY (`os_id`) REFERENCES `service_orders` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_osa_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 20. ROTAS DE INSPEÇÃO
CREATE TABLE `inspection_routes` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(200) NOT NULL,
    `description` TEXT DEFAULT NULL,
    `locations` TEXT NOT NULL,
    `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_ir_hospital` (`hospital_id`),
    CONSTRAINT `fk_ir_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 21. EXECUÇÕES DE INSPEÇÃO
CREATE TABLE `inspection_executions` (
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
    KEY `idx_ie_route` (`route_id`),
    CONSTRAINT `fk_ie_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ie_route` FOREIGN KEY (`route_id`) REFERENCES `inspection_routes` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ie_user` FOREIGN KEY (`executed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 22. LOG DE AUDITORIA
CREATE TABLE `audit_log` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED DEFAULT NULL,
    `user_id` INT UNSIGNED DEFAULT NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity` VARCHAR(50) DEFAULT NULL,
    `entity_id` INT UNSIGNED DEFAULT NULL,
    `details` TEXT DEFAULT NULL,
    `ip_address` VARCHAR(45) DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_hospital` (`hospital_id`),
    KEY `idx_audit_action` (`action`),
    KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
