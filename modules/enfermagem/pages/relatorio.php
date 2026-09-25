<?php
/** ENFERMAGEM — relatório de indicadores da Busca Fonada (aba RESUMO). */

declare(strict_types=1);

use Core\Layout;

core_require('ccih.view');

$mes = (int) ($_GET['mes'] ?? date('n'));
$ano = (int) ($_GET['ano'] ?? date('Y'));
if ($mes < 1 || $mes > 12) { $mes = (int) date('n'); }
if ($ano < 2000 || $ano > (int) date('Y') + 1) { $ano = (int) date('Y'); }

$ind = enf_indicadores($mes, $ano);
$M = $ind['do_mes'];
$A = $ind['acumulado'];
$nomeMes = ucfirst(enf_meses()[$mes]);
$podeImprimir = core_can('ccih.export');

// Uma linha da tabela: rótulo, valor do mês, acumulado, e um % opcional do mês.
$linhas = [
    ['1. CIRURGIAS', null, null, null, true],
    ['Cirurgias cadastradas', $M['cirurgias'], $A['cirurgias'], null, false],
    ['Com prótese / implante', $M['com_protese'], $A['com_protese'], null, false],
    ['Sem prótese', $M['sem_protese'], $A['sem_protese'], null, false],

    ['2. CONTATO DE 30 DIAS', null, null, null, true],
    ['Contato realizado (falou com o paciente)', $M['realizado'], $A['realizado'], null, false],
    ['Em andamento (tentando)', $M['em_andamento'], $A['em_andamento'], null, false],
    ['Pendente — já pode ligar', $M['ligar'], $A['ligar'], null, false],
    ['Aguardando os 30 dias', $M['aguardando'], $A['aguardando'], null, false],
    ['Não localizado após 3 tentativas', $M['nao_localizado'], $A['nao_localizado'], null, false],
    ['% de vigilância pós-alta realizada (indicador ANVISA)', enf_pct($M['pct_vigilancia']), enf_pct($A['pct_vigilancia']), null, false, true],

    ['3. TENTATIVAS DE LIGAÇÃO', null, null, null, true],
    ['Total de ligações feitas', $M['tent_total'], $A['tent_total'], null, false],

    ['4. RELATOS DOS PACIENTES (sobre contatos realizados)', null, null, null, true],
    ['Febre', $M['febre'], $A['febre'], null, false],
    ['Vermelhidão / calor / inchaço', $M['vermelhidao'], $A['vermelhidao'], null, false],
    ['Dor piorando', $M['dor'], $A['dor'], null, false],
    ['Secreção COM PUS', $M['sec_pus'], $A['sec_pus'], null, false],
    ['Secreção clara/amarelada', $M['sec_clara'], $A['sec_clara'], null, false],
    ['Ferida abriu', $M['ferida'], $A['ferida'], null, false],
    ['Usou antibiótico após a alta', $M['antibiotico'], $A['antibiotico'], null, false],
    ['Consulta extra por queixa', $M['extra'], $A['extra'], null, false],
    ['Reinternação', $M['reinternacao'], $A['reinternacao'], null, false],
    ['Reoperação', $M['reoperacao'], $A['reoperacao'], null, false],

    ['5. ALERTAS', null, null, null, true],
    ['Avisar CCIH (suspeita de infecção)', $M['a_vermelho'], $A['a_vermelho'], null, false],
    ['Observar', $M['a_amarelo'], $A['a_amarelo'], null, false],
    ['Sem queixas', $M['a_verde'], $A['a_verde'], null, false],

    ['6. RETORNOS 60 / 90 DIAS (só prótese)', null, null, null, true],
    ['60 dias — contato realizado', $M['c60_ok'], $A['c60_ok'], null, false],
    ['60 dias — vencido e sem contato', $M['c60_venc'], $A['c60_venc'], null, false],
    ['60 dias — sinais de infecção relatados', $M['c60_sinais'], $A['c60_sinais'], null, false],
    ['90 dias — contato realizado', $M['c90_ok'], $A['c90_ok'], null, false],
    ['90 dias — vencido e sem contato', $M['c90_venc'], $A['c90_venc'], null, false],
    ['90 dias — sinais de infecção relatados', $M['c90_sinais'], $A['c90_sinais'], null, false],

    ['7. CLASSIFICAÇÃO FINAL DA CCIH (ANVISA)', null, null, null, true],
    ['Sem infecção', $M['cl_sem'], $A['cl_sem'], null, false],
    ['Em investigação', $M['cl_inv'], $A['cl_inv'], null, false],
    ['ISC incisional superficial', $M['cl_sup'], $A['cl_sup'], null, false],
    ['ISC incisional profunda', $M['cl_prof'], $A['cl_prof'], null, false],
    ['ISC órgão/cavidade', $M['cl_orgao'], $A['cl_orgao'], null, false],
    ['Outra complicação (não infecciosa)', $M['cl_outra'], $A['cl_outra'], null, false],
    ['TOTAL DE ISC CONFIRMADAS', $M['isc_total'], $A['isc_total'], null, false, false, true],
    ['Taxa de ISC (%) = ISC ÷ cirurgias', enf_pct($M['taxa_isc']), enf_pct($A['taxa_isc']), null, false, true],
    ['Taxa de ISC em cirurgias com prótese (%)', enf_pct($M['taxa_isc_protese']), enf_pct($A['taxa_isc_protese']), null, false, true],
    ['Casos notificados', $M['notificados'], $A['notificados'], null, false],
    ['ISC confirmadas ainda NÃO notificadas', $M['isc_nao_notif'], $A['isc_nao_notif'], null, false, false, true],
];

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-clipboard-data me-2"></i>Relatório de indicadores</h1>
    <div class="d-flex gap-2 d-print-none">
        <?php if ($podeImprimir): ?>
            <button class="btn btn-outline-secondary btn-sm" onclick="window.print()"><i class="bi bi-printer me-1"></i>Imprimir</button>
        <?php endif; ?>
    </div>
</div>

<form method="get" class="card card-body mb-3 d-print-none">
    <input type="hidden" name="m" value="enfermagem">
    <input type="hidden" name="page" value="relatorio">
    <div class="row g-2 align-items-end">
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Mês</label>
            <select class="form-select form-select-sm" name="mes">
                <?php foreach (enf_meses() as $n => $nm): ?>
                    <option value="<?= $n ?>" <?= $mes === $n ? 'selected' : '' ?>><?= ucfirst($nm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-3">
            <label class="form-label small mb-1">Ano</label>
            <input type="number" class="form-control form-control-sm" name="ano" value="<?= $ano ?>" min="2000" max="<?= (int) date('Y') + 1 ?>">
        </div>
        <div class="col-12 col-md-3">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Atualizar</button>
        </div>
    </div>
</form>

<div class="card">
    <div class="card-header">
        <strong><?= $nomeMes ?> de <?= $ano ?></strong>
        <span class="text-muted small">· período <?= enf_data_br($ind['periodo'][0]) ?> a <?= enf_data_br($ind['periodo'][1]) ?> (pela data da cirurgia)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-striped mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th style="min-width:16rem">Indicador</th>
                    <th class="text-end">Mês</th>
                    <th class="text-end">Acumulado</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($linhas as $l):
                    $ehSecao = $l[4] ?? false;
                    $destaque = ($l[6] ?? false);
                    if ($ehSecao): ?>
                        <tr class="table-secondary"><th colspan="3" class="small"><?= core_e($l[0]) ?></th></tr>
                    <?php else: ?>
                        <tr class="<?= $destaque ? 'fw-semibold' : '' ?>">
                            <td><?= core_e($l[0]) ?></td>
                            <td class="text-end"><?= is_int($l[1]) ? (int) $l[1] : core_e((string) $l[1]) ?></td>
                            <td class="text-end text-muted"><?= is_int($l[2]) ? (int) $l[2] : core_e((string) $l[2]) ?></td>
                        </tr>
                    <?php endif; ?>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer small text-muted">
        Tudo calculado automaticamente a partir das cirurgias cadastradas. A infecção é contada no mês da cirurgia (regra ANVISA);
        "vigilância pós-alta realizada" = contatos de 30 dias concluídos ÷ cirurgias do período.
    </div>
</div>
<?php
Layout::render(['title' => 'Relatório', 'content' => (string) ob_get_clean(), 'active' => 'relatorio']);
