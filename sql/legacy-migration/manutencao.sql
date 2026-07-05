-- ============================================================
-- MIGRAÇÃO DE DADOS: ManuHosp legado → Plataforma Unificada
-- Módulo: manutencao (tabelas man_*)
--
-- PRÉ-REQUISITOS:
--   1. O banco legado deve estar acessível como `legado_manutencao`
--      (mesmo servidor MySQL; ajuste o nome se necessário).
--   2. sql/schema.sql (núcleo) e sql/modules/manutencao.sql já
--      executados no banco novo.
--   3. Execute este script CONECTADO AO BANCO NOVO.
--
-- ESTRATÉGIA:
--   - Usuários legados são casados com a tabela GLOBAL `users` por
--     E-MAIL; os que não existem são inseridos (password_hash copiado
--     do campo `password` legado — ambos bcrypt).
--   - O papel legado (users.role ENUM admin/manager/maintenance/
--     cleaning/viewer) vira uma linha em `user_module_access` com
--     module_slug='manutencao' e o MESMO nome de papel.
--   - IDs das tabelas de domínio são PRESERVADOS (mesmo id no man_*).
--   - Colunas *user_id*/assigned_to/created_by/... são REMAPEADAS do
--     id de usuário legado para o id na tabela global via _man_user_map.
--
-- Rode dentro de uma transação onde possível e faça backup antes.
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 0. MAPA DE USUÁRIOS (id legado → id global)
-- ------------------------------------------------------------

-- 0.1 Insere na tabela global os usuários legados que ainda não existem
--     (match por e-mail). username derivado do e-mail; password_hash é o
--     hash bcrypt legado; active a partir do status legado.
INSERT INTO users (name, username, email, password_hash, is_admin, active, auth_source, phone, created_at)
SELECT
    ou.name,
    -- username único derivado do e-mail (parte local + id legado em colisão)
    IF (
        EXISTS (SELECT 1 FROM users u2 WHERE u2.username = SUBSTRING_INDEX(ou.email, '@', 1)),
        CONCAT(SUBSTRING_INDEX(ou.email, '@', 1), '_man', ou.id),
        SUBSTRING_INDEX(ou.email, '@', 1)
    ),
    ou.email,
    ou.password,                      -- hash bcrypt legado → password_hash
    0,                                -- is_admin global NÃO é herdado do módulo
    IF(ou.status = 'active', 1, 0),
    'local',
    ou.phone,
    ou.created_at
FROM legado_manutencao.users ou
WHERE NOT EXISTS (SELECT 1 FROM users nu WHERE nu.email = ou.email);

-- 0.2 Tabela temporária de mapeamento old_id → new_id
DROP TEMPORARY TABLE IF EXISTS _man_user_map;
CREATE TEMPORARY TABLE _man_user_map (
    old_id INT UNSIGNED NOT NULL PRIMARY KEY,
    new_id INT UNSIGNED NOT NULL
) ENGINE=Memory;

INSERT INTO _man_user_map (old_id, new_id)
SELECT ou.id, nu.id
FROM legado_manutencao.users ou
JOIN users nu ON nu.email = ou.email;

-- 0.3 Papéis legados → RBAC do núcleo (user_module_access)
--     O vocabulário do módulo é o MESMO ENUM legado.
INSERT INTO user_module_access (user_id, module_slug, role, granted_by)
SELECT m.new_id, 'manutencao', ou.role, NULL
FROM legado_manutencao.users ou
JOIN _man_user_map m ON m.old_id = ou.id
ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ------------------------------------------------------------
-- 1. HOSPITAIS / SETORES / CATEGORIAS
-- ------------------------------------------------------------

INSERT INTO man_hospitals (id, name, cnpj, address, city, state, phone, email, contact_person, status, created_at, updated_at)
SELECT id, name, cnpj, address, city, state, phone, email, contact_person, status, created_at, updated_at
FROM legado_manutencao.hospitals
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO man_sectors (id, hospital_id, name, description, status, created_at)
SELECT id, hospital_id, name, description, status, created_at
FROM legado_manutencao.sectors
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO man_equipment_categories (id, hospital_id, name, description, created_at)
SELECT id, hospital_id, name, description, created_at
FROM legado_manutencao.equipment_categories
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ------------------------------------------------------------
-- 2. EQUIPAMENTOS
-- ------------------------------------------------------------

INSERT INTO man_equipment (id, hospital_id, sector_id, category_id, code, name, manufacturer, model,
                           serial_number, acquisition_date, installation_date, useful_life_years,
                           deactivation_date, deactivation_reason, criticality, status, description,
                           qr_token, created_at, updated_at)
SELECT id, hospital_id, sector_id, category_id, code, name, manufacturer, model,
       serial_number, acquisition_date, installation_date, useful_life_years,
       deactivation_date, deactivation_reason, criticality, status, description,
       qr_token, created_at, updated_at
FROM legado_manutencao.equipment
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ------------------------------------------------------------
-- 3. ORDENS DE SERVIÇO (assigned_to/created_by remapeados)
-- ------------------------------------------------------------

INSERT INTO man_service_orders (id, hospital_id, equipment_id, os_number, type, priority, status, title,
                                description, solution, observation, photo_path, signature_data,
                                assigned_to, created_by, scheduled_date, started_at, completed_at,
                                downtime_start, downtime_end, estimated_hours, actual_hours,
                                labor_hours, labor_cost_per_hour, cost, checklist_template_id,
                                checklist_checked, anonymous_token, tracking_token, created_at, updated_at)
SELECT so.id, so.hospital_id, so.equipment_id, so.os_number, so.type, so.priority, so.status, so.title,
       so.description, so.solution, so.observation, so.photo_path, so.signature_data,
       ma.new_id, mc.new_id, so.scheduled_date, so.started_at, so.completed_at,
       so.downtime_start, so.downtime_end, so.estimated_hours, so.actual_hours,
       so.labor_hours, so.labor_cost_per_hour, so.cost, so.checklist_template_id,
       so.checklist_checked, so.anonymous_token, so.tracking_token, so.created_at, so.updated_at
FROM legado_manutencao.service_orders so
LEFT JOIN _man_user_map ma ON ma.old_id = so.assigned_to
LEFT JOIN _man_user_map mc ON mc.old_id = so.created_by
ON DUPLICATE KEY UPDATE title = VALUES(title);

-- ------------------------------------------------------------
-- 4. PEÇAS E ESTOQUE
-- ------------------------------------------------------------

INSERT INTO man_parts (id, hospital_id, code, name, description, unit, quantity, min_quantity,
                       unit_cost, supplier, location, status, created_at, updated_at)
SELECT id, hospital_id, code, name, description, unit, quantity, min_quantity,
       unit_cost, supplier, location, status, created_at, updated_at
FROM legado_manutencao.parts
ON DUPLICATE KEY UPDATE name = VALUES(name);

INSERT INTO man_stock_movements (id, hospital_id, part_id, type, quantity, reason, created_by, created_at)
SELECT sm.id, sm.hospital_id, sm.part_id, sm.type, sm.quantity, sm.reason, m.new_id, sm.created_at
FROM legado_manutencao.stock_movements sm
LEFT JOIN _man_user_map m ON m.old_id = sm.created_by
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

-- ------------------------------------------------------------
-- 5. TÉCNICOS (user_id remapeado para users global)
-- ------------------------------------------------------------

INSERT INTO man_technicians (id, hospital_id, user_id, name, specialty, phone, email, crea, status, created_at)
SELECT t.id, t.hospital_id, m.new_id, t.name, t.specialty, t.phone, t.email, t.crea, t.status, t.created_at
FROM legado_manutencao.technicians t
LEFT JOIN _man_user_map m ON m.old_id = t.user_id
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ------------------------------------------------------------
-- 6. PLANOS DE MANUTENÇÃO / CALIBRAÇÕES
-- ------------------------------------------------------------

INSERT INTO man_maintenance_plans (id, hospital_id, equipment_id, title, description, frequency,
                                   next_date, last_executed, status, created_at)
SELECT id, hospital_id, equipment_id, title, description, frequency,
       next_date, last_executed, status, created_at
FROM legado_manutencao.maintenance_plans
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO man_equipment_calibrations (id, hospital_id, equipment_id, calibration_date, next_date,
                                        responsible_body, responsible_person, result, certificate_path,
                                        observations, cost, created_by, created_at, updated_at)
SELECT ec.id, ec.hospital_id, ec.equipment_id, ec.calibration_date, ec.next_date,
       ec.responsible_body, ec.responsible_person, ec.result, ec.certificate_path,
       ec.observations, ec.cost, m.new_id, ec.created_at, ec.updated_at
FROM legado_manutencao.equipment_calibrations ec
LEFT JOIN _man_user_map m ON m.old_id = ec.created_by
ON DUPLICATE KEY UPDATE next_date = VALUES(next_date);

-- ------------------------------------------------------------
-- 7. LIMPEZA
-- ------------------------------------------------------------

INSERT INTO man_cleaning_schedules (id, hospital_id, sector_id, title, type, frequency,
                                    checklist_items, instructions, status, created_at)
SELECT id, hospital_id, sector_id, title, type, frequency,
       checklist_items, instructions, status, created_at
FROM legado_manutencao.cleaning_schedules
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO man_cleaning_executions (id, hospital_id, schedule_id, sector_id, type, executed_by_name,
                                     executed_by_user, executed_at, checked_items, compliance_pct,
                                     photo_path, signature_data, observation, created_at)
SELECT ce.id, ce.hospital_id, ce.schedule_id, ce.sector_id, ce.type, ce.executed_by_name,
       m.new_id, ce.executed_at, ce.checked_items, ce.compliance_pct,
       ce.photo_path, ce.signature_data, ce.observation, ce.created_at
FROM legado_manutencao.cleaning_executions ce
LEFT JOIN _man_user_map m ON m.old_id = ce.executed_by_user
ON DUPLICATE KEY UPDATE executed_at = VALUES(executed_at);

-- ------------------------------------------------------------
-- 8. HISTÓRICO DE OS / QR LOCATIONS
-- ------------------------------------------------------------

INSERT INTO man_os_history (id, os_id, user_id, user_name, action, details, created_at)
SELECT h.id, h.os_id, m.new_id, h.user_name, h.action, h.details, h.created_at
FROM legado_manutencao.os_history h
LEFT JOIN _man_user_map m ON m.old_id = h.user_id
ON DUPLICATE KEY UPDATE action = VALUES(action);

INSERT INTO man_qr_locations (id, hospital_id, sector_id, name, description, token, status, created_at)
SELECT id, hospital_id, sector_id, name, description, token, status, created_at
FROM legado_manutencao.qr_locations
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ------------------------------------------------------------
-- 9. PEÇAS/CHECKLISTS/ANEXOS DE OS
-- ------------------------------------------------------------

INSERT INTO man_os_parts (id, os_id, part_id, quantity, unit_cost, created_at)
SELECT id, os_id, part_id, quantity, unit_cost, created_at
FROM legado_manutencao.os_parts
ON DUPLICATE KEY UPDATE quantity = VALUES(quantity);

INSERT INTO man_os_checklists (id, hospital_id, os_type, title, items, status, created_at)
SELECT id, hospital_id, os_type, title, items, status, created_at
FROM legado_manutencao.os_checklists
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO man_os_attachments (id, os_id, file_path, file_name, type, uploaded_by, created_at)
SELECT a.id, a.os_id, a.file_path, a.file_name, a.type, m.new_id, a.created_at
FROM legado_manutencao.os_attachments a
LEFT JOIN _man_user_map m ON m.old_id = a.uploaded_by
ON DUPLICATE KEY UPDATE file_path = VALUES(file_path);

-- ------------------------------------------------------------
-- 10. INSPEÇÕES
-- ------------------------------------------------------------

INSERT INTO man_inspection_routes (id, hospital_id, title, description, locations, status, created_at)
SELECT id, hospital_id, title, description, locations, status, created_at
FROM legado_manutencao.inspection_routes
ON DUPLICATE KEY UPDATE title = VALUES(title);

INSERT INTO man_inspection_executions (id, hospital_id, route_id, executed_by, executed_by_name,
                                       results, photo_path, observation, created_at)
SELECT ie.id, ie.hospital_id, ie.route_id, m.new_id, ie.executed_by_name,
       ie.results, ie.photo_path, ie.observation, ie.created_at
FROM legado_manutencao.inspection_executions ie
LEFT JOIN _man_user_map m ON m.old_id = ie.executed_by
ON DUPLICATE KEY UPDATE results = VALUES(results);

-- ------------------------------------------------------------
-- 11. AUDITORIA (opcional) → tabela global audit_log, module='manutencao'
-- ------------------------------------------------------------

INSERT INTO audit_log (user_id, module, action, entity, entity_id, details, ip_address, created_at)
SELECT m.new_id, 'manutencao', al.action, al.entity, CAST(al.entity_id AS CHAR), al.details, al.ip_address, al.created_at
FROM legado_manutencao.audit_log al
LEFT JOIN _man_user_map m ON m.old_id = al.user_id;

-- ------------------------------------------------------------
-- 12. NOTIFICAÇÕES (opcional — DESATIVADO por padrão)
--
-- As notificações legadas eram por hospital (user_id NULL = broadcast);
-- a tabela global do núcleo é por usuário. Migrar históricos gera pouco
-- valor (conteúdo transiente) — se desejar, descomente o bloco abaixo
-- para entregar as NÃO LIDAS aos usuários que tinham papel de gestão.
-- ------------------------------------------------------------
-- INSERT INTO notifications (user_id, module, type, title, message, link, read_at, created_at)
-- SELECT m.new_id, 'manutencao', n.type, n.title, n.message, NULL, NULL, n.created_at
-- FROM legado_manutencao.notifications n
-- JOIN legado_manutencao.users ou ON ou.hospital_id = n.hospital_id AND ou.role IN ('admin','manager')
-- JOIN _man_user_map m ON m.old_id = ou.id
-- WHERE n.is_read = 0 AND n.user_id IS NULL;
-- -- Notificações que já eram dirigidas a um usuário específico:
-- INSERT INTO notifications (user_id, module, type, title, message, link, read_at, created_at)
-- SELECT m.new_id, 'manutencao', n.type, n.title, n.message, NULL,
--        IF(n.is_read = 1, n.created_at, NULL), n.created_at
-- FROM legado_manutencao.notifications n
-- JOIN _man_user_map m ON m.old_id = n.user_id;

-- ------------------------------------------------------------
-- FINALIZAÇÃO
-- ------------------------------------------------------------

DROP TEMPORARY TABLE IF EXISTS _man_user_map;

SET FOREIGN_KEY_CHECKS = 1;

-- Lembretes pós-migração:
--   * Copie o conteúdo de uploads/ do sistema legado para
--     /uploads/manutencao/ (subpastas: calibrations, cleaning,
--     inspections, os_photos) — os caminhos gravados no banco
--     são relativos e continuam válidos.
--   * As tabelas legadas users/notifications/audit_log/login_attempts
--     NÃO são portadas como man_* — núcleo assume essas funções.
