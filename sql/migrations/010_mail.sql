-- ============================================================
-- Migração 010 — E-mail: teste de entrega e fila confiável
--   • mail_queue ganha reserva (evita envio duplicado quando duas rodadas
--     do processador se sobrepõem), marcação de teste, via de entrega e
--     código do erro (a tela traduz o código em instrução);
--   • mail_tests guarda o histórico dos testes com a conversa SMTP já
--     sem segredos, para o admin comparar tentativas.
-- Idempotente (o runner tolera "coluna/índice já existe").
-- ============================================================

ALTER TABLE mail_queue ADD COLUMN reserved_by VARCHAR(40) NULL COMMENT 'Bilhete da rodada que reservou a linha' AFTER attempts;
ALTER TABLE mail_queue ADD COLUMN reserved_at DATETIME NULL COMMENT 'Quando foi reservada (expira em 10 min)' AFTER reserved_by;
ALTER TABLE mail_queue ADD COLUMN error_code VARCHAR(40) NULL COMMENT 'Core\\Mailer::ERR_*' AFTER last_error;
ALTER TABLE mail_queue ADD COLUMN delivery VARCHAR(10) NULL COMMENT 'smtp | mail (função do PHP)' AFTER error_code;
ALTER TABLE mail_queue ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Mensagem disparada pela tela de teste' AFTER delivery;
ALTER TABLE mail_queue ADD INDEX idx_mq_reserved (reserved_by);

-- Histórico dos testes de entrega (tabela própria: audit_log não é expurgada
-- e um transcript de 40 linhas por teste a faria crescer sem controle).
CREATE TABLE IF NOT EXISTS mail_tests (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    finished_at   DATETIME NULL,
    user_id       INT UNSIGNED NULL,
    user_name     VARCHAR(150) NULL,
    to_email      VARCHAR(190) NOT NULL,
    path          ENUM('smtp','mail','queue') NOT NULL DEFAULT 'smtp',
    result        ENUM('running','ok','fail') NOT NULL DEFAULT 'running',
    error_code    VARCHAR(40) NULL,
    error_message VARCHAR(500) NULL,
    duration_ms   INT UNSIGNED NULL,
    steps         TEXT NULL COMMENT 'JSON: etapa => ms',
    transcript    MEDIUMTEXT NULL COMMENT 'Conversa SMTP (segredos já removidos)',
    queue_id      BIGINT UNSIGNED NULL COMMENT 'Linha de mail_queue, quando o teste foi pela fila',
    ip            VARCHAR(45) NULL,
    KEY idx_mt_created (created_at),
    KEY idx_mt_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
