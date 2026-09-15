-- ============================================================================
--  012 — DOCUMENTOS: setores independentes (vínculo usuário ↔ setor)
--
--  A partir desta versão cada usuário só enxerga os documentos, indicadores
--  e planos de ação dos setores em que está incluído (doc_user_sectors).
--
--  O risco desta mudança é o sistema em produção "apagar" da noite para o
--  dia: nenhuma instalação tem vínculos cadastrados hoje, então todo mundo
--  passaria a ver só o que não tem setor. Por isso a migração PRESERVA a
--  visibilidade atual — inclui todos os usuários ativos em todos os setores
--  existentes — e deixa o administrador estreitar depois, setor a setor, na
--  tela "Usuários do setor". Nada é excluído aqui.
--
--  Idempotente: só semeia quando a tabela de vínculo está vazia.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. A tabela do vínculo (pode não existir em bases muito antigas).
CREATE TABLE IF NOT EXISTS `doc_user_sectors` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `sector_id`  INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_doc_user_sector` (`user_id`, `sector_id`),
    INDEX `idx_doc_us_sector` (`sector_id`),
    CONSTRAINT `fk_doc_us_user`   FOREIGN KEY (`user_id`)   REFERENCES `users` (`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_doc_us_sector` FOREIGN KEY (`sector_id`) REFERENCES `doc_sectors` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Semeia o vínculo preservando a visibilidade de hoje (todos veem tudo).
--    O SELECT externo garante que isto só roda uma vez: assim que existir um
--    vínculo qualquer, a condição NOT EXISTS falha e a migração vira no-op.
INSERT IGNORE INTO `doc_user_sectors` (`user_id`, `sector_id`)
SELECT u.`id`, s.`id`
  FROM `users` u
  CROSS JOIN `doc_sectors` s
 WHERE u.`active` = 1
   AND s.`deleted_at` IS NULL
   AND NOT EXISTS (SELECT 1 FROM `doc_user_sectors` x);

-- 3. Quem já administrava setores continua podendo: as duas micropermissões
--    novas (incluir usuários e ver todos os setores) vão para quem tem
--    sectors.edit. Sem isto, o administrador de qualidade perderia a visão
--    do hospital inteiro na atualização.
INSERT IGNORE INTO `permission_grants` (`subject_type`, `subject_id`, `module_slug`, `perm_key`, `allowed`)
SELECT g.`subject_type`, g.`subject_id`, 'documentos', 'sectors.assign', 1
  FROM `permission_grants` g
 WHERE g.`module_slug` = 'documentos' AND g.`perm_key` = 'sectors.edit' AND g.`allowed` = 1;

INSERT IGNORE INTO `permission_grants` (`subject_type`, `subject_id`, `module_slug`, `perm_key`, `allowed`)
SELECT g.`subject_type`, g.`subject_id`, 'documentos', 'sectors.view_all', 1
  FROM `permission_grants` g
 WHERE g.`module_slug` = 'documentos' AND g.`perm_key` = 'sectors.edit' AND g.`allowed` = 1;
