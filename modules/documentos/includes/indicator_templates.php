<?php
/**
 * Biblioteca de Indicadores Hospitalares — Templates Prontos
 *
 * Referências:
 *  - ONA, JCI, ANVISA, ANAHP, IHI, ANS, NAGEH, AMIB, ISMP, NPUAP, OMS
 */

function indicator_templates() {
    return [
        // ── GESTÃO / PRODUÇÃO ────────────────────────────────────
        'taxa_ocupacao' => [
            'name' => 'Taxa de Ocupação Hospitalar',
            'category' => 'Gestão', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Percentual médio de leitos ocupados no período.',
            'formula' => '(pacientes_dia / leitos_dia) * 100',
            'variables' => [
                ['code'=>'pacientes_dia','label'=>'Pacientes-dia no período','unit'=>'pac-dia'],
                ['code'=>'leitos_dia',   'label'=>'Leitos-dia disponíveis','unit'=>'leito-dia'],
            ],
            'goal'=>85, 'goal_direction'=>'target', 'goal_tolerance'=>5,
            'benchmark'=>75, 'benchmark_source'=>'ANAHP — hospitais privados',
            'accreditation'=>'ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'tempo_medio_permanencia' => [
            'name' => 'Tempo Médio de Permanência',
            'category' => 'Gestão', 'type' => 'monthly', 'unit' => 'dias',
            'description' => 'Média de dias de internação por paciente.',
            'formula' => 'pacientes_dia / saidas',
            'variables' => [
                ['code'=>'pacientes_dia','label'=>'Pacientes-dia','unit'=>'pac-dia'],
                ['code'=>'saidas','label'=>'Saídas (altas + óbitos + transferências)','unit'=>'saídas'],
            ],
            'goal'=>4.5, 'goal_direction'=>'lower_better', 'goal_tolerance'=>0.5,
            'benchmark'=>5.2, 'benchmark_source'=>'SUS — média nacional',
            'accreditation'=>'ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'giro_leito' => [
            'name' => 'Índice de Renovação (Giro de Leito)',
            'category' => 'Gestão', 'type' => 'monthly', 'unit' => 'pacientes/leito',
            'description' => 'Número médio de pacientes por leito no período.',
            'formula' => 'saidas / leitos_operacionais',
            'variables' => [
                ['code'=>'saidas','label'=>'Saídas no período','unit'=>'saídas'],
                ['code'=>'leitos_operacionais','label'=>'Leitos operacionais','unit'=>'leitos'],
            ],
            'goal'=>6, 'goal_direction'=>'higher_better',
            'benchmark'=>5.5, 'benchmark_source'=>'ANAHP',
            'chart_type'=>'bar', 'decimal_places'=>2,
        ],

        // ── MORTALIDADE ─────────────────────────────────────────
        'taxa_mortalidade' => [
            'name' => 'Taxa de Mortalidade Institucional',
            'category' => 'Mortalidade', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Óbitos após 24h da admissão / total de saídas.',
            'formula' => '(obitos_apos_24h / saidas) * 100',
            'variables' => [
                ['code'=>'obitos_apos_24h','label'=>'Óbitos após 24h','unit'=>'óbitos'],
                ['code'=>'saidas','label'=>'Total de saídas','unit'=>'saídas'],
            ],
            'goal'=>3, 'goal_direction'=>'lower_better', 'goal_tolerance'=>0.5,
            'benchmark'=>4.2, 'benchmark_source'=>'CQH — Compromisso com Qualidade',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'mortalidade_uti' => [
            'name' => 'Taxa de Mortalidade em UTI',
            'category' => 'Mortalidade', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Óbitos em UTI / saídas da UTI.',
            'formula' => '(obitos_uti / saidas_uti) * 100',
            'variables' => [
                ['code'=>'obitos_uti','label'=>'Óbitos na UTI','unit'=>'óbitos'],
                ['code'=>'saidas_uti','label'=>'Saídas da UTI','unit'=>'saídas'],
            ],
            'goal'=>15, 'goal_direction'=>'lower_better',
            'benchmark'=>18, 'benchmark_source'=>'AMIB',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'readmissao_30d' => [
            'name' => 'Taxa de Readmissão em 30 dias',
            'category' => 'Mortalidade', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Pacientes readmitidos em ≤30 dias pela mesma causa.',
            'formula' => '(readmissoes_30d / altas) * 100',
            'variables' => [
                ['code'=>'readmissoes_30d','label'=>'Readmissões ≤30 dias','unit'=>'pacientes'],
                ['code'=>'altas','label'=>'Altas no período','unit'=>'pacientes'],
            ],
            'goal'=>5, 'goal_direction'=>'lower_better',
            'benchmark'=>10, 'benchmark_source'=>'CMS USA / ANS',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],

        // ── IRAS — INFECÇÕES HOSPITALARES ───────────────────────
        'densidade_pav' => [
            'name' => 'Densidade de Incidência de PAV',
            'category' => 'Controle de Infecção', 'type' => 'monthly', 'unit' => 'por 1000 vent-dia',
            'description' => 'Pneumonia Associada à Ventilação (obrigatório ANVISA).',
            'formula' => '(casos_pav / ventilador_dia) * 1000',
            'variables' => [
                ['code'=>'casos_pav','label'=>'Casos novos de PAV','unit'=>'casos'],
                ['code'=>'ventilador_dia','label'=>'Ventilador-dia','unit'=>'vent-dia'],
            ],
            'goal'=>10, 'goal_direction'=>'lower_better',
            'benchmark'=>15, 'benchmark_source'=>'ANVISA — Boletim IRAS',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'densidade_ipcs' => [
            'name' => 'Densidade de Incidência de IPCS-CVC',
            'category' => 'Controle de Infecção', 'type' => 'monthly', 'unit' => 'por 1000 CVC-dia',
            'description' => 'Infecção Primária de Corrente Sanguínea por Cateter Central.',
            'formula' => '(casos_ipcs / cvc_dia) * 1000',
            'variables' => [
                ['code'=>'casos_ipcs','label'=>'Casos de IPCS-CVC','unit'=>'casos'],
                ['code'=>'cvc_dia','label'=>'CVC-dia','unit'=>'CVC-dia'],
            ],
            'goal'=>3, 'goal_direction'=>'lower_better',
            'benchmark'=>4.5, 'benchmark_source'=>'ANVISA',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'densidade_itu' => [
            'name' => 'Densidade de Incidência de ITU-SVD',
            'category' => 'Controle de Infecção', 'type' => 'monthly', 'unit' => 'por 1000 SVD-dia',
            'description' => 'ITU associada a Sonda Vesical de Demora.',
            'formula' => '(casos_itu / svd_dia) * 1000',
            'variables' => [
                ['code'=>'casos_itu','label'=>'Casos de ITU-SVD','unit'=>'casos'],
                ['code'=>'svd_dia','label'=>'SVD-dia','unit'=>'SVD-dia'],
            ],
            'goal'=>5, 'goal_direction'=>'lower_better',
            'benchmark'=>6, 'benchmark_source'=>'ANVISA',
            'accreditation'=>'ANVISA, ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'taxa_sitio_cirurgico' => [
            'name' => 'Taxa de Infecção de Sítio Cirúrgico',
            'category' => 'Controle de Infecção', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Infecções em cirurgias limpas (acompanhadas ≤30 dias).',
            'formula' => '(infeccoes_sitio / cirurgias_limpas) * 100',
            'variables' => [
                ['code'=>'infeccoes_sitio','label'=>'Infecções identificadas','unit'=>'casos'],
                ['code'=>'cirurgias_limpas','label'=>'Cirurgias limpas','unit'=>'cirurgias'],
            ],
            'goal'=>2, 'goal_direction'=>'lower_better',
            'benchmark'=>3, 'benchmark_source'=>'ANVISA',
            'accreditation'=>'ANVISA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'higienizacao_maos' => [
            'name' => 'Taxa de Adesão à Higienização das Mãos',
            'category' => 'Controle de Infecção', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Observação direta dos 5 momentos da OMS.',
            'formula' => '(higienizacoes_corretas / oportunidades) * 100',
            'variables' => [
                ['code'=>'higienizacoes_corretas','label'=>'Higienizações corretas','unit'=>'obs'],
                ['code'=>'oportunidades','label'=>'Oportunidades observadas','unit'=>'obs'],
            ],
            'goal'=>80, 'goal_direction'=>'higher_better',
            'benchmark'=>60, 'benchmark_source'=>'OMS',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>1,
        ],

        // ── SEGURANÇA DO PACIENTE ───────────────────────────────
        'quedas_com_dano' => [
            'name' => 'Incidência de Quedas com Dano',
            'category' => 'Segurança do Paciente', 'type' => 'monthly', 'unit' => 'por 1000 pac-dia',
            'description' => 'Quedas com dano por 1000 pacientes-dia.',
            'formula' => '(quedas_com_dano / pacientes_dia) * 1000',
            'variables' => [
                ['code'=>'quedas_com_dano','label'=>'Quedas com dano','unit'=>'eventos'],
                ['code'=>'pacientes_dia','label'=>'Pacientes-dia','unit'=>'pac-dia'],
            ],
            'goal'=>1.5, 'goal_direction'=>'lower_better',
            'benchmark'=>2, 'benchmark_source'=>'NOTIVISA / ANVISA',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'lesao_por_pressao' => [
            'name' => 'Incidência de Lesão por Pressão',
            'category' => 'Segurança do Paciente', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Lesões estágio ≥2 adquiridas no hospital.',
            'formula' => '(novas_lesoes / pacientes_risco) * 100',
            'variables' => [
                ['code'=>'novas_lesoes','label'=>'Novas lesões (estágio ≥2)','unit'=>'lesões'],
                ['code'=>'pacientes_risco','label'=>'Pacientes avaliados com risco','unit'=>'pacientes'],
            ],
            'goal'=>2, 'goal_direction'=>'lower_better',
            'benchmark'=>4, 'benchmark_source'=>'NPUAP',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'extubacao_nao_planejada' => [
            'name' => 'Taxa de Extubação Não Planejada',
            'category' => 'Segurança do Paciente', 'type' => 'monthly', 'unit' => 'por 100 vent-dia',
            'description' => 'Extubações acidentais por 100 ventilador-dia.',
            'formula' => '(extubacoes_nao_planejadas / ventilador_dia) * 100',
            'variables' => [
                ['code'=>'extubacoes_nao_planejadas','label'=>'Extubações não planejadas','unit'=>'eventos'],
                ['code'=>'ventilador_dia','label'=>'Ventilador-dia','unit'=>'vent-dia'],
            ],
            'goal'=>1, 'goal_direction'=>'lower_better',
            'benchmark'=>2, 'benchmark_source'=>'AMIB',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'erro_medicacao' => [
            'name' => 'Taxa de Erro de Medicação',
            'category' => 'Segurança do Paciente', 'type' => 'monthly', 'unit' => 'por 1000 doses',
            'description' => 'Erros por 1000 doses dispensadas.',
            'formula' => '(erros_medicacao / doses_dispensadas) * 1000',
            'variables' => [
                ['code'=>'erros_medicacao','label'=>'Erros notificados','unit'=>'eventos'],
                ['code'=>'doses_dispensadas','label'=>'Doses dispensadas','unit'=>'doses'],
            ],
            'goal'=>3, 'goal_direction'=>'lower_better',
            'benchmark'=>5, 'benchmark_source'=>'ISMP Brasil',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'flebite' => [
            'name' => 'Incidência de Flebite',
            'category' => 'Segurança do Paciente', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Flebites associadas a cateter periférico.',
            'formula' => '(casos_flebite / cateteres_periferico) * 100',
            'variables' => [
                ['code'=>'casos_flebite','label'=>'Casos de flebite','unit'=>'casos'],
                ['code'=>'cateteres_periferico','label'=>'Cateteres inseridos','unit'=>'cateteres'],
            ],
            'goal'=>5, 'goal_direction'=>'lower_better',
            'benchmark'=>8, 'benchmark_source'=>'INS Brasil',
            'accreditation'=>'ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],

        // ── CENTRO CIRÚRGICO ────────────────────────────────────
        'cancelamento_cirurgia' => [
            'name' => 'Taxa de Cancelamento de Cirurgias',
            'category' => 'Centro Cirúrgico', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Cirurgias canceladas por motivo evitável após confirmação.',
            'formula' => '(cirurgias_canceladas / cirurgias_agendadas) * 100',
            'variables' => [
                ['code'=>'cirurgias_canceladas','label'=>'Cirurgias canceladas','unit'=>'cirurgias'],
                ['code'=>'cirurgias_agendadas','label'=>'Cirurgias agendadas','unit'=>'cirurgias'],
            ],
            'goal'=>5, 'goal_direction'=>'lower_better',
            'benchmark'=>8, 'benchmark_source'=>'ANAHP',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'checklist_cirurgico' => [
            'name' => 'Conformidade no Checklist Cirúrgico',
            'category' => 'Centro Cirúrgico', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Preenchimento completo do checklist OMS de cirurgia segura.',
            'formula' => '(checklists_completos / cirurgias_realizadas) * 100',
            'variables' => [
                ['code'=>'checklists_completos','label'=>'Checklists 100% preenchidos','unit'=>'checklists'],
                ['code'=>'cirurgias_realizadas','label'=>'Cirurgias realizadas','unit'=>'cirurgias'],
            ],
            'goal'=>95, 'goal_direction'=>'higher_better',
            'benchmark'=>85, 'benchmark_source'=>'OMS / ANVISA',
            'accreditation'=>'ANVISA, ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>1,
        ],

        // ── OBSTETRÍCIA ─────────────────────────────────────────
        'taxa_cesarea' => [
            'name' => 'Taxa de Cesárea',
            'category' => 'Obstetrícia', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Cesáreas sobre total de partos. OMS recomenda 10-15%.',
            'formula' => '(cesareas / total_partos) * 100',
            'variables' => [
                ['code'=>'cesareas','label'=>'Cesáreas realizadas','unit'=>'partos'],
                ['code'=>'total_partos','label'=>'Total de partos','unit'=>'partos'],
            ],
            'goal'=>25, 'goal_direction'=>'lower_better',
            'benchmark'=>57, 'benchmark_source'=>'ANS — média Brasil',
            'accreditation'=>'ANS, ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],

        // ── PRONTO-SOCORRO ──────────────────────────────────────
        'tempo_porta_medico' => [
            'name' => 'Tempo Porta-Médico no PS',
            'category' => 'Pronto-Socorro', 'type' => 'monthly', 'unit' => 'minutos',
            'description' => 'Tempo médio entre chegada e primeiro atendimento médico.',
            'formula' => 'soma_minutos_espera / atendimentos',
            'variables' => [
                ['code'=>'soma_minutos_espera','label'=>'Somatório dos tempos (min)','unit'=>'min'],
                ['code'=>'atendimentos','label'=>'Atendimentos realizados','unit'=>'pacientes'],
            ],
            'goal'=>30, 'goal_direction'=>'lower_better',
            'benchmark'=>45, 'benchmark_source'=>'ANAHP',
            'accreditation'=>'ONA, JCI', 'chart_type'=>'line', 'decimal_places'=>1,
        ],
        'taxa_abandono_ps' => [
            'name' => 'Taxa de Abandono no PS',
            'category' => 'Pronto-Socorro', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Pacientes que saem antes de serem atendidos.',
            'formula' => '(abandonos / classificados) * 100',
            'variables' => [
                ['code'=>'abandonos','label'=>'Pacientes que abandonaram','unit'=>'pacientes'],
                ['code'=>'classificados','label'=>'Pacientes classificados','unit'=>'pacientes'],
            ],
            'goal'=>2, 'goal_direction'=>'lower_better',
            'benchmark'=>5, 'benchmark_source'=>'ANAHP',
            'chart_type'=>'line', 'decimal_places'=>2,
        ],

        // ── SATISFAÇÃO ──────────────────────────────────────────
        'nps' => [
            'name' => 'NPS — Net Promoter Score',
            'category' => 'Satisfação', 'type' => 'monthly', 'unit' => 'pontos',
            'description' => '% promotores (9-10) menos % detratores (0-6).',
            'formula' => '((promotores - detratores) / total_respostas) * 100',
            'variables' => [
                ['code'=>'promotores','label'=>'Promotores (9-10)','unit'=>'respostas'],
                ['code'=>'detratores','label'=>'Detratores (0-6)','unit'=>'respostas'],
                ['code'=>'total_respostas','label'=>'Total de respostas','unit'=>'respostas'],
            ],
            'goal'=>70, 'goal_direction'=>'higher_better',
            'benchmark'=>50, 'benchmark_source'=>'ANAHP',
            'chart_type'=>'line', 'decimal_places'=>1,
        ],
        'taxa_reclamacao' => [
            'name' => 'Taxa de Reclamações',
            'category' => 'Satisfação', 'type' => 'monthly', 'unit' => 'por 1000 atend',
            'description' => 'Reclamações formais por 1000 atendimentos.',
            'formula' => '(reclamacoes / atendimentos) * 1000',
            'variables' => [
                ['code'=>'reclamacoes','label'=>'Reclamações registradas','unit'=>'reclamações'],
                ['code'=>'atendimentos','label'=>'Atendimentos','unit'=>'atendimentos'],
            ],
            'goal'=>2, 'goal_direction'=>'lower_better',
            'chart_type'=>'line', 'decimal_places'=>2,
        ],

        // ── FARMÁCIA ────────────────────────────────────────────
        'prescricao_eletronica' => [
            'name' => 'Taxa de Prescrição Eletrônica',
            'category' => 'Farmácia', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Prescrições eletrônicas sobre o total.',
            'formula' => '(prescricoes_eletronicas / total_prescricoes) * 100',
            'variables' => [
                ['code'=>'prescricoes_eletronicas','label'=>'Prescrições eletrônicas','unit'=>'prescrições'],
                ['code'=>'total_prescricoes','label'=>'Total de prescrições','unit'=>'prescrições'],
            ],
            'goal'=>98, 'goal_direction'=>'higher_better',
            'chart_type'=>'line', 'decimal_places'=>1,
        ],

        // ── FINANCEIRO ──────────────────────────────────────────
        'custo_paciente_dia' => [
            'name' => 'Custo por Paciente-dia',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'R$/pac-dia',
            'description' => 'Custo operacional total do período dividido pelos pacientes-dia produzidos.',
            'formula' => 'custo_total / pacientes_dia',
            'variables' => [
                ['code'=>'custo_total','label'=>'Custo operacional total','unit'=>'R$'],
                ['code'=>'pacientes_dia','label'=>'Pacientes-dia no período','unit'=>'pac-dia'],
            ],
            'goal'=>1500, 'goal_direction'=>'lower_better', 'goal_tolerance'=>100,
            'benchmark'=>1800, 'benchmark_source'=>'ANAHP — Observatório',
            'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'receita_por_leito' => [
            'name' => 'Receita por Leito Operacional',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'R$/leito',
            'description' => 'Receita líquida do período dividida pelo número de leitos operacionais.',
            'formula' => 'receita_liquida / leitos_operacionais',
            'variables' => [
                ['code'=>'receita_liquida','label'=>'Receita líquida','unit'=>'R$'],
                ['code'=>'leitos_operacionais','label'=>'Leitos operacionais','unit'=>'leitos'],
            ],
            'goal'=>45000, 'goal_direction'=>'higher_better',
            'benchmark'=>40000, 'benchmark_source'=>'ANAHP — Observatório',
            'chart_type'=>'bar', 'decimal_places'=>2,
        ],
        'margem_operacional' => [
            'name' => 'Margem Operacional (%)',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Resultado operacional (receita líquida menos custos e despesas operacionais) sobre a receita líquida.',
            'formula' => '((receita_liquida - custos_despesas) / receita_liquida) * 100',
            'variables' => [
                ['code'=>'receita_liquida','label'=>'Receita líquida','unit'=>'R$'],
                ['code'=>'custos_despesas','label'=>'Custos e despesas operacionais','unit'=>'R$'],
            ],
            'goal'=>10, 'goal_direction'=>'higher_better', 'goal_tolerance'=>2,
            'benchmark'=>8, 'benchmark_source'=>'ANAHP — Observatório',
            'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'taxa_glosa' => [
            'name' => 'Taxa de Glosa (%)',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Valor glosado pelas operadoras/convênios sobre o total faturado no período.',
            'formula' => '(valor_glosado / valor_faturado) * 100',
            'variables' => [
                ['code'=>'valor_glosado','label'=>'Valor glosado','unit'=>'R$'],
                ['code'=>'valor_faturado','label'=>'Valor faturado','unit'=>'R$'],
            ],
            'goal'=>3, 'goal_direction'=>'lower_better', 'goal_tolerance'=>1,
            'benchmark'=>4.5, 'benchmark_source'=>'ANAHP — Observatório',
            'accreditation'=>'ONA', 'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'prazo_medio_recebimento' => [
            'name' => 'Prazo Médio de Recebimento',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'dias',
            'description' => 'Dias médios entre o faturamento e o recebimento (contas a receber sobre a receita diária).',
            'formula' => '(contas_receber / receita_periodo) * dias_periodo',
            'variables' => [
                ['code'=>'contas_receber','label'=>'Saldo de contas a receber','unit'=>'R$'],
                ['code'=>'receita_periodo','label'=>'Receita faturada no período','unit'=>'R$'],
                ['code'=>'dias_periodo','label'=>'Dias do período','unit'=>'dias'],
            ],
            'goal'=>60, 'goal_direction'=>'lower_better', 'goal_tolerance'=>10,
            'benchmark'=>75, 'benchmark_source'=>'ANAHP — Observatório',
            'chart_type'=>'line', 'decimal_places'=>1,
        ],
        'custo_manutencao_equipamento' => [
            'name' => 'Custo de Manutenção por Equipamento',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'R$/equip',
            'description' => 'Gasto total com manutenção (preventiva + corretiva) dividido pelo parque de equipamentos.',
            'formula' => 'custo_manutencao / equipamentos',
            'variables' => [
                ['code'=>'custo_manutencao','label'=>'Custo total de manutenção','unit'=>'R$'],
                ['code'=>'equipamentos','label'=>'Equipamentos no parque','unit'=>'equip'],
            ],
            'goal'=>350, 'goal_direction'=>'lower_better', 'goal_tolerance'=>50,
            'chart_type'=>'bar', 'decimal_places'=>2,
        ],
        'custo_pessoal_receita' => [
            'name' => 'Custo com Pessoal / Receita (%)',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Participação da folha (salários + encargos + benefícios) na receita líquida.',
            'formula' => '(custo_pessoal / receita_liquida) * 100',
            'variables' => [
                ['code'=>'custo_pessoal','label'=>'Custo com pessoal (folha + encargos)','unit'=>'R$'],
                ['code'=>'receita_liquida','label'=>'Receita líquida','unit'=>'R$'],
            ],
            'goal'=>50, 'goal_direction'=>'lower_better', 'goal_tolerance'=>5,
            'benchmark'=>55, 'benchmark_source'=>'ANAHP — Observatório',
            'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'ticket_medio_atendimento' => [
            'name' => 'Ticket Médio por Atendimento',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'R$/atend',
            'description' => 'Receita bruta do período dividida pelo número de atendimentos realizados.',
            'formula' => 'receita_bruta / atendimentos',
            'variables' => [
                ['code'=>'receita_bruta','label'=>'Receita bruta','unit'=>'R$'],
                ['code'=>'atendimentos','label'=>'Atendimentos realizados','unit'=>'atendimentos'],
            ],
            'goal'=>800, 'goal_direction'=>'higher_better',
            'chart_type'=>'bar', 'decimal_places'=>2,
        ],
        'taxa_inadimplencia' => [
            'name' => 'Inadimplência (%)',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => '%',
            'description' => 'Valores vencidos há mais de 90 dias sobre o total de contas a receber.',
            'formula' => '(vencidos_90d / contas_receber) * 100',
            'variables' => [
                ['code'=>'vencidos_90d','label'=>'Valores vencidos > 90 dias','unit'=>'R$'],
                ['code'=>'contas_receber','label'=>'Total de contas a receber','unit'=>'R$'],
            ],
            'goal'=>2, 'goal_direction'=>'lower_better', 'goal_tolerance'=>1,
            'chart_type'=>'line', 'decimal_places'=>2,
        ],
        'ocupacao_ponto_equilibrio' => [
            'name' => 'Taxa de Ocupação × Ponto de Equilíbrio',
            'category' => 'Financeiro', 'type' => 'monthly', 'unit' => 'p.p.',
            'description' => 'Diferença (em pontos percentuais) entre a taxa de ocupação realizada e a ocupação de ponto de equilíbrio financeiro. Positivo = acima do equilíbrio.',
            'formula' => '((pacientes_dia / leitos_dia) * 100) - ocupacao_equilibrio',
            'variables' => [
                ['code'=>'pacientes_dia','label'=>'Pacientes-dia no período','unit'=>'pac-dia'],
                ['code'=>'leitos_dia','label'=>'Leitos-dia disponíveis','unit'=>'leito-dia'],
                ['code'=>'ocupacao_equilibrio','label'=>'Ocupação de ponto de equilíbrio','unit'=>'%'],
            ],
            'goal'=>5, 'goal_direction'=>'higher_better', 'goal_tolerance'=>2,
            'chart_type'=>'line', 'decimal_places'=>1,
        ],
    ];
}

function indicator_template_categories() {
    $cats = [];
    foreach (indicator_templates() as $t) $cats[$t['category']] = true;
    return array_keys($cats);
}

function indicator_template_find($slug) {
    return indicator_templates()[$slug] ?? null;
}
