-- ============================================================================
--  016 — Meu espaço, etapa 2: solicitações entre pessoas e formulários
--
--  Mesmo conteúdo acrescentado a sql/modules/meu.sql, repetido aqui porque
--  sql/modules/*.sql só roda na instalação nova.
--
--  Idempotente.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- Solicitações entre pessoas -------------------------------------------
-- Duas pontas: quem pediu e quem recebeu. É a diferença central em relação às
-- três tabelas da primeira etapa, que tinham um dono só — e o motivo de as
-- consultas deste módulo deixarem de ser "WHERE user_id = eu" para virarem
-- "WHERE eu sou uma das duas pontas".
--
-- formulario_id preenchido = a solicitação nasceu de um formulário que o
-- destinatário publicou; as respostas ficam em meu_formulario_respostas.
CREATE TABLE IF NOT EXISTS meu_solicitacoes (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    remetente_id   INT UNSIGNED NOT NULL,
    destinatario_id INT UNSIGNED NOT NULL,
    formulario_id  BIGINT UNSIGNED NULL,
    titulo         VARCHAR(200) NOT NULL,
    mensagem       TEXT         NULL,
    prioridade     ENUM('baixa','normal','alta') NOT NULL DEFAULT 'normal',
    prazo          DATE         NULL,
    situacao       ENUM('pendente','aceita','recusada','concluida','cancelada')
                   NOT NULL DEFAULT 'pendente',
    resposta       TEXT         NULL,
    respondida_em  DATETIME     NULL,
    -- Solicitação aceita vira tarefa do destinatário; guardar o id fecha o
    -- círculo (concluir a tarefa pode concluir a solicitação).
    tarefa_id      BIGINT UNSIGNED NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_sol_de   FOREIGN KEY (remetente_id)    REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_meu_sol_para FOREIGN KEY (destinatario_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_meu_sol_tarefa FOREIGN KEY (tarefa_id) REFERENCES meu_tarefas (id) ON DELETE SET NULL,
    -- As duas consultas da tela: "o que me pediram" e "o que eu pedi".
    KEY idx_meu_sol_para (destinatario_id, situacao, created_at),
    KEY idx_meu_sol_de   (remetente_id, situacao, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Formulários de solicitação -------------------------------------------
-- Cada pessoa cria os seus: "pedido de material", "liberação de acesso".
-- Quem preenche gera uma solicitação para o dono do formulário.
CREATE TABLE IF NOT EXISTS meu_formularios (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    titulo      VARCHAR(200) NOT NULL,
    descricao   TEXT         NULL,
    ativo       TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_meu_form_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    KEY idx_meu_form_user (user_id, ativo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Os oito tipos são os MESMOS das pesquisas do RH, de propósito: o construtor
-- e o renderizador de resposta já existem lá e foram portados, não reescritos.
CREATE TABLE IF NOT EXISTS meu_formulario_campos (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    formulario_id BIGINT UNSIGNED NOT NULL,
    rotulo        VARCHAR(500) NOT NULL,
    tipo          ENUM('text','textarea','choice','multiple','yes_no','number','date','rating')
                  NOT NULL DEFAULT 'text',
    opcoes        LONGTEXT     NULL,
    obrigatorio   TINYINT(1)   NOT NULL DEFAULT 0,
    ajuda         VARCHAR(255) NULL,
    ordem         INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_meu_campo_form FOREIGN KEY (formulario_id) REFERENCES meu_formularios (id) ON DELETE CASCADE,
    KEY idx_meu_campo_form (formulario_id, ordem)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resposta presa à SOLICITAÇÃO, não a um "envio" separado: todo preenchimento
-- de formulário É uma solicitação ao dono. Uma tabela a menos e nenhum estado
-- órfão possível.
CREATE TABLE IF NOT EXISTS meu_formulario_respostas (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    solicitacao_id BIGINT UNSIGNED NOT NULL,
    campo_id       BIGINT UNSIGNED NOT NULL,
    -- Duas colunas como nas pesquisas do RH: número em nota/escala ordena e
    -- soma; texto guarda o resto.
    nota           INT          NULL,
    resposta       TEXT         NULL,
    CONSTRAINT fk_meu_resp_sol   FOREIGN KEY (solicitacao_id) REFERENCES meu_solicitacoes (id) ON DELETE CASCADE,
    CONSTRAINT fk_meu_resp_campo FOREIGN KEY (campo_id) REFERENCES meu_formulario_campos (id) ON DELETE CASCADE,
    UNIQUE KEY uq_meu_resp (solicitacao_id, campo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
