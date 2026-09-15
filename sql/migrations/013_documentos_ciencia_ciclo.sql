-- ============================================================================
--  013 — DOCUMENTOS: ciência por ciclo + histórico de modificações
--
--  Toda mudança de status (rascunho → revisão → aprovado, reprovação, nova
--  versão) REDEFINE a confirmação de leitura: quem deu ciência na versão
--  antiga precisa dar de novo. O que estava confirmado antes NÃO é apagado —
--  vira histórico, e é justamente isso que a auditoria de acreditação pede
--  ("quem leu o quê, quando, e em qual versão do documento").
--
--  Como: cada documento tem um ciclo de ciência (ack_cycle). Redefinir é
--  incrementar o ciclo; as confirmações antigas continuam na tabela, com o
--  número do ciclo, o status e a versão em que foram dadas.
--
--  Idempotente.
-- ============================================================================

SET NAMES utf8mb4;

-- 1. Ciclo de ciência corrente do documento.
ALTER TABLE `doc_documents`
    ADD COLUMN `ack_cycle` INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Ciclo de ciência: muda de status zera as confirmações' AFTER `current_version`;

ALTER TABLE `doc_documents`
    ADD COLUMN `ack_reset_at` DATETIME NULL
        COMMENT 'Quando as confirmações foram redefinidas pela última vez' AFTER `ack_cycle`;

-- 2. A confirmação passa a registrar em QUE ciclo/versão/status foi dada.
ALTER TABLE `doc_document_acknowledgments`
    ADD COLUMN `cycle` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `user_id`;

ALTER TABLE `doc_document_acknowledgments`
    ADD COLUMN `document_version` INT UNSIGNED NULL AFTER `cycle`;

ALTER TABLE `doc_document_acknowledgments`
    ADD COLUMN `document_status` VARCHAR(30) NULL AFTER `document_version`;

-- 3. A unicidade passa a ser por CICLO: o mesmo usuário confirma de novo a
--    cada redefinição. Sem isto, o INSERT IGNORE do ciclo novo cairia na
--    chave antiga e a segunda ciência seria silenciosamente descartada.
ALTER TABLE `doc_document_acknowledgments` DROP INDEX `uk_doc_ack`;

ALTER TABLE `doc_document_acknowledgments`
    ADD UNIQUE KEY `uk_doc_ack_cycle` (`document_id`, `user_id`, `cycle`);

-- 4. Histórico de modificações do documento (linha do tempo da tela).
--    Fica separado do audit_log do núcleo de propósito: aqui a consulta é
--    "tudo o que aconteceu com ESTE documento", com índice para isso.
CREATE TABLE IF NOT EXISTS `doc_document_history` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `document_id` INT UNSIGNED    NOT NULL,
    `event`       VARCHAR(40)     NOT NULL COMMENT 'created|updated|status|version|ack_reset|acknowledged|reviewed|deleted',
    `from_status` VARCHAR(30)     DEFAULT NULL,
    `to_status`   VARCHAR(30)     DEFAULT NULL,
    `version`     INT UNSIGNED    DEFAULT NULL,
    `cycle`       INT UNSIGNED    DEFAULT NULL,
    `summary`     VARCHAR(255)    DEFAULT NULL,
    `details`     TEXT            DEFAULT NULL COMMENT 'JSON com os campos alterados',
    `user_id`     INT UNSIGNED    DEFAULT NULL,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_doc_hist_doc` (`document_id`, `id`),
    CONSTRAINT `fk_doc_hist_doc` FOREIGN KEY (`document_id`)
        REFERENCES `doc_documents` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_doc_hist_user` FOREIGN KEY (`user_id`)
        REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. As ciências que já existem pertencem ao ciclo 1 (valor padrão da coluna)
--    e ao status atual do documento — o melhor que se sabe sobre elas.
UPDATE `doc_document_acknowledgments` a
  JOIN `doc_documents` d ON d.`id` = a.`document_id`
   SET a.`document_status` = d.`status`,
       a.`document_version` = d.`current_version`
 WHERE a.`document_status` IS NULL;
