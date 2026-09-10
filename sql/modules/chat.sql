-- ================================================================
-- MÓDULO COMUNICAÇÃO (chat) — Schema consolidado
-- Porte do chat interno legado (schema.sql + migrations 001/002).
--
-- Regras do porte:
--   * Todas as tabelas do módulo com prefixo chat_.
--   * `users`, `notifications` e `audit_log` são GLOBAIS (núcleo) —
--     NÃO são criadas aqui. FKs de usuário apontam para users(id).
--   * Presença (status online/away/dnd, status_text, emoji, timezone,
--     last_seen_at) saiu de `users` para a tabela chat_presence.
--   * Idempotente: CREATE TABLE IF NOT EXISTS / INSERT IGNORE.
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------
-- PRESENÇA (por usuário — substitui as colunas de status de `users`)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_presence` (
    `user_id` INT UNSIGNED NOT NULL PRIMARY KEY,
    `status` ENUM('online','offline','away','dnd') NOT NULL DEFAULT 'offline',
    `status_text` VARCHAR(200) NULL,
    `status_emoji` VARCHAR(10) NULL,
    `timezone` VARCHAR(50) NOT NULL DEFAULT 'America/Sao_Paulo',
    `last_seen_at` DATETIME NULL,
    KEY `idx_presence_status` (`status`),
    CONSTRAINT `fk_chat_presence_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CATEGORIAS DE CANAIS (#30)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_channel_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `order_num` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_collapsed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_chat_cc_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CANAIS (públicos, privados, mensagens diretas)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_channels` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NULL,
    `slug` VARCHAR(200) NULL,
    `description` TEXT NULL,
    `type` ENUM('public','private','direct') NOT NULL DEFAULT 'public',
    `topic` VARCHAR(500) NULL,
    `created_by` INT UNSIGNED NULL,
    `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
    `is_general` TINYINT(1) NOT NULL DEFAULT 0,
    `is_readonly` TINYINT(1) NOT NULL DEFAULT 0,
    `retention_days` INT UNSIGNED NULL DEFAULT NULL,
    `slow_mode_seconds` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_pinned` INT UNSIGNED NOT NULL DEFAULT 50,
    `allow_threads` TINYINT(1) NOT NULL DEFAULT 1,
    `category_id` INT UNSIGNED NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_channels_slug` (`slug`),
    KEY `idx_chat_channels_type` (`type`),
    KEY `idx_chat_channels_archived` (`is_archived`),
    KEY `idx_chat_channels_category` (`category_id`),
    CONSTRAINT `fk_chat_channels_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MEMBROS DO CANAL
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_channel_members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    `notifications` ENUM('all','mentions','none') NOT NULL DEFAULT 'all',
    `last_read_message_id` INT UNSIGNED NULL DEFAULT NULL,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_channel_member` (`channel_id`, `user_id`),
    KEY `idx_chat_cm_user` (`user_id`),
    CONSTRAINT `fk_chat_cm_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_messages` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `parent_id` INT UNSIGNED NULL,
    `content` TEXT NOT NULL,
    `type` ENUM('text','system','task_ref','meeting_ref','file') NOT NULL DEFAULT 'text',
    `is_edited` TINYINT(1) NOT NULL DEFAULT 0,
    `edited_at` DATETIME NULL,
    `is_pinned` TINYINT(1) NOT NULL DEFAULT 0,
    `pinned_by` INT UNSIGNED NULL,
    `pinned_at` DATETIME NULL,
    `reply_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `reaction_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `metadata` JSON NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at` DATETIME NULL,
    KEY `idx_chat_msg_channel` (`channel_id`, `created_at`),
    KEY `idx_chat_msg_parent` (`parent_id`),
    KEY `idx_chat_msg_user` (`user_id`),
    KEY `idx_chat_msg_pinned` (`channel_id`, `is_pinned`),
    KEY `idx_chat_msg_deleted` (`deleted_at`),
    KEY `idx_chat_msg_channel_deleted` (`channel_id`, `deleted_at`, `created_at`),
    CONSTRAINT `fk_chat_msg_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_msg_parent` FOREIGN KEY (`parent_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- REAÇÕES EM MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_message_reactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `emoji` VARCHAR(50) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_reaction` (`message_id`, `user_id`, `emoji`),
    CONSTRAINT `fk_chat_react_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_react_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ANEXOS DE MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_message_attachments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `original_name` VARCHAR(500) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_attach_msg` (`message_id`),
    CONSTRAINT `fk_chat_attach_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_attach_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MENÇÕES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_mentions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `team_id` INT UNSIGNED NULL,
    `type` ENUM('user','team','channel','here') NOT NULL DEFAULT 'user',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_mention_user` (`user_id`),
    KEY `idx_chat_mention_team` (`team_id`),
    CONSTRAINT `fk_chat_mention_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_mention_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================================================
-- TABELAS DESCONTINUADAS (equipes, tarefas, reuniões, processos,
-- enquetes, exportações): o módulo passou a ser SOMENTE chat. São
-- mantidas apenas para a importação de dados legados
-- (sql/legacy-migration/chat.sql) e podem ser removidas — ver
-- sql/migrations/005_chat.sql.
-- ================================================================

-- ----------------------------------------------------------------
-- EQUIPES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_teams` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    `created_by` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_chat_teams_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MEMBROS DA EQUIPE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_team_members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `team_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('leader','member') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_team_member` (`team_id`, `user_id`),
    CONSTRAINT `fk_chat_tm_team` FOREIGN KEY (`team_id`) REFERENCES `chat_teams`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_tasks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('todo','in_progress','review','done','cancelled') NOT NULL DEFAULT 'todo',
    `priority` ENUM('low','medium','high','urgent') NOT NULL DEFAULT 'medium',
    `created_by` INT UNSIGNED NULL,
    `due_date` DATE NULL,
    `completed_at` DATETIME NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_chat_tasks_channel` (`channel_id`),
    KEY `idx_chat_tasks_status` (`status`),
    KEY `idx_chat_tasks_priority` (`priority`),
    KEY `idx_chat_tasks_due` (`due_date`),
    CONSTRAINT `fk_chat_tasks_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- RESPONSÁVEIS PELAS TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_task_assignees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_task_assignee` (`task_id`, `user_id`),
    CONSTRAINT `fk_chat_ta_task` FOREIGN KEY (`task_id`) REFERENCES `chat_tasks`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- COMENTÁRIOS NAS TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_task_comments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `content` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_chat_tc_task` FOREIGN KEY (`task_id`) REFERENCES `chat_tasks`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- REUNIÕES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_meetings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` TEXT NULL,
    `scheduled_at` DATETIME NOT NULL,
    `duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
    `location` VARCHAR(500) NULL,
    `meeting_link` VARCHAR(500) NULL,
    `type` ENUM('video','presential','hybrid') NOT NULL DEFAULT 'video',
    `status` ENUM('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_chat_meetings_date` (`scheduled_at`),
    KEY `idx_chat_meetings_status` (`status`),
    CONSTRAINT `fk_chat_meetings_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_meetings_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- PARTICIPANTES DE REUNIÃO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_meeting_participants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending','accepted','declined','tentative') NOT NULL DEFAULT 'pending',
    `responded_at` DATETIME NULL,
    UNIQUE KEY `uk_chat_meeting_participant` (`meeting_id`, `user_id`),
    CONSTRAINT `fk_chat_mp_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `chat_meetings`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_mp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- PROCESSOS (Workflows)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_processes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
    `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_chat_processes_status` (`status`),
    CONSTRAINT `fk_chat_processes_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_processes_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ETAPAS DO PROCESSO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_process_steps` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `process_id` INT UNSIGNED NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` TEXT NULL,
    `order_num` INT UNSIGNED NOT NULL DEFAULT 0,
    `status` ENUM('pending','in_progress','completed','skipped') NOT NULL DEFAULT 'pending',
    `assigned_to` INT UNSIGNED NULL,
    `due_date` DATE NULL,
    `completed_at` DATETIME NULL,
    `completed_by` INT UNSIGNED NULL,
    KEY `idx_chat_ps_process` (`process_id`, `order_num`),
    CONSTRAINT `fk_chat_ps_process` FOREIGN KEY (`process_id`) REFERENCES `chat_processes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_ps_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_ps_completer` FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FAVORITOS / MENSAGENS SALVAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_bookmarks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_bookmark` (`user_id`, `message_id`),
    CONSTRAINT `fk_chat_bm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_bm_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CONFIGURAÇÕES DO MÓDULO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ENQUETES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_polls` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NULL,
    `user_id` INT UNSIGNED NULL,
    `question` VARCHAR(500) NOT NULL,
    `is_anonymous` TINYINT(1) NOT NULL DEFAULT 0,
    `is_multiple` TINYINT(1) NOT NULL DEFAULT 0,
    `closes_at` DATETIME NULL,
    `is_closed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_chat_polls_channel` (`channel_id`),
    CONSTRAINT `fk_chat_polls_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_polls_message` FOREIGN KEY (`message_id`) REFERENCES `chat_messages`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_chat_polls_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_poll_options` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `text` VARCHAR(300) NOT NULL,
    `order_num` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_chat_po_poll` FOREIGN KEY (`poll_id`) REFERENCES `chat_polls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `chat_poll_votes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `option_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_poll_vote` (`poll_id`, `option_id`, `user_id`),
    CONSTRAINT `fk_chat_pv_poll` FOREIGN KEY (`poll_id`) REFERENCES `chat_polls`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_pv_option` FOREIGN KEY (`option_id`) REFERENCES `chat_poll_options`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_pv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EMOJIS PERSONALIZADOS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_custom_emojis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `image_path` VARCHAR(500) NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_chat_ce_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FAVORITOS DE CANAIS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_channel_favorites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `channel_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_chat_fav` (`user_id`, `channel_id`),
    CONSTRAINT `fk_chat_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_chat_fav_channel` FOREIGN KEY (`channel_id`) REFERENCES `chat_channels`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EXPORT LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `chat_export_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `type` VARCHAR(50) NOT NULL,
    `params` JSON NULL,
    `file_path` VARCHAR(500) NULL,
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    CONSTRAINT `fk_chat_el_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- DADOS INICIAIS
-- ----------------------------------------------------------------

INSERT IGNORE INTO `chat_settings` (`key`, `value`) VALUES
('max_upload_size', '10485760'),
('default_channel', 'geral');

-- Canal #geral (o instalador legado o semeava)
INSERT IGNORE INTO `chat_channels` (`name`, `slug`, `description`, `type`, `is_general`, `created_by`)
VALUES ('Geral', 'geral', 'Canal geral para toda a equipe', 'public', 1, NULL);

SET FOREIGN_KEY_CHECKS = 1;
