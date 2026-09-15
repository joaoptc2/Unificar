-- ============================================================================
--  014 — Temas nomeados da identidade visual
--
--  Até aqui os "temas prontos" eram constantes no código e a personalização
--  vivia solta em settings: para experimentar outra cara do portal o
--  administrador tinha que anotar as cores antigas num papel, mexer em doze
--  campos e torcer para conseguir voltar.
--
--  Agora um tema é um registro: guarda o conjunto INTEIRO de valores da
--  aparência, pode ser aplicado, e o que estava valendo antes é preservado
--  automaticamente (tema "Antes de ..."), o que dá o desfazer.
--
--  Idempotente.
-- ============================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `brand_themes` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(80)  NOT NULL,
    `description` VARCHAR(255) DEFAULT NULL,
    -- JSON com todas as chaves de Core\Branding::DEFAULTS. Guardar o conjunto
    -- inteiro (e não um "delta") é o que faz aplicar um tema ser previsível:
    -- o resultado não depende do que estava valendo antes.
    `values_json` MEDIUMTEXT   NOT NULL,
    -- Marca o tema gerado automaticamente antes de aplicar outro (o desfazer).
    `is_snapshot` TINYINT(1)   NOT NULL DEFAULT 0,
    `created_by`  INT UNSIGNED DEFAULT NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `applied_at`  DATETIME     DEFAULT NULL COMMENT 'Última vez que foi aplicado',
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_brand_theme_nome` (`name`),
    INDEX `idx_brand_theme_snap` (`is_snapshot`, `id`),
    CONSTRAINT `fk_brand_theme_user` FOREIGN KEY (`created_by`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
