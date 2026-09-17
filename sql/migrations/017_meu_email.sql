-- ============================================================================
--  017 — Meu espaço, etapa 3: caixa de e-mail pessoal (IMAP)
--
--  Mesmo conteúdo acrescentado a sql/modules/meu.sql. Idempotente.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- Caixa de e-mail pessoal (IMAP) ---------------------------------------
-- NÃO EXISTE COLUNA DE SENHA, e isso é o ponto central desta tabela.
--
-- A senha de aplicativo do Zoho contorna a verificação em duas etapas e NUNCA
-- expira — nem quando a senha principal da conta é trocada. Guardá-la aqui
-- faria o hospital virar custodiante da chave da caixa de cada funcionário,
-- com três agravantes concretos neste código:
--
--   • MailSecret::hide() nunca falha: sem OpenSSL ela grava base64 e devolve
--     normalmente, ou seja, texto claro sem ninguém perceber;
--   • o ciphertext não tem AAD, então copiar a linha de um usuário para outro
--     no banco daria a ele a caixa alheia;
--   • banco e config.php (de onde sai a chave) moram no MESMO servidor: "só
--     vaza se os dois vazarem" é enganar a si mesmo, é uma leitura de arquivo.
--
-- Então aqui ficam só os dados de conexão. A senha é digitada uma vez por
-- sessão e vive na sessão — vazamento de banco e de backup rendem zero.
CREATE TABLE IF NOT EXISTS meu_email_contas (
    user_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    host        VARCHAR(190) NOT NULL DEFAULT 'imap.zoho.com',
    porta       SMALLINT UNSIGNED NOT NULL DEFAULT 993,
    seguranca   ENUM('ssl','starttls','nenhuma') NOT NULL DEFAULT 'ssl',
    usuario     VARCHAR(190) NOT NULL,
    caixa       VARCHAR(190) NOT NULL DEFAULT 'INBOX',
    -- Envio (responder). Mesma conta, porta diferente.
    smtp_host   VARCHAR(190) NOT NULL DEFAULT 'smtp.zoho.com',
    smtp_porta  SMALLINT UNSIGNED NOT NULL DEFAULT 465,
    smtp_seg    ENUM('ssl','tls','nenhuma') NOT NULL DEFAULT 'ssl',
    ativo       TINYINT(1)   NOT NULL DEFAULT 1,
    ultimo_ok   DATETIME     NULL,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_email_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
