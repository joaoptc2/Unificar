-- ============================================================================
-- MIGRAÇÃO DE DADOS — legado DOCUMENTOS → Plataforma Unificada
--
-- Pré-requisitos:
--   1. Núcleo instalado (sql/schema.sql) — tabelas users, modules,
--      user_module_access, notifications.
--   2. Schema do módulo aplicado (sql/modules/documentos.sql) — tabelas doc_*.
--   3. Banco legado acessível NO MESMO SERVIDOR com o nome `legado_documentos`
--      (ajuste o nome nos comandos abaixo, se necessário).
--
-- Estratégia:
--   * Usuários legados viram usuários GLOBAIS (match por e-mail; quem já
--     existe na plataforma não é duplicado). password → password_hash
--     (mesmo bcrypt), username = e-mail, is_active → active.
--   * O papel legado (role_id 1/2/3) vira acesso ao módulo em
--     user_module_access: 1→'admin', 2→'gestor', 3→'operador'.
--   * Demais tabelas copiam PRESERVANDO os IDs (doc_* recém-criadas devem
--     estar vazias — os seeds de doc_hospitals/doc_sectors/categorias são
--     sobrescritos/ignorados via ON DUPLICATE / INSERT IGNORE).
--   * Colunas de usuário (created_by, approved_by, recorded_by,
--     responsible_user_id, user_id) são REMAPEADAS via e-mail
--     (id legado → id global).
--   * NÃO migram: audit_logs, login_attempts, password_resets (núcleo tem
--     os próprios), system_settings visuais (tema agora é do núcleo).
--
-- Execute em transação / faça backup antes. Revise cada bloco.
-- ============================================================================

SET NAMES utf8mb4;

-- ────────────────────────────────────────────────────────────────────────────
-- 1. USUÁRIOS → tabela global `users` (match por e-mail)
--    username = e-mail · password → password_hash · is_active → active
--    (soft-deletados do legado não são migrados)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO users (name, username, email, password_hash, active, created_at)
SELECT lu.name,
       lu.email,                -- username = e-mail
       lu.email,
       lu.password,             -- bcrypt legado é compatível com password_verify
       lu.is_active,
       lu.created_at
FROM legado_documentos.users lu
WHERE lu.deleted_at IS NULL
  AND NOT EXISTS (SELECT 1 FROM users u WHERE u.email = lu.email);

-- ────────────────────────────────────────────────────────────────────────────
-- 2. PAPÉIS → user_module_access (module_slug = 'documentos')
--    role_id legado: 1 = Admin, 2 = Gestor, 3 = Operador
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO user_module_access (user_id, module_slug, role)
SELECT u.id,
       'documentos',
       CASE lu.role_id
           WHEN 1 THEN 'admin'
           WHEN 2 THEN 'gestor'
           ELSE 'operador'
       END
FROM legado_documentos.users lu
JOIN users u ON u.email = lu.email
WHERE lu.deleted_at IS NULL
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- (Opcional) Tornar os admins legados admins GLOBAIS da plataforma —
-- descomente apenas se essa for a decisão organizacional:
-- UPDATE users u
-- JOIN legado_documentos.users lu ON lu.email = u.email AND lu.role_id = 1
-- SET u.is_admin = 1
-- WHERE lu.deleted_at IS NULL;

-- ────────────────────────────────────────────────────────────────────────────
-- 3. HOSPITAIS → doc_hospitals (preserva IDs; sobrescreve o seed id=1)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_hospitals (id, name, cnpj, address, phone, email, is_active, created_at, updated_at, deleted_at)
SELECT id, name, cnpj, address, phone, email, is_active, created_at, updated_at, deleted_at
FROM legado_documentos.hospitals
ON DUPLICATE KEY UPDATE
    name = VALUES(name), cnpj = VALUES(cnpj), address = VALUES(address),
    phone = VALUES(phone), email = VALUES(email), is_active = VALUES(is_active),
    deleted_at = VALUES(deleted_at);

-- ────────────────────────────────────────────────────────────────────────────
-- 4. SETORES → doc_sectors (preserva IDs; pode colidir com o seed 'Geral' —
--    o ON DUPLICATE por PK sobrescreve o seed com o setor legado de mesmo id)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_sectors (id, hospital_id, name, code, description, is_active, created_at, updated_at, deleted_at)
SELECT id, hospital_id, name, code, description, is_active, created_at, updated_at, deleted_at
FROM legado_documentos.sectors
ON DUPLICATE KEY UPDATE
    hospital_id = VALUES(hospital_id), name = VALUES(name), code = VALUES(code),
    description = VALUES(description), is_active = VALUES(is_active),
    deleted_at = VALUES(deleted_at);

-- ────────────────────────────────────────────────────────────────────────────
-- 5. USUÁRIO ↔ SETOR → doc_user_sectors (user_id remapeado por e-mail)
-- ────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO doc_user_sectors (user_id, sector_id, created_at)
SELECT u.id, lus.sector_id, lus.created_at
FROM legado_documentos.user_sectors lus
JOIN legado_documentos.users lu ON lu.id = lus.user_id
JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 6. CATEGORIAS → doc_document_categories (match por nome; atualiza seeds)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_document_categories (name, description, icon, sort_order, is_active, created_at)
SELECT name, description, icon, sort_order, is_active, created_at
FROM legado_documentos.document_categories
ON DUPLICATE KEY UPDATE
    description = VALUES(description), icon = VALUES(icon),
    sort_order = VALUES(sort_order), is_active = VALUES(is_active);

-- ────────────────────────────────────────────────────────────────────────────
-- 7. DOCUMENTOS → doc_documents (preserva IDs; created_by/approved_by
--    remapeados por e-mail — usuários soft-deletados viram NULL)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_documents
    (id, hospital_id, sector_id, status, title, document_code, category, responsible,
     issuing_body, legal_basis, confidentiality, expiration_date, notify_days_before,
     review_interval_months, last_reviewed_at, next_review_date, observations,
     file_name, file_path, file_size, file_type, mime_type, current_version,
     created_by, approved_by, approved_at, created_at, updated_at, deleted_at)
SELECT d.id, d.hospital_id, d.sector_id, d.status, d.title, d.document_code, d.category, d.responsible,
       d.issuing_body, d.legal_basis, d.confidentiality, d.expiration_date, d.notify_days_before,
       d.review_interval_months, d.last_reviewed_at, d.next_review_date, d.observations,
       d.file_name, d.file_path, d.file_size, d.file_type, d.mime_type, d.current_version,
       uc.id, ua.id, d.approved_at, d.created_at, d.updated_at, d.deleted_at
FROM legado_documentos.documents d
LEFT JOIN legado_documentos.users luc ON luc.id = d.created_by
LEFT JOIN users uc ON uc.email = luc.email
LEFT JOIN legado_documentos.users lua ON lua.id = d.approved_by
LEFT JOIN users ua ON ua.email = lua.email;

-- Observação: os ARQUIVOS físicos devem ser movidos de
--   <legado>/uploads/hospital_<id>/  para  /uploads/documentos/hospital_<id>/
-- (os caminhos gravados em file_path são apenas o nome do arquivo salvo).

-- ────────────────────────────────────────────────────────────────────────────
-- 8. VERSÕES DE DOCUMENTOS → doc_document_versions
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_document_versions
    (id, document_id, version, file_name, file_path, file_size, file_type, mime_type,
     expiration_date, notes, created_by, created_at)
SELECT dv.id, dv.document_id, dv.version, dv.file_name, dv.file_path, dv.file_size, dv.file_type, dv.mime_type,
       dv.expiration_date, dv.notes, u.id, dv.created_at
FROM legado_documentos.document_versions dv
LEFT JOIN legado_documentos.users lu ON lu.id = dv.created_by
LEFT JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 9. CIÊNCIA DIGITAL → doc_document_acknowledgments (user remapeado)
-- ────────────────────────────────────────────────────────────────────────────
INSERT IGNORE INTO doc_document_acknowledgments (document_id, user_id, ip_address, acknowledged_at)
SELECT da.document_id, u.id, da.ip_address, da.acknowledged_at
FROM legado_documentos.document_acknowledgments da
JOIN legado_documentos.users lu ON lu.id = da.user_id
JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 10. INDICADORES → doc_indicators (preserva IDs; usuários remapeados)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicators
    (id, hospital_id, sector_id, name, type, unit, goal, variables, formula,
     goal_numeric, goal_direction, goal_tolerance, benchmark_value, benchmark_source,
     responsible_user_id, accreditation, template_slug, chart_type, category,
     decimal_places, description, created_by, created_at, updated_at, deleted_at)
SELECT i.id, i.hospital_id, i.sector_id, i.name, i.type, i.unit, i.goal, i.variables, i.formula,
       i.goal_numeric, i.goal_direction, i.goal_tolerance, i.benchmark_value, i.benchmark_source,
       ur.id, i.accreditation, i.template_slug, i.chart_type, i.category,
       i.decimal_places, i.description, uc.id, i.created_at, i.updated_at, i.deleted_at
FROM legado_documentos.indicators i
LEFT JOIN legado_documentos.users lur ON lur.id = i.responsible_user_id
LEFT JOIN users ur ON ur.email = lur.email
LEFT JOIN legado_documentos.users luc ON luc.id = i.created_by
LEFT JOIN users uc ON uc.email = luc.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 11. VARIÁVEIS → doc_indicator_variables (cópia direta, IDs preservados)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicator_variables (id, indicator_id, code, label, unit, display_order, created_at)
SELECT id, indicator_id, code, label, unit, display_order, created_at
FROM legado_documentos.indicator_variables;

-- ────────────────────────────────────────────────────────────────────────────
-- 12. LANÇAMENTOS → doc_indicator_data (recorded_by remapeado)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicator_data (id, indicator_id, reference_date, value, observations, recorded_by, created_at, deleted_at)
SELECT d.id, d.indicator_id, d.reference_date, d.value, d.observations, u.id, d.created_at, d.deleted_at
FROM legado_documentos.indicator_data d
LEFT JOIN legado_documentos.users lu ON lu.id = d.recorded_by
LEFT JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 13. VALORES DAS VARIÁVEIS → doc_indicator_data_values (cópia direta)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicator_data_values (id, data_id, variable_id, value)
SELECT id, data_id, variable_id, value
FROM legado_documentos.indicator_data_values;

-- ────────────────────────────────────────────────────────────────────────────
-- 14. PLANOS DE AÇÃO (PDCA) → doc_indicator_actions (created_by remapeado)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicator_actions
    (id, indicator_id, data_id, title, description, action_type, root_cause,
     verification, verified_at, responsible, due_date, status, completed_at,
     created_by, created_at, updated_at, deleted_at)
SELECT a.id, a.indicator_id, a.data_id, a.title, a.description, a.action_type, a.root_cause,
       a.verification, a.verified_at, a.responsible, a.due_date, a.status, a.completed_at,
       u.id, a.created_at, a.updated_at, a.deleted_at
FROM legado_documentos.indicator_actions a
LEFT JOIN legado_documentos.users lu ON lu.id = a.created_by
LEFT JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 15. IMPORTAÇÕES → doc_indicator_imports (imported_by remapeado)
-- ────────────────────────────────────────────────────────────────────────────
INSERT INTO doc_indicator_imports
    (id, indicator_id, file_name, rows_total, rows_imported, rows_errors, error_log, imported_by, created_at)
SELECT ii.id, ii.indicator_id, ii.file_name, ii.rows_total, ii.rows_imported, ii.rows_errors, ii.error_log, u.id, ii.created_at
FROM legado_documentos.indicator_imports ii
LEFT JOIN legado_documentos.users lu ON lu.id = ii.imported_by
LEFT JOIN users u ON u.email = lu.email;

-- ────────────────────────────────────────────────────────────────────────────
-- 16. (OPCIONAL) NOTIFICAÇÕES antigas → tabela global `notifications`
--     is_read/read_at → read_at · module = 'documentos' · hospital_id sai.
--     Normalmente NÃO vale a pena migrar histórico de notificações —
--     descomente apenas se necessário.
-- ────────────────────────────────────────────────────────────────────────────
-- INSERT INTO notifications (user_id, module, type, title, message, link, read_at, created_at)
-- SELECT u.id, 'documentos', n.type, n.title, n.message, NULL,
--        CASE WHEN n.is_read = 1 THEN COALESCE(n.read_at, n.created_at) ELSE NULL END,
--        n.created_at
-- FROM legado_documentos.notifications n
-- JOIN legado_documentos.users lu ON lu.id = n.user_id
-- JOIN users u ON u.email = lu.email
-- WHERE n.deleted_at IS NULL;

-- ────────────────────────────────────────────────────────────────────────────
-- 17. (OPCIONAL) SETTINGS funcionais → doc_system_settings
--     As chaves do legado eram todas VISUAIS (cores/fonte/css) e NÃO devem
--     ser migradas (tema agora é do núcleo). Deixe comentado, ou filtre as
--     chaves funcionais que porventura existam:
-- ────────────────────────────────────────────────────────────────────────────
-- INSERT INTO doc_system_settings (setting_key, setting_value, updated_at)
-- SELECT setting_key, setting_value, updated_at
-- FROM legado_documentos.system_settings
-- WHERE setting_key NOT IN ('app_name','primary_color','sidebar_bg','sidebar_text',
--       'sidebar_hover_bg','sidebar_active_text','navbar_bg','body_bg','body_font',
--       'body_font_size','card_shadow','card_border_radius','login_gradient_start',
--       'login_gradient_end','logo_icon','footer_text','custom_css')
-- ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);

-- FIM. Após migrar: mova os arquivos de uploads (ver bloco 7) e valide os
-- totais, ex.: SELECT COUNT(*) FROM doc_documents; vs banco legado.
