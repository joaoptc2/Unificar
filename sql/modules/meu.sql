-- ============================================================
-- MÓDULO MEU ESPAÇO (meu_) — organização pessoal de cada usuário
--   • meu_eventos : agenda própria (compromissos, plantões, lembretes);
--   • meu_notas   : bloco de notas, com fixação e cor;
--   • meu_tarefas : lista de tarefas com prazo, prioridade e situação.
--
-- Tudo aqui é PESSOAL: toda tabela tem user_id e toda consulta do módulo
-- filtra por ele. O que for compartilhado entre pessoas (solicitações e
-- formulários) vem em tabelas próprias, numa etapa seguinte, justamente
-- para não misturar o que é privado com o que é de outro.
--
-- FKs de usuário apontam para a tabela GLOBAL users(id) do núcleo, com
-- ON DELETE CASCADE: desligou a pessoa, o espaço dela sai junto.
-- Idempotente: CREATE TABLE IF NOT EXISTS.
-- Compatível com MySQL 5.7+ / MariaDB 10.3+.
-- ============================================================

SET NAMES utf8mb4;

-- ---- Agenda ---------------------------------------------------------------
-- dia_inteiro = 1 faz a hora de inicio/fim ser ignorada na exibição.
CREATE TABLE IF NOT EXISTS meu_eventos (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id       INT UNSIGNED NOT NULL,
    titulo        VARCHAR(200)  NOT NULL,
    descricao     TEXT          NULL,
    local         VARCHAR(200)  NULL,
    inicio        DATETIME      NOT NULL,
    fim           DATETIME      NOT NULL,
    dia_inteiro   TINYINT(1)    NOT NULL DEFAULT 0,
    cor           VARCHAR(7)    NULL,
    lembrete_min  SMALLINT UNSIGNED NULL,
    lembrete_em   DATETIME      NULL,
    created_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_ev_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    -- A consulta que manda é "meus eventos desta semana": user_id + inicio.
    KEY idx_meu_ev_user_inicio (user_id, inicio),
    -- O cron varre lembretes vencidos sem olhar usuário.
    KEY idx_meu_ev_lembrete (lembrete_em)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Notas ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS meu_notas (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    titulo      VARCHAR(200) NULL,
    conteudo    MEDIUMTEXT   NULL,
    cor         VARCHAR(7)   NULL,
    fixada      TINYINT(1)   NOT NULL DEFAULT 0,
    arquivada   TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_nt_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    -- Fixadas primeiro, depois as mais recentes: é a ordem da tela.
    KEY idx_meu_nt_user (user_id, arquivada, fixada, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Tarefas --------------------------------------------------------------
-- origem/origem_id preparam a etapa seguinte: uma solicitação aceita vira
-- tarefa, e a tarefa precisa saber de onde veio para fechar o círculo.
CREATE TABLE IF NOT EXISTS meu_tarefas (
    id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id      INT UNSIGNED NOT NULL,
    titulo       VARCHAR(200) NOT NULL,
    detalhe      TEXT         NULL,
    prazo        DATE         NULL,
    prioridade   ENUM('baixa','normal','alta') NOT NULL DEFAULT 'normal',
    situacao     ENUM('aberta','fazendo','concluida') NOT NULL DEFAULT 'aberta',
    concluida_em DATETIME     NULL,
    origem       VARCHAR(30)  NULL,
    origem_id    BIGINT UNSIGNED NULL,
    ordem        INT          NOT NULL DEFAULT 0,
    created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_tf_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    KEY idx_meu_tf_user (user_id, situacao, prazo),
    KEY idx_meu_tf_prazo (prazo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
