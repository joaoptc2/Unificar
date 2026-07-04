-- ============================================================
-- PLATAFORMA UNIFICADA — Schema do núcleo (core)
-- Banco único: as tabelas de cada módulo usam prefixo próprio
--   doc_  → Documentos / Qualidade
--   chat_ → Comunicação (chat, tarefas, reuniões)
--   rh_   → Recursos Humanos
--   man_  → Manutenção / Engenharia Clínica
-- As tabelas abaixo (sem prefixo) pertencem ao núcleo.
-- ============================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name            VARCHAR(150)  NOT NULL,
    username        VARCHAR(100)  NOT NULL,
    email           VARCHAR(190)  NOT NULL,
    password_hash   VARCHAR(255)  NULL COMMENT 'NULL quando o usuário autentica apenas via Moodle',
    is_admin        TINYINT(1)    NOT NULL DEFAULT 0 COMMENT 'Admin global da plataforma',
    active          TINYINT(1)    NOT NULL DEFAULT 1,
    auth_source     ENUM('local','moodle') NOT NULL DEFAULT 'local',
    moodle_user_id  BIGINT UNSIGNED NULL,
    avatar          VARCHAR(255)  NULL,
    phone           VARCHAR(40)   NULL,
    job_title       VARCHAR(120)  NULL,
    sector          VARCHAR(120)  NULL,
    two_factor_secret VARCHAR(64) NULL,
    two_factor_enabled TINYINT(1) NOT NULL DEFAULT 0,
    force_password_change TINYINT(1) NOT NULL DEFAULT 0,
    last_login_at   DATETIME      NULL,
    created_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_moodle (moodle_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS modules (
    slug        VARCHAR(40)  PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    icon        VARCHAR(60)  NULL,
    sort_order  INT          NOT NULL DEFAULT 0,
    active      TINYINT(1)   NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nível de acesso de cada usuário em cada módulo.
-- Os níveis válidos são declarados pelo manifesto de cada módulo
-- (modules/<slug>/module.php); 'none' significa sem acesso.
CREATE TABLE IF NOT EXISTS user_module_access (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    module_slug VARCHAR(40)  NOT NULL,
    role        VARCHAR(30)  NOT NULL DEFAULT 'none',
    granted_by  INT UNSIGNED NULL,
    granted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_module (user_id, module_slug),
    CONSTRAINT fk_uma_user   FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_uma_module FOREIGN KEY (module_slug) REFERENCES modules(slug) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key   VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_log (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NULL,
    module     VARCHAR(40)  NULL,
    action     VARCHAR(80)  NOT NULL,
    entity     VARCHAR(80)  NULL,
    entity_id  VARCHAR(40)  NULL,
    details    TEXT NULL,
    ip_address VARCHAR(45)  NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_user (user_id),
    KEY idx_audit_module (module),
    KEY idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS password_resets (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_pr_token (token_hash),
    CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(190) NOT NULL COMMENT 'username/email ou IP',
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    success    TINYINT(1) NOT NULL DEFAULT 0,
    KEY idx_la_identifier (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Notificações unificadas (todos os módulos gravam aqui)
CREATE TABLE IF NOT EXISTS notifications (
    id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    module     VARCHAR(40) NULL,
    type       VARCHAR(60) NULL,
    title      VARCHAR(190) NOT NULL,
    message    TEXT NULL,
    link       VARCHAR(255) NULL,
    read_at    DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notif_user (user_id, read_at),
    CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulos padrão
INSERT INTO modules (slug, name, icon, sort_order, active) VALUES
    ('documentos', 'Documentos',   'bi-file-earmark-text', 10, 1),
    ('chat',       'Comunicação',  'bi-chat-dots',         20, 1),
    ('rh',         'RH',           'bi-people',            30, 1),
    ('manutencao', 'Manutenção',   'bi-tools',             40, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);

-- Usuário administrador inicial (senha: admin123 — TROQUE após o primeiro login)
INSERT INTO users (name, username, email, password_hash, is_admin, active)
SELECT 'Administrador', 'admin', 'admin@example.com',
       '$2y$12$/zdMoBKaHmBS0CG1CmvYbu4QK15ijiRXr45cgOUbKhg7tOUPOxuxO', 1, 1
WHERE NOT EXISTS (SELECT 1 FROM users WHERE username = 'admin');
