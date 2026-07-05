-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 003 — Indicadores avançados                                   ║
-- ║  Adiciona: variáveis (numerador/denominador/etc), fórmula,               ║
-- ║           meta com direção e tolerância, tipo de gráfico, categoria     ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

-- ═══════════════════════════════════════════════════════════════════════════
-- 1. Indicators: novas colunas
-- ═══════════════════════════════════════════════════════════════════════════
ALTER TABLE `indicators`
    ADD COLUMN `formula`        VARCHAR(500)  DEFAULT NULL
                                COMMENT 'Ex: (a/b)*100 — deixe nulo para valor direto' AFTER `variables`,
    ADD COLUMN `goal_numeric`   DECIMAL(15,4) DEFAULT NULL AFTER `goal`,
    ADD COLUMN `goal_direction` ENUM('higher_better','lower_better','target')
                                NOT NULL DEFAULT 'higher_better' AFTER `goal_numeric`,
    ADD COLUMN `goal_tolerance` DECIMAL(15,4) NOT NULL DEFAULT 0
                                COMMENT 'Tolerância absoluta aceitável em torno da meta' AFTER `goal_direction`,
    ADD COLUMN `chart_type`     ENUM('line','bar','area') NOT NULL DEFAULT 'line' AFTER `goal_tolerance`,
    ADD COLUMN `category`       VARCHAR(100)  DEFAULT NULL AFTER `chart_type`,
    ADD COLUMN `decimal_places` TINYINT UNSIGNED NOT NULL DEFAULT 2 AFTER `category`;

-- Tenta migrar goal (varchar) para goal_numeric quando for um número
UPDATE `indicators`
   SET `goal_numeric` = CAST(REPLACE(`goal`, ',', '.') AS DECIMAL(15,4))
 WHERE `goal` REGEXP '^-?[0-9]+([.,][0-9]+)?$';

-- ═══════════════════════════════════════════════════════════════════════════
-- 2. Variáveis do indicador (definições)
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicator_variables` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `indicator_id`  INT UNSIGNED    NOT NULL,
    `code`          VARCHAR(30)     NOT NULL COMMENT 'Identificador usado na fórmula (a, b, n, d...)',
    `label`         VARCHAR(150)    NOT NULL,
    `unit`          VARCHAR(50)     DEFAULT NULL,
    `display_order` INT             NOT NULL DEFAULT 0,
    `created_at`    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_indvar_code` (`indicator_id`, `code`),
    INDEX `idx_indvar_indicator` (`indicator_id`),
    CONSTRAINT `fk_indvar_indicator`
        FOREIGN KEY (`indicator_id`) REFERENCES `indicators` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════════════════════
-- 3. Valores das variáveis por lançamento
-- ═══════════════════════════════════════════════════════════════════════════
CREATE TABLE IF NOT EXISTS `indicator_data_values` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `data_id`     INT UNSIGNED    NOT NULL,
    `variable_id` INT UNSIGNED    NOT NULL,
    `value`       DECIMAL(18,6)   NOT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_datavar` (`data_id`, `variable_id`),
    INDEX `idx_idv_data` (`data_id`),
    INDEX `idx_idv_variable` (`variable_id`),
    CONSTRAINT `fk_idv_data`
        FOREIGN KEY (`data_id`) REFERENCES `indicator_data` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_idv_variable`
        FOREIGN KEY (`variable_id`) REFERENCES `indicator_variables` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
