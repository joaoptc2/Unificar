-- ============================================================
-- Migração 005 — Melhorias no cadastro de funcionários
-- (ASO, pontos, elogios, portal do funcionário)
-- ============================================================

-- 1. ASO admissional (data de realização + próxima)
ALTER TABLE `employees`
    ADD COLUMN `aso_admissional_date` DATE NULL AFTER `council_expiry`,
    ADD COLUMN `aso_next_date`        DATE NULL AFTER `aso_admissional_date`;

-- 2. Estender doc_type para incluir Treinamento e EPI
ALTER TABLE `employee_documents`
    MODIFY COLUMN `doc_type` ENUM('RG','CPF','Contrato','Certificado','Atestado','Treinamento','EPI','Outro') NOT NULL;

-- 3. Vincular users a employees + novo perfil 'funcionario'
ALTER TABLE `users`
    MODIFY COLUMN `role` ENUM('admin','rh','gestor','visualizador','funcionario') NOT NULL DEFAULT 'visualizador',
    ADD COLUMN `employee_id` INT UNSIGNED NULL AFTER `department_id`,
    ADD CONSTRAINT `fk_user_employee` FOREIGN KEY (`employee_id`)
        REFERENCES `employees`(`id`) ON DELETE SET NULL;

CREATE INDEX `idx_user_employee` ON `users`(`employee_id`);

-- 4. Pontuação interna
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

-- 5. Elogios internos
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
