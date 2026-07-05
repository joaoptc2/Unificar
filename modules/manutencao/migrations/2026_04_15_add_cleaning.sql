-- Migration: módulo de limpeza hospitalar (checklists e execuções).
-- Aplicar em instalações existentes. Idempotente.

CREATE TABLE IF NOT EXISTS `cleaning_schedules` (
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
    KEY `idx_cs_sector` (`sector_id`),
    CONSTRAINT `fk_cs_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cs_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `cleaning_executions` (
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
    KEY `idx_ce_sector` (`sector_id`),
    KEY `idx_ce_executed_at` (`executed_at`),
    CONSTRAINT `fk_ce_hospital` FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ce_schedule` FOREIGN KEY (`schedule_id`) REFERENCES `cleaning_schedules` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_sector` FOREIGN KEY (`sector_id`) REFERENCES `sectors` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ce_user` FOREIGN KEY (`executed_by_user`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
