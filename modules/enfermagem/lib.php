<?php
/**
 * ENFERMAGEM — funções compartilhadas pelas páginas (Busca Fonada da CCIH).
 *
 * Aqui mora a INTELIGÊNCIA que na planilha eram fórmulas: o prazo de
 * vigilância, as datas de ligar, o STATUS de 30 dias, o ALERTA (vermelho/
 * amarelo/verde) e os INDICADORES do relatório. Nada disso é digitado — é
 * derivado dos dados que a enfermagem registra, exatamente como as colunas
 * cinzas da planilha se calculavam sozinhas.
 *
 * Regra clínica do ALERTA (enf_alerta) e do STATUS (enf_status) está
 * documentada em cada função: é triagem para chamar atenção, não diagnóstico
 * — a palavra final é sempre da CCIH (classificacao).
 */

declare(strict_types=1);

use Core\Auth;
use Core\DB;

// ── Vocabulários dos menus (aba LISTAS) ─────────────────────────────────────
// Fixos porque são normativos (ANVISA). Procedimentos e médicos, que a CCIH
// acrescenta, ficam em tabela (enf_procedimentos / enf_medicos).

/** Resultado de cada tentativa de contato. 'sucesso' = de fato falou. */
function enf_resultados(): array
{
    return [
        'atendeu'    => ['ATENDEU', true],
        'whatsapp'   => ['RESPONDEU POR WHATSAPP/E-MAIL', true],
        'nao_atendeu'=> ['NÃO ATENDEU', false],
        'caixa'      => ['CAIXA POSTAL', false],
        'incorreto'  => ['NÚMERO INCORRETO', false],
        'desligou'   => ['PACIENTE DESLIGOU', false],
        'recusou'    => ['PACIENTE RECUSOU', false],
        'duplicado'  => ['PACIENTE DUPLICADO', false],
        'faleceu'    => ['PACIENTE FALECEU', false],
    ];
}

/** Um resultado de contato conta como "falou com o paciente"? */
function enf_resultado_sucesso(string $chave): bool
{
    return (bool) (enf_resultados()[$chave][1] ?? false);
}

function enf_secrecao_opcoes(): array
{
    return [
        'nao'   => 'NÃO',
        'clara' => 'SIM — CLARA/AMARELADA',
        'pus'   => 'SIM — COM PUS (grossa, esverdeada, cheiro ruim)',
    ];
}

function enf_retorno_opcoes(): array
{
    return [
        'nao'           => 'NÃO',
        'rotina'        => 'SIM — CONSULTA DE ROTINA',
        'extra'         => 'SIM — CONSULTA EXTRA POR QUEIXA',
        'reinternacao'  => 'SIM — REINTERNAÇÃO',
        'reoperacao'    => 'SIM — REOPERAÇÃO',
    ];
}

function enf_classificacoes(): array
{
    return [
        'sem_infeccao'    => 'SEM INFECÇÃO',
        'em_investigacao' => 'EM INVESTIGAÇÃO',
        'isc_superficial' => 'ISC INCISIONAL SUPERFICIAL',
        'isc_profunda'    => 'ISC INCISIONAL PROFUNDA',
        'isc_orgao'       => 'ISC ÓRGÃO/CAVIDADE',
        'outra'           => 'OUTRA COMPLICAÇÃO (NÃO INFECCIOSA)',
    ];
}

/** Classificações que contam como ISC confirmada (para a taxa de infecção). */
function enf_classificacoes_isc(): array
{
    return ['isc_superficial', 'isc_profunda', 'isc_orgao'];
}

function enf_notificacao_opcoes(): array
{
    return ['sim' => 'SIM', 'nao' => 'NÃO', 'na' => 'NÃO SE APLICA'];
}

/** Situações do STATUS de 30 dias, com rótulo e classe do selo (cor). */
function enf_status_meta(): array
{
    return [
        'aguardando'    => ['Aguardando 30 dias', 'text-bg-primary'],
        'ligar'         => ['Ligar',              'text-bg-warning'],
        'em_andamento'  => ['Em andamento',       'text-bg-info'],
        'realizado'     => ['Contato realizado',  'text-bg-success'],
        'nao_localizado'=> ['Não localizado',     'text-bg-secondary'],
    ];
}

/** Situações do ALERTA, com rótulo e classe do selo. */
function enf_alerta_meta(): array
{
    return [
        'vermelho' => ['AVISAR CCIH', 'text-bg-danger'],
        'amarelo'  => ['Observar',    'text-bg-warning'],
        'verde'    => ['Sem queixas', 'text-bg-success'],
        ''         => ['—',           'text-bg-light border'],
    ];
}

// ── Datas e prazos (colunas cinzas da planilha) ─────────────────────────────

/** Prazo de vigilância em dias a partir da prótese: 90 com, 30 sem. */
function enf_prazo_dias(bool $protese): int
{
    return $protese ? 90 : 30;
}

/** Soma dias a uma data 'Y-m-d'. */
function enf_add_dias(string $data, int $dias): string
{
    $ts = strtotime($data);
    return $ts ? date('Y-m-d', strtotime("+{$dias} days", $ts)) : $data;
}

/**
 * Enriquece a cirurgia com as datas e os estados derivados. Passe as
 * tentativas já carregadas para evitar consulta por linha em telas de lista
 * (enf_tentativas_mapa faz o carregamento em bloco).
 *
 * @param array      $c   linha de enf_cirurgias
 * @param array|null $tents tentativas desta cirurgia (todas as fases); se null,
 *                          são buscadas.
 */
function enf_derivar(array $c, ?array $tents = null): array
{
    $tents ??= DB::query('SELECT * FROM enf_tentativas WHERE cirurgia_id = ? ORDER BY data, id', [(int) $c['id']]);
    $data = (string) $c['data_cirurgia'];
    $protese = (int) $c['protese'] === 1;

    $c['_protese']      = $protese;
    $c['_ligar_a_partir'] = enf_add_dias($data, 30);           // 1ª ligação: 30 dias
    $c['_vigiar_ate']   = enf_add_dias($data, (int) $c['prazo_dias']);
    $c['_ligar_60']     = enf_add_dias($data, 60);
    $c['_ligar_90']     = enf_add_dias($data, 90);
    $c['_tentativas']   = $tents;

    $c['_status'] = enf_status($c, $tents);
    $c['_alerta'] = enf_alerta($c);
    // Retornos 60/90: só prótese; 'pendente' quando já venceu e não há contato.
    $hoje = date('Y-m-d');
    $c['_r60'] = $protese
        ? ($c['contato60_data'] ? 'realizado' : ($hoje >= $c['_ligar_60'] ? 'vencido' : 'aguardando'))
        : 'na';
    $c['_r90'] = $protese
        ? ($c['contato90_data'] ? 'realizado' : ($hoje >= $c['_ligar_90'] ? 'vencido' : 'aguardando'))
        : 'na';

    return $c;
}

/**
 * STATUS do contato de 30 dias, calculado (nunca digitado):
 *   realizado      → já falou com o paciente (tentativa de sucesso na fase d30);
 *   nao_localizado → 3+ tentativas d30, nenhuma de sucesso;
 *   em_andamento   → há tentativa d30 sem sucesso, ainda dentro das 3;
 *   ligar          → já passou dos 30 dias e ninguém ligou;
 *   aguardando     → ainda não fez 30 dias da cirurgia.
 */
function enf_status(array $c, array $tents): string
{
    if (!empty($c['contato_data'])) {
        return 'realizado';
    }
    $d30 = array_filter($tents, fn ($t) => ($t['fase'] ?? 'd30') === 'd30');
    foreach ($d30 as $t) {
        if ((int) $t['sucesso'] === 1) {
            return 'realizado';
        }
    }
    if (count($d30) >= 3) {
        return 'nao_localizado';
    }
    if (count($d30) > 0) {
        return 'em_andamento';
    }
    $ligarEm = enf_add_dias((string) $c['data_cirurgia'], 30);
    return date('Y-m-d') >= $ligarEm ? 'ligar' : 'aguardando';
}

/**
 * ALERTA a partir do questionário de 30 dias. É TRIAGEM, não diagnóstico.
 *
 *   '' (vazio)  → questionário ainda não preenchido (sem contato);
 *   verde       → nenhum sinal;
 *   amarelo     → um único sinal leve (ex.: secreção clara, consulta extra);
 *   vermelho    → qualquer sinal FORTE, ou dois ou mais sinais leves.
 *
 * Sinais FORTES (qualquer um → vermelho): secreção COM PUS, ferida abriu,
 * reinternação ou reoperação. Sinais LEVES: febre, vermelhidão, dor piorando,
 * secreção clara, uso de antibiótico após a alta, consulta extra por queixa.
 * A regra espelha o ROTEIRO ("OBSERVAR geralmente é secreção clara ou
 * consulta extra sem outros sinais") e as categorias do relatório.
 */
function enf_alerta(array $c): string
{
    // Sem contato/questionário: não há alerta ainda.
    if (empty($c['contato_data']) && $c['febre'] === null && $c['secrecao'] === null
        && $c['retorno_medico'] === null && $c['ferida_abriu'] === null) {
        return '';
    }

    $fortes = 0;
    if (($c['secrecao'] ?? '') === 'pus')      { $fortes++; }
    if ((int) ($c['ferida_abriu'] ?? 0) === 1) { $fortes++; }
    if (in_array($c['retorno_medico'] ?? '', ['reinternacao', 'reoperacao'], true)) { $fortes++; }

    $leves = 0;
    if ((int) ($c['febre'] ?? 0) === 1)        { $leves++; }
    if ((int) ($c['vermelhidao'] ?? 0) === 1)  { $leves++; }
    if ((int) ($c['dor'] ?? 0) === 1)          { $leves++; }
    if (($c['secrecao'] ?? '') === 'clara')    { $leves++; }
    if ((int) ($c['antibiotico'] ?? 0) === 1)  { $leves++; }
    if (($c['retorno_medico'] ?? '') === 'extra') { $leves++; }

    if ($fortes > 0 || $leves >= 2) {
        return 'vermelho';
    }
    if ($leves === 1) {
        return 'amarelo';
    }
    return 'verde';
}

// ── Acesso a dados ──────────────────────────────────────────────────────────

function enf_uid(): int
{
    return (int) Auth::id();
}

/** Carrega uma cirurgia por id (com derivações), ou null. */
function enf_cirurgia(int $id): ?array
{
    $c = DB::queryOne('SELECT * FROM enf_cirurgias WHERE id = ?', [$id]);
    return $c ? enf_derivar($c) : null;
}

/** Procedimentos ativos (ou todos, para a tela de listas). */
function enf_procedimentos(bool $somenteAtivos = true): array
{
    $sql = 'SELECT * FROM enf_procedimentos';
    if ($somenteAtivos) {
        $sql .= ' WHERE ativo = 1';
    }
    $sql .= ' ORDER BY ordem, nome';
    return DB::query($sql);
}

/** Médicos cadastrados + os já usados em cirurgias (sugestões do datalist). */
function enf_medicos(): array
{
    $rows = DB::query('SELECT nome FROM enf_medicos WHERE ativo = 1 ORDER BY ordem, nome');
    $nomes = array_column($rows, 'nome');
    $usados = DB::query('SELECT DISTINCT medico FROM enf_cirurgias ORDER BY medico');
    foreach ($usados as $u) {
        if ($u['medico'] !== '' && !in_array($u['medico'], $nomes, true)) {
            $nomes[] = $u['medico'];
        }
    }
    sort($nomes, SORT_NATURAL | SORT_FLAG_CASE);
    return $nomes;
}

/** Normaliza um celular para só dígitos (a planilha pedia "só números, DDD"). */
function enf_celular(string $bruto): string
{
    return preg_replace('/\D+/', '', $bruto);
}

// ── Indicadores do relatório (aba RESUMO) ───────────────────────────────────

/**
 * Calcula todos os indicadores do mês e do acumulado, agrupando por
 * data_cirurgia (regra ANVISA: a infecção é contada no mês da cirurgia).
 *
 * Devolve um array com as sete seções do relatório. Faz UMA leitura das
 * cirurgias do período e do acumulado e conta em PHP — as regras de status/
 * alerta são as mesmas do resto do módulo, sem duplicar SQL.
 */
function enf_indicadores(int $mes, int $ano): array
{
    $ini = sprintf('%04d-%02d-01', $ano, $mes);
    $fim = date('Y-m-t', strtotime($ini));

    $mesRows = DB::query(
        'SELECT * FROM enf_cirurgias WHERE data_cirurgia BETWEEN ? AND ? ORDER BY data_cirurgia',
        [$ini, $fim]
    );
    $todasRows = DB::query('SELECT * FROM enf_cirurgias');

    return [
        'mes'        => $mes,
        'ano'        => $ano,
        'periodo'    => [$ini, $fim],
        'do_mes'     => enf_contar($mesRows),
        'acumulado'  => enf_contar($todasRows),
    ];
}

/** Conta um conjunto de cirurgias nas categorias do relatório. */
function enf_contar(array $rows): array
{
    $z = [
        // 1. cirurgias
        'cirurgias' => 0, 'com_protese' => 0, 'sem_protese' => 0, 'sem_info_protese' => 0,
        // 2. contato 30 dias
        'realizado' => 0, 'em_andamento' => 0, 'ligar' => 0, 'aguardando' => 0, 'nao_localizado' => 0,
        // 3. tentativas
        'tent_total' => 0,
        // 4. relatos (sobre contatos realizados)
        'febre' => 0, 'vermelhidao' => 0, 'dor' => 0, 'sec_pus' => 0, 'sec_clara' => 0,
        'ferida' => 0, 'antibiotico' => 0, 'extra' => 0, 'reinternacao' => 0, 'reoperacao' => 0,
        // 5. alertas
        'a_vermelho' => 0, 'a_amarelo' => 0, 'a_verde' => 0,
        // 6. retornos 60/90 (prótese)
        'c60_ok' => 0, 'c60_venc' => 0, 'c60_sinais' => 0,
        'c90_ok' => 0, 'c90_venc' => 0, 'c90_sinais' => 0,
        // 7. classificação CCIH
        'cl_sem' => 0, 'cl_inv' => 0, 'cl_sup' => 0, 'cl_prof' => 0, 'cl_orgao' => 0, 'cl_outra' => 0,
        'isc_total' => 0, 'isc_protese' => 0, 'notificados' => 0, 'isc_nao_notif' => 0,
    ];

    // Carrega as tentativas de todas as cirurgias do conjunto de uma vez.
    $ids = array_map(fn ($r) => (int) $r['id'], $rows);
    $mapaTent = enf_tentativas_mapa($ids);

    foreach ($rows as $c) {
        $c = enf_derivar($c, $mapaTent[(int) $c['id']] ?? []);
        $z['cirurgias']++;
        if ($c['_protese']) { $z['com_protese']++; } else { $z['sem_protese']++; }
        // (protese é NOT NULL DEFAULT 0; "sem info" existiria só em import
        //  parcial — mantido por paridade com a planilha, sempre 0 aqui.)

        $z[$c['_status']]++;
        $z['tent_total'] += count($c['_tentativas']);

        if (!empty($c['contato_data'])) {
            if ((int) ($c['febre'] ?? 0) === 1)       { $z['febre']++; }
            if ((int) ($c['vermelhidao'] ?? 0) === 1) { $z['vermelhidao']++; }
            if ((int) ($c['dor'] ?? 0) === 1)         { $z['dor']++; }
            if (($c['secrecao'] ?? '') === 'pus')     { $z['sec_pus']++; }
            if (($c['secrecao'] ?? '') === 'clara')   { $z['sec_clara']++; }
            if ((int) ($c['ferida_abriu'] ?? 0) === 1){ $z['ferida']++; }
            if ((int) ($c['antibiotico'] ?? 0) === 1) { $z['antibiotico']++; }
            if (($c['retorno_medico'] ?? '') === 'extra')        { $z['extra']++; }
            if (($c['retorno_medico'] ?? '') === 'reinternacao') { $z['reinternacao']++; }
            if (($c['retorno_medico'] ?? '') === 'reoperacao')   { $z['reoperacao']++; }
        }

        switch ($c['_alerta']) {
            case 'vermelho': $z['a_vermelho']++; break;
            case 'amarelo':  $z['a_amarelo']++;  break;
            case 'verde':    $z['a_verde']++;    break;
        }

        if ($c['_protese']) {
            if ($c['_r60'] === 'realizado') { $z['c60_ok']++; }
            elseif ($c['_r60'] === 'vencido') { $z['c60_venc']++; }
            if ((int) ($c['contato60_sinais'] ?? 0) === 1) { $z['c60_sinais']++; }
            if ($c['_r90'] === 'realizado') { $z['c90_ok']++; }
            elseif ($c['_r90'] === 'vencido') { $z['c90_venc']++; }
            if ((int) ($c['contato90_sinais'] ?? 0) === 1) { $z['c90_sinais']++; }
        }

        switch ($c['classificacao'] ?? '') {
            case 'sem_infeccao':    $z['cl_sem']++;   break;
            case 'em_investigacao': $z['cl_inv']++;   break;
            case 'isc_superficial': $z['cl_sup']++;   break;
            case 'isc_profunda':    $z['cl_prof']++;  break;
            case 'isc_orgao':       $z['cl_orgao']++; break;
            case 'outra':           $z['cl_outra']++; break;
        }
        if (in_array($c['classificacao'] ?? '', enf_classificacoes_isc(), true)) {
            $z['isc_total']++;
            if ($c['_protese']) { $z['isc_protese']++; }
            if (($c['notificado'] ?? '') !== 'sim') { $z['isc_nao_notif']++; }
        }
        if (($c['notificado'] ?? '') === 'sim') { $z['notificados']++; }
    }

    // Indicadores derivados (percentuais)
    $z['pct_vigilancia'] = $z['cirurgias'] > 0 ? $z['realizado'] / $z['cirurgias'] : 0.0;
    $z['taxa_isc']       = $z['cirurgias'] > 0 ? $z['isc_total'] / $z['cirurgias'] : 0.0;
    $z['taxa_isc_protese'] = $z['com_protese'] > 0 ? $z['isc_protese'] / $z['com_protese'] : 0.0;

    return $z;
}

/**
 * Carrega as tentativas de várias cirurgias de uma vez, agrupadas por
 * cirurgia_id. Evita N+1 nas telas de lista e no relatório.
 *
 * @param int[] $ids
 * @return array<int, array<int, array>>
 */
function enf_tentativas_mapa(array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if ($ids === []) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = DB::query(
        "SELECT * FROM enf_tentativas WHERE cirurgia_id IN ($ph) ORDER BY data, id",
        $ids
    );
    $mapa = [];
    foreach ($rows as $r) {
        $mapa[(int) $r['cirurgia_id']][] = $r;
    }
    return $mapa;
}

/** Percentual formatado como na planilha (1 casa). */
function enf_pct(float $v): string
{
    return number_format($v * 100, 1, ',', '.') . '%';
}

/** Data 'Y-m-d' → 'd/m/Y' (vazio quando nula). */
function enf_data_br(?string $d): string
{
    if (!$d || $d === '0000-00-00') {
        return '';
    }
    $ts = strtotime($d);
    return $ts ? date('d/m/Y', $ts) : '';
}

/** Meses em português para os seletores do relatório. */
function enf_meses(): array
{
    return [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
}
