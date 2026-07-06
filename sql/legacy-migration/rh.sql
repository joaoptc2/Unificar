-- ============================================================
-- MIGRAÇÃO DE DADOS: RH Hospital (legado) → Plataforma Unificada
--
-- Pré-requisitos:
--   1. O banco antigo deve estar acessível como `legado_rh` no mesmo
--      servidor MySQL (ex.: RENAME/import do dump legado).
--   2. O schema do núcleo (sql/schema.sql) e do módulo
--      (sql/modules/rh.sql) já devem ter sido aplicados no banco novo.
--   3. Executar este script CONECTADO AO BANCO NOVO.
--
-- Ordem: usuários → papéis (RBAC) → vínculos (rh_user_profile) →
--        tabelas de domínio (respeitando dependências de FK).
--
-- Observações:
--   • users.password (bcrypt) → users.password_hash (compatível).
--   • username = e-mail (a tabela global exige username único).
--   • users.role → user_module_access (module_slug='rh'); os papéis
--     legados (admin/rh/gestor/visualizador/funcionario) são os mesmos
--     do manifesto do módulo.
--   • users.employee_id / users.department_id → rh_user_profile.
--   • login_attempts, password_resets e user_2fa NÃO são migrados
--     (agora são do núcleo; 2FA deve ser reconfigurado pelo usuário).
--   • audit_log legado NÃO é migrado (estrutura diferente); mantenha o
--     dump legado para consulta histórica se necessário.
--
-- Revise e descomente bloco a bloco.
-- ============================================================

-- SET NAMES utf8mb4;
-- SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------------
-- 1) Usuários → tabela GLOBAL users (match por e-mail)
--    Cria apenas os que ainda não existem na plataforma.
-- ------------------------------------------------------------
-- INSERT INTO users (name, username, email, password_hash, is_admin, active, created_at)
-- SELECT lu.name,
--        lu.email,               -- username = e-mail
--        lu.email,
--        lu.password,            -- bcrypt legado é aceito por password_verify()
--        0,                      -- admin global NÃO é herdado do módulo
--        lu.active,
--        lu.created_at
-- FROM legado_rh.users lu
-- WHERE NOT EXISTS (SELECT 1 FROM users u WHERE u.email = lu.email);

-- (Opcional) Atualiza o último login conhecido:
-- UPDATE users u
-- JOIN legado_rh.users lu ON lu.email = u.email
-- SET u.last_login_at = lu.last_login
-- WHERE lu.last_login IS NOT NULL AND u.last_login_at IS NULL;

-- ------------------------------------------------------------
-- 2) Papéis legados → RBAC central (user_module_access, module 'rh')
--    admin/rh/gestor/visualizador/funcionario mantêm o mesmo nome.
-- ------------------------------------------------------------
-- INSERT INTO user_module_access (user_id, module_slug, role, granted_by, granted_at)
-- SELECT u.id, 'rh', lu.role, NULL, NOW()
-- FROM legado_rh.users lu
-- JOIN users u ON u.email = lu.email
-- ON DUPLICATE KEY UPDATE role = VALUES(role);

-- ------------------------------------------------------------
-- 3) Tabelas de domínio (mesmos IDs — preserva as FKs internas)
-- ------------------------------------------------------------

-- 3.1 Departamentos e cargos
-- INSERT INTO rh_departments SELECT * FROM legado_rh.departments;
-- INSERT INTO rh_job_positions SELECT * FROM legado_rh.job_positions;

-- 3.2 Funcionários (created_by remapeado para o usuário global por e-mail)
-- INSERT INTO rh_employees
--     (id, full_name, cpf, birth_date, gender, phone, email,
--      address_street, address_number, address_complement, address_neighborhood,
--      address_city, address_state, address_zip, job_position_id, department_id,
--      admission_date, contract_type, status, termination_date, leave_date, return_date,
--      regional_council, council_number, council_expiry,
--      aso_admissional_date, aso_next_date, photo, notes, anonymized_at,
--      created_by, created_at, updated_at)
-- SELECT e.id, e.full_name, e.cpf, e.birth_date, e.gender, e.phone, e.email,
--        e.address_street, e.address_number, e.address_complement, e.address_neighborhood,
--        e.address_city, e.address_state, e.address_zip, e.job_position_id, e.department_id,
--        e.admission_date, e.contract_type, e.status, e.termination_date, e.leave_date, e.return_date,
--        e.regional_council, e.council_number, e.council_expiry,
--        e.aso_admissional_date, e.aso_next_date, e.photo, e.notes, e.anonymized_at,
--        (SELECT u.id FROM users u JOIN legado_rh.users lu ON lu.email = u.email WHERE lu.id = e.created_by),
--        e.created_at, e.updated_at
-- FROM legado_rh.employees e;

-- ------------------------------------------------------------
-- 4) Vínculos usuário ↔ funcionário/departamento → rh_user_profile
--    (substitui users.employee_id / users.department_id do legado)
-- ------------------------------------------------------------
-- INSERT INTO rh_user_profile (user_id, employee_id, department_id)
-- SELECT u.id, lu.employee_id, lu.department_id
-- FROM legado_rh.users lu
-- JOIN users u ON u.email = lu.email
-- WHERE lu.employee_id IS NOT NULL OR lu.department_id IS NOT NULL
-- ON DUPLICATE KEY UPDATE employee_id = VALUES(employee_id),
--                         department_id = VALUES(department_id);

-- ------------------------------------------------------------
-- 5) Demais tabelas do domínio → rh_*
--    Colunas *_by / user_id que apontavam para o users legado são
--    remapeadas pelo e-mail via a função de subquery abaixo.
-- ------------------------------------------------------------
-- Dica: para encurtar, crie uma VIEW de mapeamento de IDs:
-- CREATE OR REPLACE VIEW legado_rh_user_map AS
-- SELECT lu.id AS legacy_id, u.id AS new_id
-- FROM legado_rh.users lu JOIN users u ON u.email = lu.email;

-- INSERT INTO rh_employee_documents
--     (id, employee_id, doc_type, title, file_path, file_original_name, file_size, notes, uploaded_by, created_at)
-- SELECT d.id, d.employee_id, d.doc_type, d.title, d.file_path, d.file_original_name, d.file_size, d.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = d.uploaded_by), d.created_at
-- FROM legado_rh.employee_documents d;

-- INSERT INTO rh_employee_records
--     (id, employee_id, record_type, description, old_value, new_value, record_date, created_by, created_at)
-- SELECT r.id, r.employee_id, r.record_type, r.description, r.old_value, r.new_value, r.record_date,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = r.created_by), r.created_at
-- FROM legado_rh.employee_records r;

-- INSERT INTO rh_medical_certificates
--     (id, employee_id, issue_date, days, cid, doctor_name, doctor_crm, notes, file_path, created_by, created_at)
-- SELECT c.id, c.employee_id, c.issue_date, c.days, c.cid, c.doctor_name, c.doctor_crm, c.notes, c.file_path,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = c.created_by), c.created_at
-- FROM legado_rh.medical_certificates c;

-- INSERT INTO rh_expirations
--     (id, employee_id, type, title, description, issue_date, expiry_date, status, alert_days,
--      file_path, renewed, notified_at, notified_expired_at, created_by, created_at, updated_at)
-- SELECT x.id, x.employee_id, x.type, x.title, x.description, x.issue_date, x.expiry_date, x.status, x.alert_days,
--        x.file_path, x.renewed, x.notified_at, x.notified_expired_at,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = x.created_by), x.created_at, x.updated_at
-- FROM legado_rh.expirations x;

-- INSERT INTO rh_expiration_history
--     (id, expiration_id, old_expiry_date, new_expiry_date, notes, renewed_by, created_at)
-- SELECT h.id, h.expiration_id, h.old_expiry_date, h.new_expiry_date, h.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = h.renewed_by), h.created_at
-- FROM legado_rh.expiration_history h;

-- INSERT INTO rh_schedules
--     (id, employee_id, title, description, event_date, event_time, end_time, event_type,
--      expiration_id, color, created_by, created_at, updated_at)
-- SELECT s.id, s.employee_id, s.title, s.description, s.event_date, s.event_time, s.end_time, s.event_type,
--        s.expiration_id, s.color,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = s.created_by), s.created_at, s.updated_at
-- FROM legado_rh.schedules s;

-- INSERT INTO rh_recruitment_jobs
--     (id, title, description, requirements, department_id, status, created_by, created_at, updated_at)
-- SELECT j.id, j.title, j.description, j.requirements, j.department_id, j.status,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = j.created_by), j.created_at, j.updated_at
-- FROM legado_rh.recruitment_jobs j;

-- INSERT INTO rh_recruitment_steps SELECT * FROM legado_rh.recruitment_steps;

-- INSERT INTO rh_candidates SELECT * FROM legado_rh.candidates;

-- INSERT INTO rh_candidate_progress
--     (id, candidate_id, step_id, status, notes, evaluated_by, evaluated_at, created_at)
-- SELECT p.id, p.candidate_id, p.step_id, p.status, p.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = p.evaluated_by), p.evaluated_at, p.created_at
-- FROM legado_rh.candidate_progress p;

-- INSERT INTO rh_employee_scores
--     (id, employee_id, points, reason, category, created_by, created_at)
-- SELECT s.id, s.employee_id, s.points, s.reason, s.category,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = s.created_by), s.created_at
-- FROM legado_rh.employee_scores s;

-- INSERT INTO rh_employee_compliments
--     (id, employee_id, message, compliment_from, created_by, created_at)
-- SELECT c.id, c.employee_id, c.message, c.compliment_from,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = c.created_by), c.created_at
-- FROM legado_rh.employee_compliments c;

-- INSERT INTO rh_public_submissions SELECT * FROM legado_rh.public_submissions;

-- INSERT INTO rh_vacations
--     (id, employee_id, period_start, period_end, start_date, end_date, days, sold_days, installment,
--      status, approved_by, approved_at, notes, created_by, created_at, updated_at)
-- SELECT v.id, v.employee_id, v.period_start, v.period_end, v.start_date, v.end_date, v.days, v.sold_days, v.installment,
--        v.status,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = v.approved_by), v.approved_at, v.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = v.created_by), v.created_at, v.updated_at
-- FROM legado_rh.vacations v;

-- INSERT INTO rh_shift_templates SELECT * FROM legado_rh.shift_templates;
-- (Atenção: rh_shift_templates é semeada pelo sql/modules/rh.sql — se as
--  seeds já existirem, importe o legado com offset de IDs ou limpe antes.)

-- INSERT INTO rh_shifts
--     (id, employee_id, department_id, template_id, shift_date, start_time, end_time, type, status,
--      swap_with_id, notes, created_by, created_at, updated_at)
-- SELECT s.id, s.employee_id, s.department_id, s.template_id, s.shift_date, s.start_time, s.end_time, s.type, s.status,
--        s.swap_with_id, s.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = s.created_by), s.created_at, s.updated_at
-- FROM legado_rh.shifts s;

-- INSERT INTO rh_onboarding_templates SELECT * FROM legado_rh.onboarding_templates;
-- INSERT INTO rh_onboarding_template_items SELECT * FROM legado_rh.onboarding_template_items;
-- (Mesma observação de seeds de rh_shift_templates.)

-- INSERT INTO rh_onboarding_progress
--     (id, employee_id, template_id, item_id, completed, completed_by, completed_at, notes, created_at)
-- SELECT p.id, p.employee_id, p.template_id, p.item_id, p.completed,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = p.completed_by), p.completed_at, p.notes, p.created_at
-- FROM legado_rh.onboarding_progress p;

-- INSERT INTO rh_training_catalog SELECT * FROM legado_rh.training_catalog;

-- INSERT INTO rh_training_records
--     (id, employee_id, catalog_id, title, completed_at, expires_at, hours, certificate_path, notes, created_by, created_at)
-- SELECT t.id, t.employee_id, t.catalog_id, t.title, t.completed_at, t.expires_at, t.hours, t.certificate_path, t.notes,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = t.created_by), t.created_at
-- FROM legado_rh.training_records t;

-- INSERT INTO rh_announcements
--     (id, title, body, type, department_id, published_at, expires_at, pinned, created_by, created_at, updated_at)
-- SELECT a.id, a.title, a.body, a.type, a.department_id, a.published_at, a.expires_at, a.pinned,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = a.created_by), a.created_at, a.updated_at
-- FROM legado_rh.announcements a;

-- INSERT INTO rh_announcement_reads (announcement_id, user_id, read_at)
-- SELECT r.announcement_id,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = r.user_id),
--        r.read_at
-- FROM legado_rh.announcement_reads r
-- WHERE (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = r.user_id) IS NOT NULL;

-- INSERT INTO rh_surveys
--     (id, title, description, type, anonymous, status, starts_at, ends_at, created_by, created_at)
-- SELECT s.id, s.title, s.description, s.type, s.anonymous, s.status, s.starts_at, s.ends_at,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = s.created_by), s.created_at
-- FROM legado_rh.surveys s;

-- INSERT INTO rh_survey_questions SELECT * FROM legado_rh.survey_questions;

-- INSERT INTO rh_survey_responses
--     (id, survey_id, question_id, user_id, rating, answer, created_at)
-- SELECT r.id, r.survey_id, r.question_id,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = r.user_id),
--        r.rating, r.answer, r.created_at
-- FROM legado_rh.survey_responses r;

-- INSERT INTO rh_salary_history
--     (id, employee_id, salary, reason, effective_date, created_by, created_at)
-- SELECT s.id, s.employee_id, s.salary, s.reason, s.effective_date,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = s.created_by), s.created_at
-- FROM legado_rh.salary_history s;

-- INSERT INTO rh_requests
--     (id, employee_id, type, subject, body, status, response, responded_by, responded_at, created_at, updated_at)
-- SELECT r.id, r.employee_id, r.type, r.subject, r.body, r.status, r.response,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = r.responded_by), r.responded_at, r.created_at, r.updated_at
-- FROM legado_rh.requests r;

-- INSERT INTO rh_digital_signatures SELECT * FROM legado_rh.digital_signatures;
-- INSERT INTO rh_employee_dependents SELECT * FROM legado_rh.employee_dependents;

-- INSERT INTO rh_warnings
--     (id, employee_id, type, reason, incident_date, witnesses, file_path, signature_id, created_by, created_at)
-- SELECT w.id, w.employee_id, w.type, w.reason, w.incident_date, w.witnesses, w.file_path, w.signature_id,
--        (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = w.created_by), w.created_at
-- FROM legado_rh.warnings w;

-- ------------------------------------------------------------
-- 6) (Opcional) Notificações legadas não lidas → tabela GLOBAL
--    (is_read/read_at → read_at; links reescritos com m=rh)
-- ------------------------------------------------------------
-- INSERT INTO notifications (user_id, module, type, title, message, link, read_at, created_at)
-- SELECT (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = n.user_id),
--        'rh', n.type, n.title, n.message,
--        REPLACE(n.link, 'index.php?page=', 'index.php?m=rh&page='),
--        CASE WHEN n.is_read = 1 THEN COALESCE(n.read_at, n.created_at) ELSE NULL END,
--        n.created_at
-- FROM legado_rh.notifications n
-- WHERE (SELECT new_id FROM legado_rh_user_map WHERE legacy_id = n.user_id) IS NOT NULL;

-- ------------------------------------------------------------
-- 7) Uploads (fora do SQL): copiar os arquivos físicos
--    - legado public/uploads/*   → /uploads/rh/<subdir>/
--    - legado storage/uploads/*  → /uploads/rh/<subdir>/
--    Os paths gravados no banco ("uploads/..." e "storage/uploads/...")
--    continuam válidos — o módulo resolve ambos para /uploads/rh/.
-- ------------------------------------------------------------

-- DROP VIEW IF EXISTS legado_rh_user_map;
-- SET FOREIGN_KEY_CHECKS = 1;
