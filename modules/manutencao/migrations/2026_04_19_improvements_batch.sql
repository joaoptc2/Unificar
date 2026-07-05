-- ============================================================
-- Migration: 16 melhorias do ManuHosp
-- Aplicar em instalacoes existentes. Idempotente (CREATE IF NOT EXISTS + checks).
-- ============================================================

-- ============================================================
-- 1. EQUIPMENT LIFECYCLE (Melhoria 1)
-- ============================================================
ALTER TABLE `equipment`
    ADD COLUMN IF NOT EXISTS `installation_date` DATE DEFAULT NULL AFTER `acquisition_date`,
    ADD COLUMN IF NOT EXISTS `useful_life_years` SMALLINT UNSIGNED DEFAULT NULL AFTER `installation_date`,
    ADD COLUMN IF NOT EXISTS `deactivation_date` DATETIME DEFAULT NULL AFTER `useful_life_years`,
    ADD COLUMN IF NOT EXISTS `deactivation_reason` TEXT DEFAULT NULL AFTER `deactivation_date`;

-- ============================================================
-- 2. SERVICE_ORDERS: downtime, signature, checklist, labor, tracking (Melhorias 3,4,6,9,13)
-- ============================================================
ALTER TABLE `service_orders`
    ADD COLUMN IF NOT EXISTS `downtime_start` DATETIME DEFAULT NULL AFTER `completed_at`,
    ADD COLUMN IF NOT EXISTS `downtime_end` DATETIME DEFAULT NULL AFTER `downtime_start`,
    ADD COLUMN IF NOT EXISTS `signature_data` MEDIUMTEXT DEFAULT NULL AFTER `photo_path`,
    ADD COLUMN IF NOT EXISTS `checklist_template_id` INT UNSIGNED DEFAULT NULL AFTER `signature_data`,
    ADD COLUMN IF NOT EXISTS `checklist_checked` TEXT DEFAULT NULL AFTER `checklist_template_id`,
    ADD COLUMN IF NOT EXISTS `labor_hours` DECIMAL(6,2) DEFAULT NULL AFTER `actual_hours`,
    ADD COLUMN IF NOT EXISTS `labor_cost_per_hour` DECIMAL(10,2) DEFAULT NULL AFTER `labor_hours`,
    ADD COLUMN IF NOT EXISTS `tracking_token` VARCHAR(64) DEFAULT NULL AFTER `anonymous_token`;

-- Index para tracking (ignora erro se ja existir)
-- ALTER TABLE `service_orders` ADD KEY `idx_os_tracking` (`tracking_token`);

-- ============================================================
-- 3. OS_PARTS - pecas utilizadas por OS (Melhoria 2)
-- ============================================================
CREATE TABLE IF NOT EXISTS `os_parts` (
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

-- ============================================================
-- 4. OS_CHECKLISTS - templates de checklist por tipo de OS (Melhoria 6)
-- ============================================================
CREATE TABLE IF NOT EXISTS `os_checklists` (
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

-- ============================================================
-- 5. OS_ATTACHMENTS - multiplos anexos por OS (Melhoria 12)
-- ============================================================
CREATE TABLE IF NOT EXISTS `os_attachments` (
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

-- ============================================================
-- 6. INSPECTION_ROUTES - rotas de inspecao (Melhoria 17)
-- ============================================================
CREATE TABLE IF NOT EXISTS `inspection_routes` (
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

-- ============================================================
-- 7. INSPECTION_EXECUTIONS - execucoes de inspecao (Melhoria 17)
-- ============================================================
CREATE TABLE IF NOT EXISTS `inspection_executions` (
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

-- Migrar photo_path existentes para os_attachments (dados legados)
INSERT INTO `os_attachments` (`os_id`, `file_path`, `file_name`, `type`, `uploaded_by`, `created_at`)
SELECT `id`, `photo_path`, SUBSTRING_INDEX(`photo_path`, '/', -1), 'photo', `created_by`, `created_at`
FROM `service_orders`
WHERE `photo_path` IS NOT NULL AND `photo_path` != ''
AND `id` NOT IN (SELECT `os_id` FROM `os_attachments`);
