-- ============================================================================
--  MÓDULO ENFERMAGEM (enf_) — Busca Fonada da CCIH
--
--  Vigilância epidemiológica pós-alta de Infecção de Sítio Cirúrgico (ISC).
--  A enfermagem liga para o paciente operado depois da alta e registra o que
--  ele relata; a CCIH classifica. Substitui a planilha BUSCA_FONADA_CCIH.
--
--  Regras que a estrutura carrega (base ANVISA — ver aba REFERÊNCIAS da
--  planilha; NT 03/2026):
--    • vigilância de 30 dias para toda cirurgia;
--    • 90 dias quando há PRÓTESE/implante (mama), com ligações extras aos
--      60 e aos 90 dias;
--    • a infecção é contada no MÊS DA CIRURGIA (por isso os indicadores do
--      relatório agrupam por data_cirurgia, não pela data do contato).
--
--  MODELAGEM
--    enf_cirurgias   — uma linha por CIRURGIA (não por paciente: o mesmo
--                      paciente operado duas vezes gera duas linhas). Guarda
--                      o desfecho do contato de 30 dias (questionário) e os
--                      contatos de 60/90 dias, além da avaliação da CCIH.
--    enf_tentativas  — cada ligação/tentativa vira uma linha (a planilha
--                      tinha 3 colunas fixas; aqui não há limite e fica o
--                      histórico). O STATUS e o ALERTA são CALCULADOS a
--                      partir destes dados no PHP (lib.php), nunca digitados.
--    enf_procedimentos / enf_medicos — listas dos menus, editáveis pela CCIH
--                      (equivalem à aba LISTAS).
--
--  Dados de SAÚDE são sigilosos (LGPD): o acesso é por micropermissão
--  (ccih.*) e o módulo não expõe nada fora dele.
--
--  Idempotente (CREATE TABLE IF NOT EXISTS). MySQL 5.7+ / MariaDB 10.3+.
-- ============================================================================

SET NAMES utf8mb4;

-- ---- Listas dos menus (aba LISTAS) ----------------------------------------

-- Procedimentos com o padrão de prótese/prazo: escolher "MAMOPLASTIA DE
-- AUMENTO (PRÓTESE)" já sugere prótese=Sim e 90 dias de vigilância.
CREATE TABLE IF NOT EXISTS enf_procedimentos (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome           VARCHAR(200) NOT NULL,
    protese_padrao TINYINT(1)   NOT NULL DEFAULT 0,
    prazo_padrao   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    ativo          TINYINT(1)   NOT NULL DEFAULT 1,
    ordem          INT          NOT NULL DEFAULT 0,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enf_proc_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Médicos/cirurgiões: lista opcional para o campo (o cadastro também aceita
-- digitar um nome novo, que passa a aparecer na lista de sugestões).
CREATE TABLE IF NOT EXISTS enf_medicos (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(160) NOT NULL,
    ativo      TINYINT(1)   NOT NULL DEFAULT 1,
    ordem      INT          NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_enf_med_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Cirurgias sob vigilância (aba PACIENTES) -----------------------------
CREATE TABLE IF NOT EXISTS enf_cirurgias (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    -- Identificação da cirurgia (você preenche)
    data_cirurgia   DATE         NOT NULL,
    medico          VARCHAR(160) NOT NULL,
    paciente        VARCHAR(200) NOT NULL,
    celular         VARCHAR(20)  NULL,
    email           VARCHAR(190) NULL,
    procedimento    VARCHAR(200) NOT NULL,
    protese         TINYINT(1)   NOT NULL DEFAULT 0,

    -- Prazo de vigilância em dias (30 padrão, 90 com prótese). Derivado no
    -- cadastro e gravado para o relatório não depender de recálculo.
    prazo_dias      SMALLINT UNSIGNED NOT NULL DEFAULT 30,

    -- Desfecho do CONTATO DE 30 DIAS (questionário do ROTEIRO). NULL enquanto
    -- não se conseguiu falar com o paciente. secrecao/retorno têm menu próprio;
    -- os demais sintomas são Sim/Não (1/0), NULL = não perguntado.
    contato_data       DATE NULL,
    contato_resultado  VARCHAR(60) NULL,
    febre           TINYINT(1) NULL,
    vermelhidao     TINYINT(1) NULL,
    dor             TINYINT(1) NULL,
    secrecao        ENUM('nao','clara','pus') NULL,
    ferida_abriu    TINYINT(1) NULL,
    antibiotico     TINYINT(1) NULL,
    retorno_medico  ENUM('nao','rotina','extra','reinternacao','reoperacao') NULL,
    observacoes     TEXT NULL,

    -- Retornos de 60 e 90 dias (só cirurgias com prótese)
    contato60_data     DATE NULL,
    contato60_resultado VARCHAR(60) NULL,
    contato60_sinais   TINYINT(1) NULL,
    contato90_data     DATE NULL,
    contato90_resultado VARCHAR(60) NULL,
    contato90_sinais   TINYINT(1) NULL,

    -- Avaliação da CCIH (só a enfermeira/médico da CCIH preenche)
    classificacao   ENUM('sem_infeccao','em_investigacao','isc_superficial',
                         'isc_profunda','isc_orgao','outra') NULL,
    data_diagnostico DATE NULL,
    notificado      ENUM('sim','nao','na') NULL,
    responsavel_ccih VARCHAR(160) NULL,

    created_by      INT UNSIGNED NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    -- O relatório agrupa por mês da cirurgia; o painel filtra por prótese.
    KEY idx_enf_cir_data (data_cirurgia),
    KEY idx_enf_cir_protese (protese, data_cirurgia),
    KEY idx_enf_cir_paciente (paciente),
    CONSTRAINT fk_enf_cir_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Tentativas de ligação (aba PACIENTES, colunas de tentativa) ----------
-- Cada dígito é uma linha. 'fase' diz a qual janela pertence (30/60/90 dias).
-- 'sucesso' = de fato falou com o paciente/responsável (ATENDEU ou respondeu
-- por WhatsApp/e-mail); é o que faz o STATUS virar "realizado".
CREATE TABLE IF NOT EXISTS enf_tentativas (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cirurgia_id BIGINT UNSIGNED NOT NULL,
    fase        ENUM('d30','d60','d90') NOT NULL DEFAULT 'd30',
    data        DATE NOT NULL,
    resultado   VARCHAR(60) NOT NULL,
    sucesso     TINYINT(1) NOT NULL DEFAULT 0,
    observacao  VARCHAR(500) NULL,
    created_by  INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_enf_tent_cir FOREIGN KEY (cirurgia_id) REFERENCES enf_cirurgias (id) ON DELETE CASCADE,
    CONSTRAINT fk_enf_tent_user FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL,
    KEY idx_enf_tent_cir (cirurgia_id, fase, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Seed dos procedimentos (aba LISTAS) ----------------------------------
-- Procedimentos com prótese já entram com 90 dias de vigilância. INSERT
-- IGNORE para ser idempotente e não sobrescrever ajustes da CCIH.
INSERT IGNORE INTO enf_procedimentos (nome, protese_padrao, prazo_padrao, ordem) VALUES
    ('MAMOPLASTIA DE AUMENTO (PRÓTESE)', 1, 90, 1),
    ('MASTOPEXIA',                       0, 30, 2),
    ('MASTOPEXIA COM PRÓTESE',           1, 90, 3),
    ('REDUÇÃO MAMÁRIA',                  0, 30, 4),
    ('RECONSTRUÇÃO MAMÁRIA',             1, 90, 5),
    ('TROCA DE PRÓTESE MAMÁRIA',         1, 90, 6),
    ('EXPLANTE DE PRÓTESE',              0, 30, 7),
    ('ABDOMINOPLASTIA',                  0, 30, 8),
    ('LIPOASPIRAÇÃO',                    0, 30, 9),
    ('LIPOABDOMINOPLASTIA',              0, 30, 10),
    ('LIPOENXERTIA GLÚTEA',              0, 30, 11),
    ('RINOPLASTIA',                      0, 30, 12),
    ('BLEFAROPLASTIA',                   0, 30, 13),
    ('RITIDOPLASTIA (LIFTING FACIAL)',   0, 30, 14),
    ('OTOPLASTIA',                       0, 30, 15),
    ('BRAQUIOPLASTIA',                   0, 30, 16),
    ('CRUROPLASTIA (COXAS)',             0, 30, 17),
    ('GINECOMASTIA',                     0, 30, 18),
    ('CIRURGIA COMBINADA (VER DESCRIÇÃO)', 0, 30, 19),
    ('OUTRO (VER DESCRIÇÃO)',            0, 30, 20);
