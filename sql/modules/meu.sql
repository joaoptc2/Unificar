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
