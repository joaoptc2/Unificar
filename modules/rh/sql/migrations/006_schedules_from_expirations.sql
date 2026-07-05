-- ============================================================
-- Migração 006 — Sincronização de vencimentos com a agenda
-- Replica todos os vencimentos existentes como compromissos
-- do tipo "vencimento" em `schedules`.
-- ============================================================

INSERT INTO `schedules`
    (`employee_id`, `title`, `description`, `event_date`, `event_type`, `expiration_id`, `color`, `created_by`, `created_at`)
SELECT
    ex.employee_id,
    CONCAT('Vencimento: ', ex.title) AS title,
    COALESCE(ex.description, ''),
    ex.expiry_date,
    'vencimento',
    ex.id,
    '#dc3545',
    ex.created_by,
    NOW()
FROM `expirations` ex
LEFT JOIN `schedules` s ON s.expiration_id = ex.id
WHERE s.id IS NULL;
