-- ============================================================================
--  021 — RH: canal de denúncia anônimo e controle de vacinação
--
--  CANAL DE DENÚNCIA (rh_complaints / rh_complaint_messages)
--    A denúncia NÃO tem user_id, employee_id nem IP: o anonimato é garantido
--    pela estrutura, não por uma promessa de tela. O denunciante recebe um
--    PROTOCOLO e uma CHAVE; só o hash da chave é gravado (vazar o banco não
--    entrega a chave). created_at é truncado à hora para dificultar a
--    correlação com registros de acesso. A comissão (complaints.view/respond)
--    apura, conversa pelo protocolo (mensagens) e conclui.
--
--  VACINAÇÃO (rh_vaccines / rh_employee_vaccines / rh_vaccine_requests)
--    Catálogo editável (semeado com as vacinas do trabalhador da saúde —
--    NR-32, PNI e recomendações SBIm), doses por funcionário com validade,
--    dispensa/sorologia, comprovante privado, e solicitações de renovação
--    que fecham o ciclo RH → funcionário (comprovante) → RH (validação).
--
--  Idempotente (CREATE TABLE IF NOT EXISTS / INSERT IGNORE).
-- ============================================================================

SET NAMES utf8mb4;

-- ---- Canal de denúncia -----------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_complaints (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    protocol        VARCHAR(20)  NOT NULL,
    key_hash        CHAR(64)     NOT NULL COMMENT 'sha256 da chave de acompanhamento',
    category        ENUM('assedio_moral','assedio_sexual','discriminacao','fraude','seguranca','conduta','outro')
                    NOT NULL DEFAULT 'outro',
    subject         VARCHAR(200) NOT NULL,
    body            TEXT         NOT NULL,
    involved        VARCHAR(300) NULL COMMENT 'pessoas/setor envolvidos (texto livre)',
    occurred_at     DATE         NULL,
    location        VARCHAR(200) NULL,
    contact         VARCHAR(200) NULL COMMENT 'identificação VOLUNTÁRIA do denunciante',
    attachment_path VARCHAR(500) NULL,
    attachment_name VARCHAR(255) NULL,
    status          ENUM('nova','em_apuracao','concluida','arquivada') NOT NULL DEFAULT 'nova',
    response        TEXT         NULL COMMENT 'conclusão visível ao denunciante',
    internal_notes  TEXT         NULL COMMENT 'apuração interna (só a comissão)',
    handled_by      INT UNSIGNED NULL,
    responded_at    DATETIME     NULL,
    closed_at       DATETIME     NULL,
    created_at      DATETIME     NOT NULL,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rh_cmp_protocol (protocol),
    KEY idx_rh_cmp_status (status, created_at),
    CONSTRAINT fk_rh_cmp_handler FOREIGN KEY (handled_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Conversa pelo protocolo: a comissão pede detalhes, o denunciante responde
-- (sem login, pelo protocolo + chave). user_id só nas mensagens da comissão.
CREATE TABLE IF NOT EXISTS rh_complaint_messages (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    complaint_id INT UNSIGNED NOT NULL,
    author       ENUM('denunciante','comissao') NOT NULL,
    user_id      INT UNSIGNED NULL,
    body         TEXT NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rh_cmsg_cmp (complaint_id, created_at),
    CONSTRAINT fk_rh_cmsg_cmp  FOREIGN KEY (complaint_id) REFERENCES rh_complaints (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_cmsg_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Vacinação: catálogo ---------------------------------------------------
CREATE TABLE IF NOT EXISTS rh_vaccines (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `key`           VARCHAR(40)  NOT NULL,
    name            VARCHAR(120) NOT NULL,
    category        ENUM('obrigatoria','pni_rotina','recomendada') NOT NULL DEFAULT 'recomendada',
    target          VARCHAR(200) NULL COMMENT 'para quem (todos, suscetíveis, laboratório...)',
    doses_total     TINYINT UNSIGNED NOT NULL DEFAULT 1,
    schedule        VARCHAR(200) NULL COMMENT 'esquema em texto (ex.: 3 doses: 0, 1 e 6 meses)',
    next_dose_days  SMALLINT UNSIGNED NULL COMMENT 'prazo padrão até a próxima dose do esquema',
    booster_months  SMALLINT UNSIGNED NULL COMMENT 'validade/reforço; NULL = sem reforço',
    validity_rule   VARCHAR(255) NULL,
    serology        VARCHAR(120) NULL COMMENT 'sorologia associada (ex.: anti-HBs)',
    applies_to_all  TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = só aparece se registrada',
    notes           TEXT         NULL,
    sources         TEXT         NULL,
    active          TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order      INT          NOT NULL DEFAULT 0,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_rh_vac_key (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Vacinação: registros por funcionário ----------------------------------
-- kind: dose (aplicação), dispensa (contraindicação/imune/não se aplica),
-- sorologia (resultado de exame, ex.: anti-HBs reagente).
CREATE TABLE IF NOT EXISTS rh_employee_vaccines (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id           INT UNSIGNED NOT NULL,
    vaccine_id            INT UNSIGNED NOT NULL,
    kind                  ENUM('dose','dispensa','sorologia') NOT NULL DEFAULT 'dose',
    dose_number           TINYINT UNSIGNED NULL,
    dose_label            VARCHAR(40)  NULL COMMENT 'ex.: 1ª dose, Reforço, Anual',
    applied_at            DATE         NULL,
    valid_until           DATE         NULL COMMENT 'calculada pelo catálogo ou informada',
    batch                 VARCHAR(60)  NULL,
    manufacturer          VARCHAR(80)  NULL,
    result                VARCHAR(60)  NULL COMMENT 'sorologia: reagente / nao_reagente',
    reason                VARCHAR(200) NULL COMMENT 'dispensa: motivo',
    file_path             VARCHAR(500) NULL,
    file_original_name    VARCHAR(255) NULL,
    notes                 TEXT         NULL,
    verified              TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '0 = enviado pelo funcionário, aguarda o RH',
    verified_by           INT UNSIGNED NULL,
    submitted_by_employee TINYINT(1)   NOT NULL DEFAULT 0,
    alert_notified_at     DATETIME     NULL,
    expired_notified_at   DATETIME     NULL,
    created_by            INT UNSIGNED NULL,
    created_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rh_ev_emp (employee_id, vaccine_id, applied_at),
    KEY idx_rh_ev_valid (valid_until),
    CONSTRAINT fk_rh_ev_emp  FOREIGN KEY (employee_id) REFERENCES rh_employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_ev_vac  FOREIGN KEY (vaccine_id)  REFERENCES rh_vaccines (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_ev_user FOREIGN KEY (created_by)  REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Vacinação: solicitações de renovação / regularização -------------------
-- requested_by NULL = aberta automaticamente pelo cron (vencimento).
CREATE TABLE IF NOT EXISTS rh_vaccine_requests (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    employee_id         INT UNSIGNED NOT NULL,
    vaccine_id          INT UNSIGNED NOT NULL,
    kind                ENUM('renovacao','pendente') NOT NULL DEFAULT 'renovacao',
    requested_by        INT UNSIGNED NULL,
    due_date            DATE NULL,
    message             TEXT NULL,
    status              ENUM('aberta','enviada','concluida','cancelada') NOT NULL DEFAULT 'aberta',
    employee_vaccine_id INT UNSIGNED NULL COMMENT 'dose enviada pelo funcionário em resposta',
    employee_note       TEXT NULL,
    responded_at        DATETIME NULL,
    closed_by           INT UNSIGNED NULL,
    closed_at           DATETIME NULL,
    notified_at         DATETIME NULL,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_rh_vr_emp (employee_id, status),
    KEY idx_rh_vr_status (status, created_at),
    CONSTRAINT fk_rh_vr_emp FOREIGN KEY (employee_id) REFERENCES rh_employees (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_vr_vac FOREIGN KEY (vaccine_id)  REFERENCES rh_vaccines (id) ON DELETE CASCADE,
    CONSTRAINT fk_rh_vr_ev  FOREIGN KEY (employee_vaccine_id) REFERENCES rh_employee_vaccines (id) ON DELETE SET NULL,
    CONSTRAINT fk_rh_vr_req FOREIGN KEY (requested_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---- Seed do catálogo (trabalhador da saúde — Brasil) ----------------------
-- Base: NR-32 (32.2.4.17.1: tétano, difteria, hepatite B e as do PCMSO),
-- Calendário Nacional de Vacinação / Trabalhador da Saúde (PNI/MS), Manual
-- dos CRIE e Calendário SBIm ocupacional 2026/27. 'obrigatoria' = exigida
-- pela NR-32 ou indicada expressamente ao trabalhador da saúde pelo PNI;
-- 'pni_rotina' = calendário do adulto aplicável; 'recomendada' = SBIm.
-- applies_to_all = 0: só aparece quando registrada (depende do PCMSO/idade).
-- INSERT IGNORE: a CCIH/RH pode ajustar sem ser sobrescrita.
INSERT IGNORE INTO rh_vaccines (`key`, name, category, target, doses_total, schedule, next_dose_days, booster_months, validity_rule, serology, applies_to_all, notes, sources, sort_order) VALUES
('hepatite_b', 'Hepatite B', 'obrigatoria', 'Todos os trabalhadores de serviços de saúde (NR-32 32.2.4.17.1)', 3,
 '3 doses: 0, 1 e 6 meses (mínimo 30 dias entre 1ª e 2ª; 3ª ≥ 2 meses após a 2ª e ≥ 4 meses após a 1ª)', 30, NULL,
 'Esquema completo + anti-HBs ≥ 10 mUI/mL = imune, sem reforço. Anti-HBs não reagente: dose extra/2º esquema e nova sorologia; não respondedor após 6 doses = suscetível (conduta pós-exposição).',
 'Anti-HBs quantitativo 30–60 dias após a 3ª dose (até 6 meses)', 1,
 'Citada nominalmente pela NR-32 (empregador fornece e controla a eficácia — 32.2.4.17.3). Registre a sorologia como "Sorologia" nesta vacina.',
 'NR-32 32.2.4.17; Calendário Nacional de Vacinação (PNI/MS); Manual dos CRIE', 1),
('dt_dtpa', 'Difteria e tétano (dT / dTpa)', 'obrigatoria', 'Todos os trabalhadores de serviços de saúde (NR-32 32.2.4.17.1)', 3,
 '3 doses (0, 2 e 4 meses, se nunca vacinado; mínimo 30 dias) e reforço a cada 10 anos — dTpa preferida no reforço (SBIm; gratuita no SUS para maternidade/neonatal/pediatria)', 60, 120,
 'Vence 10 anos após a última dose/reforço (alerta a partir de 6 meses antes). Esquema de infância completo: só o reforço decenal.', NULL, 1,
 'Tétano e difteria citados nominalmente pela NR-32. Uma dose de dTpa substitui um dos reforços de dT.',
 'NR-32 32.2.4.17; Calendário Nacional de Vacinação (PNI/MS); SBIm ocupacional 2026/27', 2),
('triplice_viral', 'Tríplice viral (sarampo, caxumba, rubéola)', 'obrigatoria', 'Todos os profissionais de saúde, independentemente da idade (2 doses)', 2,
 '2 doses com intervalo mínimo de 30 dias (contam doses prévias documentadas)', 30, NULL,
 'Esquema de 2 doses completo = imune, sem reforço. Contraindicada em gestantes e imunodeprimidos (registrar dispensa).', NULL, 1,
 'Calendário do Trabalhador da Saúde (PNI): 2 doses comprovadas mesmo acima de 29 anos.',
 'Calendário Nacional de Vacinação — Trabalhador da Saúde (PNI/MS)', 3),
('varicela', 'Varicela (catapora)', 'obrigatoria', 'Profissionais de saúde SUSCETÍVEIS (sem história confiável da doença e sem 2 doses)', 2,
 '2 doses com intervalo de 1 a 3 meses (CRIE: 4–8 semanas)', 30, NULL,
 'Imune = história clínica confiável (registrar dispensa "já teve a doença"), 2 doses, ou sorologia IgG reagente. Vacina viva: contraindicada em gestantes/imunodeprimidos.',
 'IgG anti-VZV opcional para quem não lembra se teve a doença', 1,
 'Fornecida pelo CRIE aos profissionais suscetíveis. Importante em pediatria, oncologia, transplante, obstetrícia e UTI.',
 'Manual dos CRIE (MS); Calendário do Trabalhador da Saúde (PNI/MS)', 4),
('influenza', 'Influenza (gripe)', 'obrigatoria', 'Todos os trabalhadores de serviços de saúde (grupo prioritário da campanha anual)', 1,
 '1 dose por ano, com a composição da temporada (campanha nacional, em geral abril–junho)', NULL, 12,
 'Vence 12 meses após a dose (alerta 60 dias antes). Na campanha, a dose da temporada anterior conta como vencendo.', NULL, 1,
 'Não está nominalmente na NR-32, mas o MS a indica todo ano ao trabalhador da saúde e a NR-32 32.2.4.17.2 obriga a fornecer vacinas eficazes.',
 'Campanha Nacional de Vacinação contra a Influenza (MS); SBIm ocupacional 2026/27', 5),
('covid19', 'COVID-19 (vacina atualizada)', 'obrigatoria', 'Trabalhadores da saúde (grupo prioritário permanente do MS)', 1,
 '1 dose por ano com a vacina mais atual disponibilizada pelo MS (semestral só para subgrupos: ≥ 60 anos, imunocomprometidos, ILPI)', NULL, 12,
 'Vence 12 meses após a dose (alerta 60 dias antes). Para quem está no subgrupo semestral, informe a validade manualmente ao registrar.', NULL, 1,
 'Estratégia nacional 2025/2026: dose anual para trabalhadores da saúde; um instrutivo municipal lista o grupo como semestral — confirme com a CCIH/PCMSO.',
 'Estratégia de vacinação contra a COVID-19 2025/2026 (MS); SBIm ocupacional 2026/27', 6),
('febre_amarela', 'Febre amarela', 'pni_rotina', 'Residentes/trabalhadores em área com recomendação (MG incluída; ACRV ampliada a todo o país em 2025/26)', 1,
 'Dose única na vida (quem tomou a 1ª antes dos 5 anos precisa de uma 2ª)', NULL, NULL,
 'Dose única = válida para sempre (PNI, desde 2017). Vacina viva: ≥ 60 anos, gestantes e imunodeprimidos = avaliação médica/dispensa, não pendência.', NULL, 1,
 'Calendário nacional do adulto, não exigência da NR-32: a exigência para todos os funcionários cabe ao PCMSO.',
 'Nota Informativa 16/2025-CGARB/DEDT/SVSA/MS; Calendário Nacional de Vacinação (PNI/MS)', 7),
('hepatite_a', 'Hepatite A', 'recomendada', 'Profissionais de saúde (SBIm ocupacional) — ênfase em nutrição/lactário, lavanderia, higienização e pediatria', 2,
 '2 doses: 0 e 6 meses (intervalo de 6 a 12 meses; esquema interrompido não recomeça)', 180, NULL,
 'Esquema de 2 doses = protegido, sem reforço. Sorologia anti-HAV IgG reagente = imune (registrar como sorologia).',
 'Anti-HAV IgG (total) opcional antes de vacinar nascidos antes de ~1990', 1,
 'Recomendação SBIm, não obrigatória por NR-32/PNI; pode entrar no PCMSO a critério do risco. Rede privada (CRIE em condições especiais).',
 'SBIm ocupacional 2026/27; SBIm adulto 2026/27', 8),
('meningo_acwy', 'Meningocócica ACWY', 'recomendada', 'Laboratório de microbiologia/CCIH, emergência e áreas de risco (SBIm; CRIE para microbiologistas)', 1,
 '1 dose; reforço a cada 5 anos enquanto persistir o risco', NULL, 60,
 'Vence 5 anos após a dose, enquanto houver exposição (alerta 6 meses antes).', NULL, 0,
 'Recomendação SBIm ocupacional; o Manual dos CRIE oferece ACWY a microbiologistas expostos ao meningococo.',
 'SBIm ocupacional 2026/27; Manual dos CRIE (MS)', 9),
('meningo_b', 'Meningocócica B', 'recomendada', 'Laboratório de microbiologia, emergência, UTI e pediatria (SBIm)', 2,
 '2 doses com intervalo de 1 a 2 meses', 30, NULL,
 'Esquema de 2 doses = protegido; sem reforço de rotina (reforço em risco contínuo a critério médico).', NULL, 0,
 'Recomendação SBIm ocupacional; rede privada. Incluir só para cargos com exposição ao meningococo, a critério do PCMSO.',
 'SBIm ocupacional 2026/27', 10),
('pneumococica', 'Pneumocócica (VPC20, ou VPC13/VPC15 + VPP23)', 'recomendada', 'Adultos com comorbidades de risco e a partir de 50–60 anos (SBIm)', 1,
 'VPC20: dose única. Alternativa: VPC13/VPC15 seguida de VPP23 após 6–12 meses (2ª VPP23 5 anos depois)', 180, NULL,
 'Conforme indicação médica; VPC20 em dose única não vence.', NULL, 0,
 'Não é vacina ocupacional: registrar quando houver indicação (comorbidade/idade).',
 'SBIm adulto 2026/27; SBIm idoso 2026/27', 11),
('hpv', 'HPV (nonavalente)', 'recomendada', 'Homens e mulheres de 9 a 45 anos (SBIm); acima de 45 a critério médico', 3,
 '≥ 15 anos: 3 doses — 0, 1–2 e 6 meses; 9–14 anos: 2 doses ou dose única (PNI)', 60, NULL,
 'Esquema completo = protegido, sem reforço.', NULL, 0,
 'Não é vacina ocupacional; entra como "outras vacinas", sem alerta de vencimento.',
 'SBIm adulto 2026/27; Calendário Técnico Nacional (PNI/MS)', 12),
('herpes_zoster', 'Herpes zóster (recombinante)', 'recomendada', 'A partir de 50 anos; imunocomprometidos a partir de 18 anos (SBIm)', 2,
 '2 doses com intervalo de 2 meses (aceitável 2 a 6 meses)', 60, NULL,
 'Esquema de 2 doses = protegido, sem reforço.', NULL, 0,
 'Rede privada; item opcional por faixa etária.',
 'SBIm adulto 2026/27; SBIm idoso 2026/27', 13),
('dengue', 'Dengue (Qdenga / Butantan-DV)', 'recomendada', 'Pessoas de 4 a 60 anos (SBIm; PNI por faixa etária e região)', 2,
 'Qdenga: 2 doses com intervalo de 3 meses. Butantan-DV: dose única (confirmar indicação vigente)', 90, NULL,
 'Esquema completo = protegido; sem regra automática de vencimento.', NULL, 0,
 'Incluída por completude do calendário SBIm; não obrigatória nem ocupacional.',
 'SBIm adulto 2026/27', 14);

-- ---- Canal de denúncia: liberar para quem já é funcionário -----------------
-- O preset 'funcionario' passou a incluir complaints.create, mas presets só
-- valem na criação do acesso. Quem já tinha a Minha Área (my.view) ganha a
-- permissão aqui — o canal tem de estar aberto a TODOS os funcionários, sem
-- depender de o administrador lembrar de conceder um a um. Idempotente
-- (INSERT IGNORE sobre a chave única subject/module/perm).
INSERT IGNORE INTO permission_grants (subject_type, subject_id, module_slug, perm_key, allowed, granted_by)
SELECT g.subject_type, g.subject_id, 'rh', 'complaints.create', 1, NULL
  FROM permission_grants g
 WHERE g.module_slug = 'rh' AND g.perm_key = 'my.view' AND g.allowed = 1;
