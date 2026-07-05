-- Migration: adiciona tabela equipment_calibrations
-- Aplicar em instalações existentes do ManuHosp (v4.0 → v4.1).
-- Seguro para rodar múltiplas vezes (CREATE TABLE IF NOT EXISTS).

CREATE TABLE IF NOT EXISTS `equipment_calibrations` (
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
