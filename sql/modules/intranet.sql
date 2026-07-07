-- ============================================================
-- MÓDULO INTRANET — Documentos institucionais com layout
-- padronizado, versionamento e exportação em PDF.
-- Tabelas prefixadas com intra_. Usuários = tabela global users.
-- ============================================================

SET NAMES utf8mb4;

-- Layouts predefinidos (papel timbrado do hospital)
CREATE TABLE IF NOT EXISTS intra_layouts (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    description   VARCHAR(255) NULL,
    page_size     VARCHAR(20)  NOT NULL DEFAULT 'A4' COMMENT 'A4, A3, A5, Letter, Oficio',
    orientation   ENUM('portrait','landscape') NOT NULL DEFAULT 'portrait',
    margin_top    SMALLINT UNSIGNED NOT NULL DEFAULT 20 COMMENT 'mm',
    margin_right  SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    margin_bottom SMALLINT UNSIGNED NOT NULL DEFAULT 20,
    margin_left   SMALLINT UNSIGNED NOT NULL DEFAULT 15,
    header_html   MEDIUMTEXT NULL COMMENT 'aceita {{logo}} {{titulo}} {{autor}} {{data}} {{versao}}',
    footer_html   MEDIUMTEXT NULL,
    header_height SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'mm; >0 repete em todas as páginas',
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

-- Documentos
CREATE TABLE IF NOT EXISTS intra_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    layout_id       INT UNSIGNED NULL,
    content_html    MEDIUMTEXT NULL,
    status          ENUM('draft','published') NOT NULL DEFAULT 'draft',
    is_public       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'cópia pública via public_token',
    public_token    CHAR(32) NOT NULL,
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intradoc_token (public_token),
    KEY idx_intradoc_status (status),
    CONSTRAINT fk_intrad_layout FOREIGN KEY (layout_id)  REFERENCES intra_layouts(id) ON DELETE SET NULL,
    CONSTRAINT fk_intrad_cby    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_intrad_uby    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Histórico de versões (registro e acompanhamento das edições)
CREATE TABLE IF NOT EXISTS intra_document_versions (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    document_id INT UNSIGNED NOT NULL,
    version     INT UNSIGNED NOT NULL,
    title       VARCHAR(200) NOT NULL,
    layout_id   INT UNSIGNED NULL,
    content_html MEDIUMTEXT NULL,
    note        VARCHAR(255) NULL COMMENT 'descrição da alteração',
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_intraver (document_id, version),
    CONSTRAINT fk_intrav_doc FOREIGN KEY (document_id) REFERENCES intra_documents(id) ON DELETE CASCADE,
    CONSTRAINT fk_intrav_usr FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro do módulo na plataforma
INSERT INTO modules (slug, name, icon, sort_order, active)
VALUES ('intranet', 'Intranet', 'bi-newspaper', 50, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Layout inicial (papel timbrado A4 genérico)
INSERT INTO intra_layouts
    (name, description, page_size, orientation, margin_top, margin_right, margin_bottom, margin_left,
     header_html, footer_html, header_height, footer_height, is_default, active)
SELECT 'Padrão A4 (retrato)', 'Layout inicial — personalize em Intranet > Layouts', 'A4', 'portrait',
       25, 15, 20, 15,
       '<div style="display:flex;align-items:center;gap:10px;border-bottom:2px solid #0d5c8f;padding-bottom:6px;">{{logo}}<div><strong style="font-size:14pt;color:#0d5c8f;">{{org}}</strong><br><span style="font-size:9pt;color:#555;">{{titulo}}</span></div></div>',
       '<div style="border-top:1px solid #ccc;padding-top:4px;font-size:8pt;color:#666;display:flex;justify-content:space-between;"><span>{{titulo}} — v{{versao}}</span><span>Atualizado em {{data}} por {{autor}}</span></div>',
       18, 12, 1, 1
WHERE NOT EXISTS (SELECT 1 FROM intra_layouts);
