-- ============================================================================
--  018 — Assistente com IA: registro de uso e teto de custo
--
--  Idempotente.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- Uso do assistente ----------------------------------------------------
-- Uma linha por chamada. Serve a três propósitos, nesta ordem de importância:
--
--   1. TETO DE CUSTO: sem isto, um laço mal escrito ou um usuário curioso
--      geram uma conta que ninguém previu. A API não avisa; quem tem de somar
--      é o portal.
--   2. TRANSPARÊNCIA LGPD: registra QUE dado saiu do hospital — a função
--      usada e o tamanho — sem guardar o conteúdo. Guardar o texto enviado
--      seria criar uma segunda cópia do que se quer proteger.
--   3. Diagnóstico: quando a resposta não veio, o motivo fica aqui.
CREATE TABLE IF NOT EXISTS ia_uso (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NULL,
    funcao       VARCHAR(40)  NOT NULL,
    modelo       VARCHAR(60)  NOT NULL,
    tokens_in    INT UNSIGNED NOT NULL DEFAULT 0,
    tokens_out   INT UNSIGNED NOT NULL DEFAULT 0,
    -- Em milésimos de centavo: o custo de uma chamada curta é fração de
    -- centavo, e arredondar para centavo na gravação zeraria quase tudo e
    -- faria o teto nunca fechar.
    milicentavos INT UNSIGNED NOT NULL DEFAULT 0,
    -- Tamanho do que foi enviado, NÃO o conteúdo.
    chars_enviados INT UNSIGNED NOT NULL DEFAULT 0,
    ok           TINYINT(1)   NOT NULL DEFAULT 1,
    erro         VARCHAR(255) NULL,
    ms           INT UNSIGNED NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_ia_uso_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL,
    -- A consulta do teto é "quanto gastei neste mês".
    KEY idx_ia_uso_mes (created_at),
    KEY idx_ia_uso_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
