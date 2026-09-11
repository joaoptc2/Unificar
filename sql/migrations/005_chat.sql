-- ============================================================
-- Migração 005 — Módulo Comunicação (chat)
-- O módulo passou a ser SOMENTE chat. Tarefas, reuniões, equipes,
-- processos, enquetes, exportações e o painel administrativo foram
-- descontinuados. As tabelas correspondentes NÃO são removidas
-- automaticamente (preservação de dados) — veja o bloco comentado no
-- fim para removê-las manualmente quando não forem mais necessárias.
-- ============================================================

-- Configurações visuais do módulo legado não são mais usadas
-- (o chat segue o padrão visual da plataforma e o nome da organização).
DELETE FROM chat_settings WHERE `key` IN
    ('app_name', 'primary_color', 'sidebar_bg', 'sidebar_text', 'sidebar_hover', 'allow_registration');

-- Índice para a busca de mensagens (texto) por canal
ALTER TABLE chat_messages ADD INDEX idx_chat_msg_channel_deleted (channel_id, deleted_at, created_at);

-- Remoção OPCIONAL das tabelas descontinuadas (execute manualmente):
-- DROP TABLE IF EXISTS chat_task_comments, chat_task_assignees, chat_tasks;
-- DROP TABLE IF EXISTS chat_meeting_participants, chat_meetings;
-- DROP TABLE IF EXISTS chat_process_steps, chat_processes;
-- DROP TABLE IF EXISTS chat_poll_votes, chat_poll_options, chat_polls;
-- DROP TABLE IF EXISTS chat_team_members, chat_teams, chat_export_logs;
