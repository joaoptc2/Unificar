-- ============================================================
-- Migração 003 — Núcleo (atualização de setembro/2026)
--   • schema_migrations: controle das migrações aplicadas
--   • mail_queue: fila de e-mails (comunicados, pesquisas, alertas)
--   • intra_layouts: layouts de documentos compartilhados (fundo,
--     capa, fontes) — tabela criada aqui caso a Intranet não exista
--   • módulo Planejamento registrado
-- Idempotente: pode ser reaplicada (o runner tolera "já existe").
-- ============================================================

CREATE TABLE IF NOT EXISTS schema_migrations (
    filename   VARCHAR(150) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notes      TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_queue (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    to_email   VARCHAR(190) NOT NULL,
    to_name    VARCHAR(150) NULL,
    subject    VARCHAR(250) NOT NULL,
    body_html  MEDIUMTEXT NOT NULL,
    module     VARCHAR(40) NULL,
    ref_type   VARCHAR(60) NULL,
    ref_id     INT UNSIGNED NULL,
    status     ENUM('pending','sent','failed') NOT NULL DEFAULT 'pending',
    attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sent_at    DATETIME NULL,
    KEY idx_mq_status (status, id),
    KEY idx_mq_ref (module, ref_type, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS intra_layouts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    page_size     VARCHAR(20)  NOT NULL DEFAULT 'A4',
    orientation   ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
    margin_top    SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    margin_right  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    margin_bottom SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    margin_left   SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    header_html   MEDIUMTEXT NULL,
    footer_html   MEDIUMTEXT NULL,
    header_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    footer_height SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    custom_css    TEXT NULL,
    logo_path     VARCHAR(255) NULL,
    is_default    TINYINT(1) NOT NULL DEFAULT 0,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    created_by    INT UNSIGNED NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_intral_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE intra_layouts ADD COLUMN kind ENUM('both','page','cover') NOT NULL DEFAULT 'both' AFTER description;
ALTER TABLE intra_layouts ADD COLUMN cover_html MEDIUMTEXT NULL AFTER footer_height;
ALTER TABLE intra_layouts ADD COLUMN fonts TEXT NULL AFTER custom_css;
ALTER TABLE intra_layouts ADD COLUMN font_sizes TEXT NULL AFTER fonts;
ALTER TABLE intra_layouts ADD COLUMN default_font VARCHAR(80) NULL AFTER font_sizes;
ALTER TABLE intra_layouts ADD COLUMN default_font_size VARCHAR(10) NULL AFTER default_font;
ALTER TABLE intra_layouts ADD COLUMN background_path VARCHAR(255) NULL AFTER logo_path;
ALTER TABLE intra_layouts ADD COLUMN cover_background_path VARCHAR(255) NULL AFTER background_path;

INSERT IGNORE INTO modules (slug, name, icon, sort_order, active)
VALUES ('planejamento', 'Planejamento', 'bi-kanban', 60, 1);

INSERT INTO intra_layouts
    (name, description, page_size, orientation, margin_top, margin_right, margin_bottom, margin_left,
     header_html, footer_html, header_height, footer_height, is_default, active)
SELECT 'Padrão A4 (retrato)', 'Layout inicial — personalize em Administração > Layouts de documentos', 'A4', 'portrait',
       25, 15, 20, 15,
       '<div style="display:flex;align-items:center;gap:10px;border-bottom:2px solid #0d5c8f;padding-bottom:6px;">{{logo}}<div><strong style="font-size:14pt;color:#0d5c8f;">{{org}}</strong><br><span style="font-size:9pt;color:#555;">{{titulo}}</span></div></div>',
       '<div style="border-top:1px solid #ccc;padding-top:4px;font-size:8pt;color:#666;display:flex;justify-content:space-between;"><span>{{titulo}} — v{{versao}}</span><span>Atualizado em {{data}} por {{autor}}</span></div>',
       18, 12, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM intra_layouts);
