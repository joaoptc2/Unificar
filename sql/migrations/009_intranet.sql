-- ============================================================
-- Migração 009 — Módulo Intranet
--   • Layout de CAPA opcional + conteúdo próprio da capa;
--   • fonte e tamanho do texto (restritos aos permitidos pelo layout);
--   • os mesmos campos no histórico de versões (restauração fiel).
-- Idempotente (o runner tolera "coluna/FK já existe").
-- ============================================================

ALTER TABLE intra_documents ADD COLUMN cover_layout_id INT UNSIGNED NULL COMMENT 'Layout de capa (intra_layouts)' AFTER layout_id;
ALTER TABLE intra_documents ADD COLUMN cover_html MEDIUMTEXT NULL COMMENT 'Conteúdo da capa (vazio = modelo do layout)' AFTER content_html;
ALTER TABLE intra_documents ADD COLUMN font_family VARCHAR(80) NULL AFTER cover_html;
ALTER TABLE intra_documents ADD COLUMN font_size VARCHAR(10) NULL AFTER font_family;
ALTER TABLE intra_documents ADD CONSTRAINT fk_intrad_cover_layout
    FOREIGN KEY (cover_layout_id) REFERENCES intra_layouts (id) ON DELETE SET NULL;

ALTER TABLE intra_document_versions ADD COLUMN cover_layout_id INT UNSIGNED NULL AFTER layout_id;
ALTER TABLE intra_document_versions ADD COLUMN cover_html MEDIUMTEXT NULL AFTER content_html;
ALTER TABLE intra_document_versions ADD COLUMN font_family VARCHAR(80) NULL AFTER cover_html;
ALTER TABLE intra_document_versions ADD COLUMN font_size VARCHAR(10) NULL AFTER font_family;
