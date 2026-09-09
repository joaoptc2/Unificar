-- ============================================================
-- MÓDULO INTRANET — Documentos institucionais com layout
-- padronizado, versionamento e exportação em PDF.
-- Tabelas prefixadas com intra_. Usuários = tabela global users.
-- ============================================================

SET NAMES utf8mb4;

-- (A tabela intra_layouts — layouts de documentos — agora é do NÚCLEO:
--  sql/schema.sql, gerenciada em Administração > Layouts de documentos.)

-- Documentos
CREATE TABLE IF NOT EXISTS intra_documents (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    layout_id       INT UNSIGNED NULL,
    cover_layout_id INT UNSIGNED NULL COMMENT 'Layout de capa (intra_layouts) — opcional',
    content_html    MEDIUMTEXT NULL,
    cover_html      MEDIUMTEXT NULL COMMENT 'Conteúdo da capa (vazio = modelo do layout)',
    font_family     VARCHAR(80) NULL COMMENT 'Fonte do texto (permitida pelo layout)',
    font_size       VARCHAR(10) NULL COMMENT 'Tamanho do texto (permitido pelo layout)',
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
    CONSTRAINT fk_intrad_cover_layout FOREIGN KEY (cover_layout_id) REFERENCES intra_layouts(id) ON DELETE SET NULL,
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
    cover_layout_id INT UNSIGNED NULL,
    content_html MEDIUMTEXT NULL,
    cover_html  MEDIUMTEXT NULL,
    font_family VARCHAR(80) NULL,
    font_size   VARCHAR(10) NULL,
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
