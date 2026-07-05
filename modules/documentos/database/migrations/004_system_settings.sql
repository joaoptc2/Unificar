-- ╔══════════════════════════════════════════════════════════════════════════╗
-- ║  Migration 004 — Tabela de configurações visuais do sistema              ║
-- ╚══════════════════════════════════════════════════════════════════════════╝

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `system_settings` (
    `id`         INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `setting_key`   VARCHAR(100) NOT NULL,
    `setting_value` TEXT         DEFAULT NULL,
    `updated_by` INT UNSIGNED    DEFAULT NULL,
    `updated_at` DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Valores iniciais (design system padrão)
INSERT INTO `system_settings` (`setting_key`, `setting_value`) VALUES
    ('app_name',          'Sistema de Gestão Documental'),
    ('primary_color',     '#0d6efd'),
    ('sidebar_bg',        '#1e293b'),
    ('sidebar_text',      '#94a3b8'),
    ('sidebar_hover_bg',  '#334155'),
    ('sidebar_active_text','#ffffff'),
    ('navbar_bg',         '#0d6efd'),
    ('body_bg',           '#f1f5f9'),
    ('body_font',         '''Segoe UI'', system-ui, -apple-system, sans-serif'),
    ('body_font_size',    '0.9'),
    ('card_shadow',       '0 1px 3px rgba(0,0,0,.08)'),
    ('card_border_radius','0.5'),
    ('login_gradient_start','#0d6efd'),
    ('login_gradient_end',  '#6610f2'),
    ('logo_icon',         'bi-hospital'),
    ('footer_text',       ''),
    ('custom_css',        '')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
