-- ============================================================
-- Migração 003 — Suporte a LGPD (P2.3)
-- ============================================================

-- Consentimento LGPD na inscrição pública.
ALTER TABLE `candidates`
    ADD COLUMN `lgpd_consent_at` DATETIME NULL AFTER `in_talent_pool`,
    ADD COLUMN `lgpd_consent_ip` VARCHAR(45) NULL AFTER `lgpd_consent_at`;

-- Anonimização de funcionários (preserva histórico, remove PII).
ALTER TABLE `employees`
    ADD COLUMN `anonymized_at` DATETIME NULL AFTER `notes`;
