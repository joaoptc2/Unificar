-- ============================================================
-- Migração 007 — Módulo Manutenção
--   • Código de identificação único de 12 dígitos por equipamento
--     (asset_code): gera código de barras (Code 128) e QR code para
--     localizar o equipamento e abrir sua página de histórico.
--     Equipamentos existentes recebem o código automaticamente ao
--     abrir a lista de equipamentos ou pelo cron do módulo.
--   • Aba "Hospital / Dados da unidade" descontinuada (tabela mantida).
-- Idempotente (o runner tolera "já existe").
-- ============================================================

ALTER TABLE man_equipment ADD COLUMN asset_code CHAR(12) NULL
    COMMENT 'Código único de 12 dígitos (11 + dígito verificador Luhn) — código de barras/QR' AFTER code;
ALTER TABLE man_equipment ADD UNIQUE KEY uk_man_equipment_asset_code (asset_code);
