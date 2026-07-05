-- ================================================================
-- CHAT INTERNO EMPRESARIAL - Schema Completo
-- Inspirado no Slack | PHP 8.0+ | MySQL 5.7+ / MariaDB 10.3+
-- ================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ----------------------------------------------------------------
-- USUÁRIOS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `email` VARCHAR(200) NOT NULL UNIQUE,
    `password` VARCHAR(255) NOT NULL,
    `avatar` VARCHAR(500) NULL,
    `role` ENUM('admin','manager','member') NOT NULL DEFAULT 'member',
    `status` ENUM('online','offline','away','dnd') NOT NULL DEFAULT 'offline',
    `status_text` VARCHAR(200) NULL,
    `status_emoji` VARCHAR(10) NULL,
    `title` VARCHAR(200) NULL,
    `department` VARCHAR(200) NULL,
    `phone` VARCHAR(30) NULL,
    `timezone` VARCHAR(50) NOT NULL DEFAULT 'America/Sao_Paulo',
    `last_seen_at` DATETIME NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_users_email` (`email`),
    KEY `idx_users_status` (`status`),
    KEY `idx_users_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CANAIS (públicos, privados, mensagens diretas)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channels` (
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
    UNIQUE KEY `uk_channels_slug` (`slug`),
    KEY `idx_channels_type` (`type`),
    KEY `idx_channels_archived` (`is_archived`),
    KEY `idx_channels_category` (`category_id`),
    CONSTRAINT `fk_channels_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MEMBROS DO CANAL
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channel_members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('owner','admin','member') NOT NULL DEFAULT 'member',
    `notifications` ENUM('all','mentions','none') NOT NULL DEFAULT 'all',
    `last_read_message_id` INT UNSIGNED NULL DEFAULT NULL,
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_channel_member` (`channel_id`, `user_id`),
    KEY `idx_cm_user` (`user_id`),
    CONSTRAINT `fk_cm_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_cm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `messages` (
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
    KEY `idx_msg_channel` (`channel_id`, `created_at`),
    KEY `idx_msg_parent` (`parent_id`),
    KEY `idx_msg_user` (`user_id`),
    KEY `idx_msg_pinned` (`channel_id`, `is_pinned`),
    KEY `idx_msg_deleted` (`deleted_at`),
    CONSTRAINT `fk_msg_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_msg_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_msg_parent` FOREIGN KEY (`parent_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- REAÇÕES EM MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_reactions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `emoji` VARCHAR(50) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_reaction` (`message_id`, `user_id`, `emoji`),
    CONSTRAINT `fk_react_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_react_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ANEXOS DE MENSAGENS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `message_attachments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `original_name` VARCHAR(500) NOT NULL,
    `file_path` VARCHAR(500) NOT NULL,
    `file_type` VARCHAR(100) NOT NULL,
    `file_size` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_attach_msg` (`message_id`),
    CONSTRAINT `fk_attach_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_attach_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MENÇÕES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `mentions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `message_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `team_id` INT UNSIGNED NULL,
    `type` ENUM('user','team','channel','here') NOT NULL DEFAULT 'user',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_mention_user` (`user_id`),
    KEY `idx_mention_team` (`team_id`),
    CONSTRAINT `fk_mention_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mention_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EQUIPES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `teams` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `slug` VARCHAR(200) NOT NULL UNIQUE,
    `description` TEXT NULL,
    `color` VARCHAR(7) NOT NULL DEFAULT '#6366f1',
    `created_by` INT UNSIGNED NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_teams_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- MEMBROS DA EQUIPE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `team_members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `team_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `role` ENUM('leader','member') NOT NULL DEFAULT 'member',
    `joined_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_team_member` (`team_id`, `user_id`),
    CONSTRAINT `fk_tm_team` FOREIGN KEY (`team_id`) REFERENCES `teams`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tasks` (
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
    KEY `idx_tasks_channel` (`channel_id`),
    KEY `idx_tasks_status` (`status`),
    KEY `idx_tasks_priority` (`priority`),
    KEY `idx_tasks_due` (`due_date`),
    CONSTRAINT `fk_tasks_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- RESPONSÁVEIS PELAS TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `task_assignees` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_task_assignee` (`task_id`, `user_id`),
    CONSTRAINT `fk_ta_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ta_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- COMENTÁRIOS NAS TAREFAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `task_comments` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `task_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NULL,
    `content` TEXT NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_tc_task` FOREIGN KEY (`task_id`) REFERENCES `tasks`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tc_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- REUNIÕES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meetings` (
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
    KEY `idx_meetings_date` (`scheduled_at`),
    KEY `idx_meetings_status` (`status`),
    CONSTRAINT `fk_meetings_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_meetings_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- PARTICIPANTES DE REUNIÃO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `meeting_participants` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `meeting_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `status` ENUM('pending','accepted','declined','tentative') NOT NULL DEFAULT 'pending',
    `responded_at` DATETIME NULL,
    UNIQUE KEY `uk_meeting_participant` (`meeting_id`, `user_id`),
    CONSTRAINT `fk_mp_meeting` FOREIGN KEY (`meeting_id`) REFERENCES `meetings`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mp_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- PROCESSOS (Workflows)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `processes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `channel_id` INT UNSIGNED NULL,
    `title` VARCHAR(500) NOT NULL,
    `description` TEXT NULL,
    `status` ENUM('active','paused','completed','cancelled') NOT NULL DEFAULT 'active',
    `progress` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_processes_status` (`status`),
    CONSTRAINT `fk_processes_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_processes_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ETAPAS DO PROCESSO
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `process_steps` (
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
    KEY `idx_ps_process` (`process_id`, `order_num`),
    CONSTRAINT `fk_ps_process` FOREIGN KEY (`process_id`) REFERENCES `processes`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ps_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ps_completer` FOREIGN KEY (`completed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- NOTIFICAÇÕES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `notifications` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(500) NOT NULL,
    `content` TEXT NULL,
    `link` VARCHAR(500) NULL,
    `is_read` TINYINT(1) NOT NULL DEFAULT 0,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_notif_user` (`user_id`, `is_read`, `created_at`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FAVORITOS / MENSAGENS SALVAS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `bookmarks` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `message_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_bookmark` (`user_id`, `message_id`),
    CONSTRAINT `fk_bm_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_bm_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- LOG DE AUDITORIA
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_log` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `action` VARCHAR(50) NOT NULL,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INT UNSIGNED NULL,
    `old_data` JSON NULL,
    `new_data` JSON NULL,
    `ip_address` VARCHAR(45) NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_date` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CONFIGURAÇÕES DO SISTEMA
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `settings` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key` VARCHAR(100) NOT NULL UNIQUE,
    `value` TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- ENQUETES
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `polls` (
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
    KEY `idx_polls_channel` (`channel_id`),
    CONSTRAINT `fk_polls_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_polls_message` FOREIGN KEY (`message_id`) REFERENCES `messages`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_polls_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_options` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `text` VARCHAR(300) NOT NULL,
    `order_num` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT `fk_po_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `poll_votes` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `poll_id` INT UNSIGNED NOT NULL,
    `option_id` INT UNSIGNED NOT NULL,
    `user_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_poll_vote` (`poll_id`, `option_id`, `user_id`),
    CONSTRAINT `fk_pv_poll` FOREIGN KEY (`poll_id`) REFERENCES `polls`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pv_option` FOREIGN KEY (`option_id`) REFERENCES `poll_options`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_pv_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EMOJIS PERSONALIZADOS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `custom_emojis` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `image_path` VARCHAR(500) NOT NULL,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_ce_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- CATEGORIAS DE CANAIS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channel_categories` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(200) NOT NULL,
    `order_num` INT UNSIGNED NOT NULL DEFAULT 0,
    `is_collapsed` TINYINT(1) NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_cc_creator` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- FAVORITOS DE CANAIS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `channel_favorites` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NOT NULL,
    `channel_id` INT UNSIGNED NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_fav` (`user_id`, `channel_id`),
    CONSTRAINT `fk_fav_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fav_channel` FOREIGN KEY (`channel_id`) REFERENCES `channels`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- EXPORT LOGS
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `export_logs` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT UNSIGNED NULL,
    `type` VARCHAR(50) NOT NULL,
    `params` JSON NULL,
    `file_path` VARCHAR(500) NULL,
    `status` ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` DATETIME NULL,
    CONSTRAINT `fk_el_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------
-- DADOS INICIAIS
-- ----------------------------------------------------------------

INSERT IGNORE INTO `settings` (`key`, `value`) VALUES
('app_name', 'TeamChat'),
('primary_color', '#6366f1'),
('sidebar_bg', '#0f0a25'),
('sidebar_text', '#a5b4fc'),
('sidebar_hover', '#1e1b4b'),
('allow_registration', '1'),
('max_upload_size', '10485760'),
('default_channel', 'geral');

SET FOREIGN_KEY_CHECKS = 1;
