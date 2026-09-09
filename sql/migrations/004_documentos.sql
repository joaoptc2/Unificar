-- ============================================================
-- Migração 004 — Módulo Documentos
--   • Documentos controlados × não controlados (is_controlled);
--   • validade opcional (não controlados não têm controle de vencimento);
--   • documento editado no sistema (editor com layouts do hospital):
--     content_html, cover_html, layout_id, cover_layout_id, fonte/tamanho
--     — também no histórico de versões.
-- Idempotente (o runner tolera "coluna/índice/FK já existe").
-- ============================================================

ALTER TABLE doc_documents ADD COLUMN is_controlled TINYINT(1) NOT NULL DEFAULT 1
    COMMENT '1 = controlado (status, validade, revisão); 0 = não controlado (apenas armazenado)' AFTER status;
ALTER TABLE doc_documents MODIFY COLUMN expiration_date DATE NULL DEFAULT NULL;
ALTER TABLE doc_documents ADD COLUMN source ENUM('upload','editor') NOT NULL DEFAULT 'upload'
    COMMENT 'upload = arquivo enviado; editor = escrito no sistema' AFTER mime_type;
ALTER TABLE doc_documents ADD COLUMN content_html MEDIUMTEXT NULL AFTER source;
ALTER TABLE doc_documents ADD COLUMN cover_html MEDIUMTEXT NULL AFTER content_html;
ALTER TABLE doc_documents ADD COLUMN layout_id INT UNSIGNED NULL COMMENT 'Layout de página (intra_layouts)' AFTER cover_html;
ALTER TABLE doc_documents ADD COLUMN cover_layout_id INT UNSIGNED NULL COMMENT 'Layout de capa (intra_layouts)' AFTER layout_id;
ALTER TABLE doc_documents ADD COLUMN font_family VARCHAR(80) NULL AFTER cover_layout_id;
ALTER TABLE doc_documents ADD COLUMN font_size VARCHAR(10) NULL AFTER font_family;
ALTER TABLE doc_documents ADD INDEX idx_doc_docs_controlled (is_controlled, deleted_at);
ALTER TABLE doc_documents ADD CONSTRAINT fk_doc_docs_layout
    FOREIGN KEY (layout_id) REFERENCES intra_layouts (id) ON DELETE SET NULL;
ALTER TABLE doc_documents ADD CONSTRAINT fk_doc_docs_cover_layout
    FOREIGN KEY (cover_layout_id) REFERENCES intra_layouts (id) ON DELETE SET NULL;

ALTER TABLE doc_document_versions ADD COLUMN content_html MEDIUMTEXT NULL AFTER mime_type;
ALTER TABLE doc_document_versions ADD COLUMN cover_html MEDIUMTEXT NULL AFTER content_html;
ALTER TABLE doc_document_versions ADD COLUMN layout_id INT UNSIGNED NULL AFTER cover_html;
ALTER TABLE doc_document_versions ADD COLUMN cover_layout_id INT UNSIGNED NULL AFTER layout_id;
ALTER TABLE doc_document_versions ADD COLUMN font_family VARCHAR(80) NULL AFTER cover_layout_id;
ALTER TABLE doc_document_versions ADD COLUMN font_size VARCHAR(10) NULL AFTER font_family;
