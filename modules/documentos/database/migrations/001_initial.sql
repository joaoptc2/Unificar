-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Sistema de Gestão Hospitalar — Schema do Banco de Dados              ║
-- ║  MySQL 5.7+ / MariaDB 10.3+                                          ║
-- ║  Charset: utf8mb4 (suporte completo a Unicode)                        ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ═══════════════════════════════════════════════════════════════════════════
--  1. HOSPITAIS
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `hospitals` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(200)    NOT NULL,
    `cnpj`       VARCHAR(20)     DEFAULT NULL,
    `address`    VARCHAR(300)    DEFAULT NULL,
    `phone`      VARCHAR(20)     DEFAULT NULL,
    `email`      VARCHAR(150)    DEFAULT NULL,
    `is_active`  TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_hospitals_active` (`is_active`, `deleted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  2. USUÁRIOS
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `users` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED    NOT NULL,
    `name`        VARCHAR(150)    NOT NULL,
    `email`       VARCHAR(150)    NOT NULL,
    `password`    VARCHAR(255)    NOT NULL,
    `role_id`     TINYINT UNSIGNED NOT NULL DEFAULT 3 COMMENT '1=Admin Global, 2=Gestor, 3=Operador',
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `last_login`  DATETIME        DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_users_email_hospital` (`email`, `hospital_id`),
    INDEX `idx_users_hospital` (`hospital_id`),
    INDEX `idx_users_role` (`role_id`),
    INDEX `idx_users_active` (`is_active`, `deleted_at`),
    CONSTRAINT `fk_users_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  3. DOCUMENTOS
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `documents` (
    `id`                  INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id`         INT UNSIGNED    NOT NULL,
    `title`               VARCHAR(250)    NOT NULL,
    `category`            VARCHAR(100)    NOT NULL,
    `responsible`         VARCHAR(150)    DEFAULT NULL,
    `expiration_date`     DATE            NOT NULL,
    `notify_days_before`  INT UNSIGNED    NOT NULL DEFAULT 30,
    `observations`        TEXT            DEFAULT NULL,
    `file_name`           VARCHAR(255)    DEFAULT NULL,
    `file_path`           VARCHAR(255)    DEFAULT NULL,
    `file_size`           BIGINT UNSIGNED DEFAULT NULL,
    `file_type`           VARCHAR(10)     DEFAULT NULL,
    `created_by`          INT UNSIGNED    DEFAULT NULL,
    `created_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`          DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_docs_hospital` (`hospital_id`),
    INDEX `idx_docs_expiration` (`expiration_date`),
    INDEX `idx_docs_category` (`category`),
    INDEX `idx_docs_deleted` (`deleted_at`),
    CONSTRAINT `fk_docs_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_docs_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  4. INDICADORES DE ENFERMAGEM
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicators` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED    NOT NULL,
    `name`        VARCHAR(200)    NOT NULL,
    `type`        ENUM('daily','monthly','yearly') NOT NULL DEFAULT 'monthly',
    `unit`        VARCHAR(50)     DEFAULT NULL,
    `goal`        VARCHAR(50)     DEFAULT NULL,
    `variables`   VARCHAR(500)    DEFAULT NULL,
    `description` TEXT            DEFAULT NULL,
    `created_by`  INT UNSIGNED    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_indicators_hospital` (`hospital_id`),
    INDEX `idx_indicators_type` (`type`),
    INDEX `idx_indicators_deleted` (`deleted_at`),
    CONSTRAINT `fk_indicators_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_indicators_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  5. DADOS DOS INDICADORES (lançamentos)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicator_data` (
    `id`             INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `indicator_id`   INT UNSIGNED    NOT NULL,
    `reference_date` DATE            NOT NULL,
    `value`          DECIMAL(15,4)   NOT NULL,
    `observations`   TEXT            DEFAULT NULL,
    `recorded_by`    INT UNSIGNED    DEFAULT NULL,
    `created_at`     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`     DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_inddata_indicator` (`indicator_id`),
    INDEX `idx_inddata_date` (`reference_date`),
    CONSTRAINT `fk_inddata_indicator`
        FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_inddata_recorded_by`
        FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  6. NOTIFICAÇÕES
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `hospital_id` INT UNSIGNED    NOT NULL,
    `user_id`     INT UNSIGNED    NOT NULL,
    `title`       VARCHAR(200)    NOT NULL,
    `message`     TEXT            NOT NULL,
    `type`        VARCHAR(50)     NOT NULL DEFAULT 'info',
    `is_read`     TINYINT(1)      NOT NULL DEFAULT 0,
    `read_at`     DATETIME        DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME        DEFAULT NULL,
    PRIMARY KEY (`id`),
    INDEX `idx_notif_user` (`user_id`, `is_read`),
    INDEX `idx_notif_hospital` (`hospital_id`),
    INDEX `idx_notif_deleted` (`deleted_at`),
    CONSTRAINT `fk_notif_hospital`
        FOREIGN KEY (`hospital_id`) REFERENCES `hospitals` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notif_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
--  7. LOG DE AUDITORIA
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`     INT UNSIGNED    DEFAULT NULL,
    `hospital_id` INT UNSIGNED    DEFAULT NULL,
    `action`      VARCHAR(100)    NOT NULL,
    `details`     TEXT            DEFAULT NULL,
    `ip_address`  VARCHAR(45)     DEFAULT NULL,
    `user_agent`  VARCHAR(255)    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_audit_user` (`user_id`),
    INDEX `idx_audit_hospital` (`hospital_id`),
    INDEX `idx_audit_action` (`action`),
    INDEX `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- ═══════════════════════════════════════════════════════════════════════════
--  DADOS INICIAIS
-- ═══════════════════════════════════════════════════════════════════════════

-- Hospital padrão
INSERT INTO `hospitals` (`id`, `name`, `cnpj`, `email`, `is_active`)
VALUES (1, 'Hospital Central', '00.000.000/0001-00', 'contato@hospitalcentral.com.br', 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);

-- Usuário administrador padrão
-- Senha: admin123 (hash bcrypt)
INSERT INTO `users` (`id`, `hospital_id`, `name`, `email`, `password`, `role_id`, `is_active`)
VALUES (1, 1, 'Administrador', 'admin@hospital.com',
        '$2y$12$LJ3m4ys3Gzf0U5OUQqGxneFBjRCAJRo8Ahi/B1/4FpiWCiJfmGHKu',
        1, 1)
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
