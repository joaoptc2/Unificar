-- ============================================================
-- Migração 002 — Micropermissões e grupos de usuários
-- Aplicar em instalações existentes. Instalações novas já recebem
-- estas tabelas pelo sql/schema.sql.
-- ============================================================

CREATE TABLE IF NOT EXISTS user_groups (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_group_members (
    group_id INT UNSIGNED NOT NULL,
    user_id  INT UNSIGNED NOT NULL,
    added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (group_id, user_id),
    KEY idx_ugm_user (user_id),
    CONSTRAINT fk_ugm_group FOREIGN KEY (group_id) REFERENCES user_groups(id) ON DELETE CASCADE,
    CONSTRAINT fk_ugm_user  FOREIGN KEY (user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Concessões de micropermissões.
--   subject_type/user  → allowed=1 concede, allowed=0 NEGA (sobrepõe grupos)
--   subject_type/group → allowed=1 concede (negação em grupo não é usada)
-- perm_key = "<recurso>.<ação>", ex.: "documents.edit"
CREATE TABLE IF NOT EXISTS permission_grants (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subject_type ENUM('user','group') NOT NULL,
    subject_id   INT UNSIGNED NOT NULL,
    module_slug  VARCHAR(40) NOT NULL,
    perm_key     VARCHAR(80) NOT NULL,
    allowed      TINYINT(1) NOT NULL DEFAULT 1,
    granted_by   INT UNSIGNED NULL,
    granted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_grant (subject_type, subject_id, module_slug, perm_key),
    KEY idx_grant_module (module_slug, perm_key),
    KEY idx_grant_subject (subject_type, subject_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
