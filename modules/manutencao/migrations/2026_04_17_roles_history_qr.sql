-- Migration: novos roles, historico de OS, locais com QR code
-- Aplicar em instalacoes existentes. Idempotente.

-- 1. Migrar roles: adicionar 'maintenance' e 'cleaning', remover 'technician'
ALTER TABLE `users` MODIFY `role` ENUM('admin','manager','maintenance','cleaning','viewer') NOT NULL DEFAULT 'viewer';
UPDATE `users` SET `role` = 'maintenance' WHERE `role` = 'technician';

-- 2. Historico de OS
CREATE TABLE IF NOT EXISTS `os_history` (
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

-- 3. Locais com QR code
CREATE TABLE IF NOT EXISTS `qr_locations` (
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
