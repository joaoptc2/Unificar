-- ============================================================
-- Migração 008 — Novo módulo Planejamento (plan_)
-- Conteúdo idêntico a sql/modules/planejamento.sql (idempotente).
-- ============================================================

SET NAMES utf8mb4;

-- ---- Modelos predefinidos --------------------------------------------------
-- data = JSON:
--   plan    → {"kind":"work_plan","items":[{"kind":"objective","title":"..","children":[...]}]}
--   board   → {"columns":[{"name":"..","color":"#hex","wip_limit":0,"is_done":0}],"labels":[".."],"points":true}
--   diagram → {"v":1,"canvas":{"w":1600,"h":1000,"grid":20},"nodes":[...],"edges":[...]}
CREATE TABLE IF NOT EXISTS plan_templates (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    kind        ENUM('plan','board','diagram') NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    icon        VARCHAR(60) NULL COMMENT 'Bootstrap Icon',
    data        MEDIUMTEXT NOT NULL COMMENT 'JSON do modelo',
    is_builtin  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = fornecido pelo sistema (editável, não excluível)',
    active      TINYINT(1) NOT NULL DEFAULT 1,
    sort_order  INT NOT NULL DEFAULT 0,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_tpl_kind (kind, active, sort_order),
    CONSTRAINT fk_plan_tpl_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Planos de trabalho / planejamento organizacional ----------------------
CREATE TABLE IF NOT EXISTS plan_plans (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title       VARCHAR(200) NOT NULL,
    description TEXT NULL,
    kind        ENUM('work_plan','strategic','operational','project','pdca','other') NOT NULL DEFAULT 'work_plan',
    status      ENUM('draft','active','completed','archived') NOT NULL DEFAULT 'draft',
    sector      VARCHAR(150) NULL COMMENT 'Setor / área responsável (texto livre)',
    owner_id    INT UNSIGNED NULL COMMENT 'Responsável geral',
    start_date  DATE NULL,
    end_date    DATE NULL,
    progress    TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '% calculado a partir dos itens',
    template_id INT UNSIGNED NULL,
    created_by  INT UNSIGNED NULL,
    updated_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at  DATETIME NULL,
    KEY idx_plan_plans_status (status, deleted_at),
    KEY idx_plan_plans_owner (owner_id),
    CONSTRAINT fk_plan_plans_owner    FOREIGN KEY (owner_id)    REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT fk_plan_plans_template FOREIGN KEY (template_id) REFERENCES plan_templates (id) ON DELETE SET NULL,
    CONSTRAINT fk_plan_plans_creator  FOREIGN KEY (created_by)  REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT fk_plan_plans_updater  FOREIGN KEY (updated_by)  REFERENCES users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Itens hierárquicos do plano (objetivo → meta → ação → tarefa), com os
-- campos do 5W2H: what=title, why=description, where=where_text,
-- when=start_date/due_date, who=responsible, how=how_text, how much=cost.
CREATE TABLE IF NOT EXISTS plan_plan_items (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plan_id          INT UNSIGNED NOT NULL,
    parent_id        INT UNSIGNED NULL,
    kind             ENUM('objective','goal','action','task') NOT NULL DEFAULT 'action',
    title            VARCHAR(300) NOT NULL,
    description      TEXT NULL,
    where_text       VARCHAR(200) NULL,
    how_text         TEXT NULL,
    cost             DECIMAL(12,2) NULL,
    responsible_id   INT UNSIGNED NULL,
    responsible_name VARCHAR(150) NULL COMMENT 'Quando o responsável não é usuário do sistema',
    start_date       DATE NULL,
    due_date         DATE NULL,
    status           ENUM('pending','in_progress','done','cancelled') NOT NULL DEFAULT 'pending',
    priority         ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
    progress         TINYINT UNSIGNED NOT NULL DEFAULT 0,
    indicator        VARCHAR(255) NULL COMMENT 'Indicador / evidência de conclusão',
    sort_order       INT NOT NULL DEFAULT 0,
    completed_at     DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_items_plan (plan_id, parent_id, sort_order),
    KEY idx_plan_items_due (due_date, status),
    KEY idx_plan_items_resp (responsible_id),
    CONSTRAINT fk_plan_items_plan   FOREIGN KEY (plan_id)        REFERENCES plan_plans (id)      ON DELETE CASCADE,
    CONSTRAINT fk_plan_items_parent FOREIGN KEY (parent_id)      REFERENCES plan_plan_items (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_items_resp   FOREIGN KEY (responsible_id) REFERENCES users (id)           ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Quadros (Kanban / Scrum) ----------------------------------------------
CREATE TABLE IF NOT EXISTS plan_boards (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    kind        ENUM('kanban','scrum','custom') NOT NULL DEFAULT 'kanban',
    is_private  TINYINT(1) NOT NULL DEFAULT 0 COMMENT '1 = só membros (plan_board_members) veem',
    use_points  TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Story points nos cartões (Scrum)',
    sprint_days SMALLINT UNSIGNED NULL COMMENT 'Duração da sprint (Scrum)',
    sprint_start DATE NULL,
    labels      VARCHAR(500) NULL COMMENT 'Etiquetas disponíveis (separadas por vírgula)',
    plan_id     INT UNSIGNED NULL COMMENT 'Plano de trabalho vinculado',
    template_id INT UNSIGNED NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    archived_at DATETIME NULL,
    KEY idx_plan_boards_archived (archived_at),
    CONSTRAINT fk_plan_boards_plan     FOREIGN KEY (plan_id)     REFERENCES plan_plans (id)     ON DELETE SET NULL,
    CONSTRAINT fk_plan_boards_template FOREIGN KEY (template_id) REFERENCES plan_templates (id) ON DELETE SET NULL,
    CONSTRAINT fk_plan_boards_creator  FOREIGN KEY (created_by)  REFERENCES users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_board_members (
    board_id INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    role     ENUM('owner','member') NOT NULL DEFAULT 'member',
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (board_id, user_id),
    KEY idx_plan_bm_user (user_id),
    CONSTRAINT fk_plan_bm_board FOREIGN KEY (board_id) REFERENCES plan_boards (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_bm_user  FOREIGN KEY (user_id)  REFERENCES users (id)       ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_board_columns (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id   INT UNSIGNED NOT NULL,
    name       VARCHAR(100) NOT NULL,
    color      VARCHAR(7) NULL,
    wip_limit  SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '0 = sem limite',
    is_done    TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Cartões nesta coluna contam como concluídos',
    sort_order INT NOT NULL DEFAULT 0,
    KEY idx_plan_cols_board (board_id, sort_order),
    CONSTRAINT fk_plan_cols_board FOREIGN KEY (board_id) REFERENCES plan_boards (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_board_cards (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    board_id     INT UNSIGNED NOT NULL,
    column_id    INT UNSIGNED NOT NULL,
    title        VARCHAR(300) NOT NULL,
    description  TEXT NULL,
    assignee_id  INT UNSIGNED NULL,
    priority     ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    points       SMALLINT UNSIGNED NULL,
    labels       VARCHAR(255) NULL COMMENT 'Etiquetas (separadas por vírgula)',
    color        VARCHAR(7) NULL,
    due_date     DATE NULL,
    checklist    TEXT NULL COMMENT 'JSON [{"text":"..","done":0}]',
    plan_item_id INT UNSIGNED NULL COMMENT 'Ação do plano de trabalho vinculada',
    sort_order   INT NOT NULL DEFAULT 0,
    created_by   INT UNSIGNED NULL,
    completed_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_plan_cards_col (column_id, sort_order),
    KEY idx_plan_cards_board (board_id),
    KEY idx_plan_cards_assignee (assignee_id),
    KEY idx_plan_cards_due (due_date),
    CONSTRAINT fk_plan_cards_board    FOREIGN KEY (board_id)     REFERENCES plan_boards (id)        ON DELETE CASCADE,
    CONSTRAINT fk_plan_cards_col      FOREIGN KEY (column_id)    REFERENCES plan_board_columns (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_cards_assignee FOREIGN KEY (assignee_id)  REFERENCES users (id)              ON DELETE SET NULL,
    CONSTRAINT fk_plan_cards_item     FOREIGN KEY (plan_item_id) REFERENCES plan_plan_items (id)    ON DELETE SET NULL,
    CONSTRAINT fk_plan_cards_creator  FOREIGN KEY (created_by)   REFERENCES users (id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_card_comments (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    card_id    INT UNSIGNED NOT NULL,
    user_id    INT UNSIGNED NULL,
    body       TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_plan_cc_card (card_id, created_at),
    CONSTRAINT fk_plan_cc_card FOREIGN KEY (card_id) REFERENCES plan_board_cards (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_cc_user FOREIGN KEY (user_id) REFERENCES users (id)            ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Diagramas / fluxogramas / mapas de processo ---------------------------
CREATE TABLE IF NOT EXISTS plan_diagrams (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title           VARCHAR(200) NOT NULL,
    description     TEXT NULL,
    kind            ENUM('flowchart','process_map','diagram','org_chart','mind_map','swot','other') NOT NULL DEFAULT 'flowchart',
    data            MEDIUMTEXT NOT NULL COMMENT 'JSON do editor (nodes/edges/canvas)',
    thumbnail_svg   MEDIUMTEXT NULL COMMENT 'SVG gerado na última gravação (pré-visualização)',
    plan_id         INT UNSIGNED NULL,
    template_id     INT UNSIGNED NULL,
    is_public       TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Visível a todos com diagrams.view (0 = só autor/gestores)',
    current_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_by      INT UNSIGNED NULL,
    updated_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at      DATETIME NULL,
    KEY idx_plan_diag_kind (kind, deleted_at),
    KEY idx_plan_diag_creator (created_by),
    CONSTRAINT fk_plan_diag_plan     FOREIGN KEY (plan_id)     REFERENCES plan_plans (id)     ON DELETE SET NULL,
    CONSTRAINT fk_plan_diag_template FOREIGN KEY (template_id) REFERENCES plan_templates (id) ON DELETE SET NULL,
    CONSTRAINT fk_plan_diag_creator  FOREIGN KEY (created_by)  REFERENCES users (id)          ON DELETE SET NULL,
    CONSTRAINT fk_plan_diag_updater  FOREIGN KEY (updated_by)  REFERENCES users (id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS plan_diagram_versions (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    diagram_id INT UNSIGNED NOT NULL,
    version    INT UNSIGNED NOT NULL,
    title      VARCHAR(200) NOT NULL,
    data       MEDIUMTEXT NOT NULL,
    note       VARCHAR(255) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_plan_diag_version (diagram_id, version),
    CONSTRAINT fk_plan_dv_diagram FOREIGN KEY (diagram_id) REFERENCES plan_diagrams (id) ON DELETE CASCADE,
    CONSTRAINT fk_plan_dv_user    FOREIGN KEY (created_by) REFERENCES users (id)         ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Registro do módulo ----------------------------------------------------
INSERT INTO modules (slug, name, icon, sort_order, active)
VALUES ('planejamento', 'Planejamento', 'bi-kanban', 60, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- ---- Modelos predefinidos (seeds idempotentes por nome) --------------------
INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'board', 'Kanban básico', 'Colunas A fazer / Em andamento / Concluído.', 'bi-kanban',
'{"columns":[{"name":"A fazer","color":"#0d6efd"},{"name":"Em andamento","color":"#fd7e14","wip_limit":3},{"name":"Concluído","color":"#198754","is_done":1}],"labels":["urgente","melhoria","rotina"],"points":false}', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'board' AND name = 'Kanban básico');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'board', 'Scrum', 'Product Backlog, Sprint Backlog, Em andamento, Revisão/Teste e Concluído, com story points e sprints de 2 semanas.', 'bi-arrow-repeat',
'{"kind":"scrum","columns":[{"name":"Product Backlog","color":"#6c757d"},{"name":"Sprint Backlog","color":"#0d6efd"},{"name":"Em andamento","color":"#fd7e14","wip_limit":5},{"name":"Revisão / Teste","color":"#6f42c1"},{"name":"Concluído","color":"#198754","is_done":1}],"labels":["história","bug","melhoria","débito técnico"],"points":true,"sprint_days":14}', 1, 2
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'board' AND name = 'Scrum');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'board', 'Não conformidades (PDCA)', 'Fluxo de tratamento de não conformidades: Registrada, Análise de causa, Ação em execução, Verificação de eficácia, Encerrada.', 'bi-exclamation-triangle',
'{"columns":[{"name":"Registrada","color":"#dc3545"},{"name":"Análise de causa","color":"#fd7e14"},{"name":"Ação em execução","color":"#0d6efd"},{"name":"Verificação de eficácia","color":"#6f42c1"},{"name":"Encerrada","color":"#198754","is_done":1}],"labels":["assistencial","administrativa","infraestrutura","segurança do paciente"],"points":false}', 1, 3
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'board' AND name = 'Não conformidades (PDCA)');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'plan', 'Plano de trabalho (5W2H)', 'Objetivo com metas e ações no formato 5W2H (o quê, por quê, onde, quando, quem, como, quanto custa).', 'bi-clipboard2-check',
'{"kind":"work_plan","items":[{"kind":"objective","title":"Objetivo 1 — descreva o resultado esperado","children":[{"kind":"goal","title":"Meta 1.1 — mensurável, com prazo","children":[{"kind":"action","title":"Ação 1.1.1 — o quê será feito","description":"Por quê: justificativa da ação","how_text":"Como: passos para executar","where_text":"Onde: setor / local"},{"kind":"action","title":"Ação 1.1.2"}]},{"kind":"goal","title":"Meta 1.2","children":[{"kind":"action","title":"Ação 1.2.1"}]}]},{"kind":"objective","title":"Objetivo 2","children":[{"kind":"goal","title":"Meta 2.1","children":[{"kind":"action","title":"Ação 2.1.1"}]}]}]}', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'plan' AND name = 'Plano de trabalho (5W2H)');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'plan', 'Ciclo PDCA', 'Planejar, Executar, Verificar e Agir — um objetivo por etapa do ciclo.', 'bi-arrow-repeat',
'{"kind":"pdca","items":[{"kind":"objective","title":"Planejar (Plan)","children":[{"kind":"goal","title":"Identificar o problema e definir a meta","children":[{"kind":"action","title":"Descrever o problema e coletar dados"},{"kind":"action","title":"Analisar as causas (Ishikawa / 5 porquês)"},{"kind":"action","title":"Elaborar o plano de ação"}]}]},{"kind":"objective","title":"Executar (Do)","children":[{"kind":"goal","title":"Implantar as ações planejadas","children":[{"kind":"action","title":"Treinar a equipe"},{"kind":"action","title":"Executar as ações e registrar evidências"}]}]},{"kind":"objective","title":"Verificar (Check)","children":[{"kind":"goal","title":"Comparar resultados com a meta","children":[{"kind":"action","title":"Medir os indicadores"},{"kind":"action","title":"Avaliar a eficácia das ações"}]}]},{"kind":"objective","title":"Agir (Act)","children":[{"kind":"goal","title":"Padronizar ou corrigir","children":[{"kind":"action","title":"Padronizar o que funcionou (POP)"},{"kind":"action","title":"Tratar desvios e reiniciar o ciclo"}]}]}]}', 1, 2
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'plan' AND name = 'Ciclo PDCA');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'plan', 'Planejamento estratégico', 'Missão/visão desdobradas em objetivos estratégicos, metas anuais e iniciativas.', 'bi-bullseye',
'{"kind":"strategic","items":[{"kind":"objective","title":"Perspectiva: Pacientes e sociedade","children":[{"kind":"goal","title":"Elevar a satisfação do paciente para 90%","children":[{"kind":"action","title":"Implantar pesquisa de satisfação contínua"},{"kind":"action","title":"Plano de melhoria do acolhimento"}]}]},{"kind":"objective","title":"Perspectiva: Processos internos","children":[{"kind":"goal","title":"Reduzir o tempo de espera no pronto atendimento","children":[{"kind":"action","title":"Mapear o fluxo atual (mapa de processo)"},{"kind":"action","title":"Implantar classificação de risco"}]}]},{"kind":"objective","title":"Perspectiva: Pessoas e aprendizado","children":[{"kind":"goal","title":"Capacitar 100% da equipe assistencial","children":[{"kind":"action","title":"Calendário anual de treinamentos"}]}]},{"kind":"objective","title":"Perspectiva: Financeira","children":[{"kind":"goal","title":"Reduzir custos com manutenção corretiva em 15%","children":[{"kind":"action","title":"Ampliar o plano de manutenção preventiva"}]}]}]}', 1, 3
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'plan' AND name = 'Planejamento estratégico');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'diagram', 'Fluxograma básico', 'Início, processo, decisão e fim — ponto de partida para qualquer fluxo.', 'bi-diagram-3',
'{"v":1,"canvas":{"w":1400,"h":900,"grid":20},"nodes":[{"id":"n1","type":"start","x":560,"y":60,"w":180,"h":56,"text":"Início"},{"id":"n2","type":"process","x":540,"y":180,"w":220,"h":70,"text":"Etapa do processo"},{"id":"n3","type":"decision","x":560,"y":310,"w":180,"h":110,"text":"Conforme?"},{"id":"n4","type":"process","x":860,"y":330,"w":220,"h":70,"text":"Tratar não conformidade"},{"id":"n5","type":"end","x":560,"y":500,"w":180,"h":56,"text":"Fim"}],"edges":[{"id":"e1","from":"n1","to":"n2"},{"id":"e2","from":"n2","to":"n3"},{"id":"e3","from":"n3","to":"n5","label":"Sim"},{"id":"e4","from":"n3","to":"n4","label":"Não","fromSide":"right","toSide":"left"},{"id":"e5","from":"n4","to":"n2","fromSide":"top","toSide":"right","style":"dashed"}]}', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'diagram' AND name = 'Fluxograma básico');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'diagram', 'Mapa de processo (raias)', 'Raias por setor/função com o fluxo entre elas — ideal para mapear processos assistenciais e administrativos.', 'bi-layout-three-columns',
'{"v":1,"canvas":{"w":1600,"h":900,"grid":20},"nodes":[{"id":"l1","type":"lane","x":40,"y":40,"w":1500,"h":220,"text":"Recepção"},{"id":"l2","type":"lane","x":40,"y":260,"w":1500,"h":220,"text":"Enfermagem"},{"id":"l3","type":"lane","x":40,"y":480,"w":1500,"h":220,"text":"Equipe médica"},{"id":"n1","type":"start","x":120,"y":120,"w":160,"h":56,"text":"Paciente chega"},{"id":"n2","type":"process","x":340,"y":112,"w":200,"h":70,"text":"Cadastro / admissão"},{"id":"n3","type":"process","x":340,"y":332,"w":200,"h":70,"text":"Triagem / classificação de risco"},{"id":"n4","type":"decision","x":620,"y":310,"w":180,"h":110,"text":"Urgente?"},{"id":"n5","type":"process","x":900,"y":552,"w":200,"h":70,"text":"Atendimento imediato"},{"id":"n6","type":"process","x":900,"y":332,"w":200,"h":70,"text":"Aguardar por ordem de risco"},{"id":"n7","type":"process","x":1180,"y":552,"w":200,"h":70,"text":"Consulta / conduta"},{"id":"n8","type":"end","x":1200,"y":120,"w":160,"h":56,"text":"Alta / encaminhamento"}],"edges":[{"id":"e1","from":"n1","to":"n2","fromSide":"right","toSide":"left"},{"id":"e2","from":"n2","to":"n3","fromSide":"bottom","toSide":"top"},{"id":"e3","from":"n3","to":"n4","fromSide":"right","toSide":"left"},{"id":"e4","from":"n4","to":"n5","label":"Sim","fromSide":"bottom","toSide":"left"},{"id":"e5","from":"n4","to":"n6","label":"Não","fromSide":"right","toSide":"left"},{"id":"e6","from":"n6","to":"n7","fromSide":"bottom","toSide":"top"},{"id":"e7","from":"n5","to":"n7","fromSide":"right","toSide":"left"},{"id":"e8","from":"n7","to":"n8","fromSide":"top","toSide":"bottom"}]}', 1, 2
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'diagram' AND name = 'Mapa de processo (raias)');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'diagram', 'SIPOC', 'Fornecedores, Entradas, Processo, Saídas e Clientes — visão macro de um processo.', 'bi-table',
'{"v":1,"canvas":{"w":1600,"h":800,"grid":20},"nodes":[{"id":"h1","type":"lane","x":40,"y":40,"w":290,"h":680,"text":"Fornecedores (S)"},{"id":"h2","type":"lane","x":340,"y":40,"w":290,"h":680,"text":"Entradas (I)"},{"id":"h3","type":"lane","x":640,"y":40,"w":290,"h":680,"text":"Processo (P)"},{"id":"h4","type":"lane","x":940,"y":40,"w":290,"h":680,"text":"Saídas (O)"},{"id":"h5","type":"lane","x":1240,"y":40,"w":290,"h":680,"text":"Clientes (C)"},{"id":"n1","type":"note","x":70,"y":120,"w":230,"h":80,"text":"Quem fornece os insumos?"},{"id":"n2","type":"note","x":370,"y":120,"w":230,"h":80,"text":"Quais insumos / informações?"},{"id":"n3","type":"process","x":670,"y":120,"w":230,"h":60,"text":"1. Etapa inicial"},{"id":"n4","type":"process","x":670,"y":220,"w":230,"h":60,"text":"2. Etapa intermediária"},{"id":"n5","type":"process","x":670,"y":320,"w":230,"h":60,"text":"3. Etapa final"},{"id":"n6","type":"note","x":970,"y":120,"w":230,"h":80,"text":"O que o processo entrega?"},{"id":"n7","type":"note","x":1270,"y":120,"w":230,"h":80,"text":"Quem recebe as saídas?"}],"edges":[{"id":"e1","from":"n3","to":"n4"},{"id":"e2","from":"n4","to":"n5"}]}', 1, 3
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'diagram' AND name = 'SIPOC');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'diagram', 'Matriz SWOT', 'Forças, Fraquezas, Oportunidades e Ameaças em quatro quadrantes.', 'bi-grid-3x3-gap',
'{"v":1,"canvas":{"w":1200,"h":900,"grid":20},"nodes":[{"id":"q1","type":"lane","x":60,"y":60,"w":520,"h":360,"text":"Forças (S)","fill":"#e8f5e9","stroke":"#2e7d32"},{"id":"q2","type":"lane","x":600,"y":60,"w":520,"h":360,"text":"Fraquezas (W)","fill":"#fdecea","stroke":"#c62828"},{"id":"q3","type":"lane","x":60,"y":440,"w":520,"h":360,"text":"Oportunidades (O)","fill":"#e3f2fd","stroke":"#1565c0"},{"id":"q4","type":"lane","x":600,"y":440,"w":520,"h":360,"text":"Ameaças (T)","fill":"#fff8e1","stroke":"#ef6c00"},{"id":"n1","type":"note","x":90,"y":130,"w":220,"h":70,"text":"Equipe qualificada"},{"id":"n2","type":"note","x":630,"y":130,"w":220,"h":70,"text":"Processos não padronizados"},{"id":"n3","type":"note","x":90,"y":510,"w":220,"h":70,"text":"Acreditação ONA"},{"id":"n4","type":"note","x":630,"y":510,"w":220,"h":70,"text":"Restrição orçamentária"}],"edges":[]}', 1, 4
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'diagram' AND name = 'Matriz SWOT');

INSERT INTO plan_templates (kind, name, description, icon, data, is_builtin, sort_order)
SELECT 'diagram', 'Organograma', 'Estrutura hierárquica de diretoria, gerências e coordenações.', 'bi-diagram-2',
'{"v":1,"canvas":{"w":1400,"h":800,"grid":20},"nodes":[{"id":"n1","type":"process","x":600,"y":60,"w":200,"h":60,"text":"Diretoria"},{"id":"n2","type":"process","x":260,"y":220,"w":200,"h":60,"text":"Gerência assistencial"},{"id":"n3","type":"process","x":600,"y":220,"w":200,"h":60,"text":"Gerência administrativa"},{"id":"n4","type":"process","x":940,"y":220,"w":200,"h":60,"text":"Qualidade"},{"id":"n5","type":"process","x":140,"y":380,"w":200,"h":60,"text":"Enfermagem"},{"id":"n6","type":"process","x":380,"y":380,"w":200,"h":60,"text":"Corpo clínico"},{"id":"n7","type":"process","x":600,"y":380,"w":200,"h":60,"text":"RH / Financeiro"},{"id":"n8","type":"process","x":940,"y":380,"w":200,"h":60,"text":"Manutenção / Engenharia clínica"}],"edges":[{"id":"e1","from":"n1","to":"n2","fromSide":"bottom","toSide":"top"},{"id":"e2","from":"n1","to":"n3","fromSide":"bottom","toSide":"top"},{"id":"e3","from":"n1","to":"n4","fromSide":"bottom","toSide":"top"},{"id":"e4","from":"n2","to":"n5","fromSide":"bottom","toSide":"top"},{"id":"e5","from":"n2","to":"n6","fromSide":"bottom","toSide":"top"},{"id":"e6","from":"n3","to":"n7","fromSide":"bottom","toSide":"top"},{"id":"e7","from":"n4","to":"n8","fromSide":"bottom","toSide":"top"}]}', 1, 5
WHERE NOT EXISTS (SELECT 1 FROM plan_templates WHERE kind = 'diagram' AND name = 'Organograma');
