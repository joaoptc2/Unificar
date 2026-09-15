-- ============================================================
-- Migração 011 — Layout: módulo com nome próprio e visível (ou não) no topo
--   • modules.label / modules.custom_icon: o hospital chama "Manutenção" de
--     "Engenharia Clínica" e "Planejamento" de "Qualidade". Ficam em colunas
--     PRÓPRIAS porque name/icon são reescritos com os valores do manifesto
--     a cada abertura da tela de Módulos;
--   • modules.show_in_topbar: tirar do topo sem tirar o acesso — o módulo
--     continua na tela inicial e por link direto.
-- Idempotente (o runner tolera "coluna já existe").
-- ============================================================

ALTER TABLE modules ADD COLUMN label VARCHAR(100) NULL COMMENT 'Nome escolhido pelo hospital (vazio = o do manifesto)' AFTER name;
ALTER TABLE modules ADD COLUMN custom_icon VARCHAR(60) NULL COMMENT 'Ícone escolhido (vazio = o do manifesto)' AFTER icon;
ALTER TABLE modules ADD COLUMN show_in_topbar TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'Aparece na barra superior' AFTER active;
