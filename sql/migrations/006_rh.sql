-- ============================================================
-- Migração 006 — Módulo RH
--   • Pesquisas: novos tipos de pergunta (texto livre, escolha única,
--     múltipla escolha, sim/não, escala, número, data), obrigatoriedade,
--     texto de apoio, público-alvo, portal e envio por e-mail, controle
--     de participação (quem já respondeu, mesmo em pesquisas anônimas);
--   • Comunicados: resumo, corpo em HTML (editor), imagem, anexo,
--     portal do funcionário e envio por e-mail;
--   • Solicitações: vínculo com a solicitação de férias do portal;
--   • Brindes: catálogo e resgates com pontos (rh_employee_scores).
-- Idempotente (o runner tolera "já existe").
-- ============================================================

-- ---- Pesquisas -------------------------------------------------------------
ALTER TABLE rh_survey_questions MODIFY COLUMN `type`
    ENUM('rating','text','choice','multiple','yes_no','scale','number','date') NOT NULL DEFAULT 'rating';
ALTER TABLE rh_survey_questions ADD COLUMN required TINYINT(1) NOT NULL DEFAULT 0 AFTER options;
ALTER TABLE rh_survey_questions ADD COLUMN help_text VARCHAR(255) NULL AFTER required;

ALTER TABLE rh_surveys ADD COLUMN department_id INT UNSIGNED NULL COMMENT 'NULL = todos os departamentos' AFTER anonymous;
ALTER TABLE rh_surveys ADD COLUMN show_in_portal TINYINT(1) NOT NULL DEFAULT 1 AFTER department_id;
ALTER TABLE rh_surveys ADD COLUMN send_email TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_portal;
ALTER TABLE rh_surveys ADD COLUMN emailed_at DATETIME NULL AFTER send_email;
ALTER TABLE rh_surveys ADD COLUMN updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP AFTER created_at;
ALTER TABLE rh_surveys ADD CONSTRAINT fk_rh_survey_dept
    FOREIGN KEY (department_id) REFERENCES rh_departments (id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS rh_survey_participations (
    survey_id    INT UNSIGNED NOT NULL,
    user_id      INT UNSIGNED NOT NULL,
    completed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (survey_id, user_id),
    CONSTRAINT fk_rh_sp_survey FOREIGN KEY (survey_id) REFERENCES rh_surveys (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_sp_user   FOREIGN KEY (user_id)   REFERENCES users (id)      ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Comunicados -----------------------------------------------------------
ALTER TABLE rh_announcements ADD COLUMN summary VARCHAR(300) NULL COMMENT 'Resumo (lista, e-mail)' AFTER title;
ALTER TABLE rh_announcements ADD COLUMN body_html MEDIUMTEXT NULL COMMENT 'Corpo formatado (editor); body = texto simples' AFTER body;
ALTER TABLE rh_announcements ADD COLUMN image_path VARCHAR(500) NULL AFTER pinned;
ALTER TABLE rh_announcements ADD COLUMN attachment_path VARCHAR(500) NULL AFTER image_path;
ALTER TABLE rh_announcements ADD COLUMN attachment_name VARCHAR(255) NULL AFTER attachment_path;
ALTER TABLE rh_announcements ADD COLUMN show_in_portal TINYINT(1) NOT NULL DEFAULT 1 AFTER attachment_name;
ALTER TABLE rh_announcements ADD COLUMN send_email TINYINT(1) NOT NULL DEFAULT 0 AFTER show_in_portal;
ALTER TABLE rh_announcements ADD COLUMN emailed_at DATETIME NULL AFTER send_email;

-- ---- Solicitações (férias pelo portal) ------------------------------------
ALTER TABLE rh_requests ADD COLUMN vacation_id INT UNSIGNED NULL COMMENT 'Solicitação de férias criada no portal' AFTER type;
ALTER TABLE rh_requests ADD COLUMN requested_by INT UNSIGNED NULL COMMENT 'Usuário que abriu a solicitação' AFTER body;
ALTER TABLE rh_requests ADD INDEX idx_rh_req_vacation (vacation_id);
ALTER TABLE rh_requests ADD CONSTRAINT fk_rh_req_vacation
    FOREIGN KEY (vacation_id) REFERENCES rh_vacations (id) ON DELETE SET NULL;

-- ---- Brindes (troca de pontos) ---------------------------------------------
CREATE TABLE IF NOT EXISTS rh_rewards (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(150) NOT NULL,
    description TEXT NULL,
    points_cost INT UNSIGNED NOT NULL DEFAULT 0,
    stock       INT NULL COMMENT 'NULL = ilimitado',
    image_path  VARCHAR(500) NULL,
    active      TINYINT(1) NOT NULL DEFAULT 1,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rh_rewards_active (active, points_cost),
    CONSTRAINT fk_rh_rewards_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rh_reward_redemptions (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    reward_id    INT UNSIGNED NOT NULL,
    employee_id  INT UNSIGNED NOT NULL,
    points_spent INT UNSIGNED NOT NULL,
    status       ENUM('pendente','aprovada','entregue','rejeitada','cancelada') NOT NULL DEFAULT 'pendente',
    notes        TEXT NULL COMMENT 'Observação do funcionário',
    response     TEXT NULL COMMENT 'Resposta do RH',
    score_id     BIGINT UNSIGNED NULL COMMENT 'Lançamento (negativo) em rh_employee_scores',
    responded_by INT UNSIGNED NULL,
    responded_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rh_redeem_employee (employee_id, created_at),
    KEY idx_rh_redeem_status (status),
    CONSTRAINT fk_rh_redeem_reward    FOREIGN KEY (reward_id)    REFERENCES rh_rewards (id)         ON DELETE RESTRICT,
    CONSTRAINT fk_rh_redeem_employee  FOREIGN KEY (employee_id)  REFERENCES rh_employees (id)       ON DELETE CASCADE,
    CONSTRAINT fk_rh_redeem_score     FOREIGN KEY (score_id)     REFERENCES rh_employee_scores (id) ON DELETE SET NULL,
    CONSTRAINT fk_rh_redeem_responder FOREIGN KEY (responded_by) REFERENCES users (id)              ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
