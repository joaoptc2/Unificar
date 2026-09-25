<?php
/** ENFERMAGEM — painel: a fila de ligações do dia e os alertas. */

declare(strict_types=1);

use Core\DB;
use Core\Layout;

core_require('ccih.view');

$rows = DB::query('SELECT * FROM enf_cirurgias');
$mapaTent = enf_tentativas_mapa(array_map(fn ($r) => (int) $r['id'], $rows));

$cont = ['ligar' => 0, 'em_andamento' => 0, 'aguardando' => 0, 'nao_localizado' => 0, 'realizado' => 0];
$ligarHoje = [];   // status d30 = ligar
$emAndamento = [];
$vermelhos = [];   // alerta vermelho
$retornos = [];    // 60/90 vencidos (prótese)

foreach ($rows as $r) {
    $c = enf_derivar($r, $mapaTent[(int) $r['id']] ?? []);
    $cont[$c['_status']] = ($cont[$c['_status']] ?? 0) + 1;
    if ($c['_status'] === 'ligar')        { $ligarHoje[] = $c; }
    if ($c['_status'] === 'em_andamento') { $emAndamento[] = $c; }
    if ($c['_alerta'] === 'vermelho')     { $vermelhos[] = $c; }
    if ($c['_protese'] && ($c['_r60'] === 'vencido' || $c['_r90'] === 'vencido')) { $retornos[] = $c; }
}

$statusMeta = enf_status_meta();
$alertaMeta = enf_alerta_meta();
$linkPac = fn (array $c) => core_module_url('enfermagem', ['page' => 'paciente', 'id' => (int) $c['id']]);

$cards = [
    ['Ligar (30 dias)',   count($ligarHoje),        'bi-telephone-outbound', 'warning',   ['status' => 'ligar']],
    ['Em andamento',      $cont['em_andamento'],    'bi-telephone',          'info',      ['status' => 'em_andamento']],
    ['Aguardando 30 dias',$cont['aguardando'],      'bi-hourglass-split',    'primary',   ['status' => 'aguardando']],
    ['Avisar CCIH',       count($vermelhos),        'bi-exclamation-octagon','danger',    ['alerta' => 'vermelho']],
    ['Retornos vencidos', count($retornos),         'bi-shield-exclamation', 'dark',      ['protese' => '1']],
    ['Não localizados',   $cont['nao_localizado'],  'bi-question-circle',    'secondary', ['status' => 'nao_localizado']],
];

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-speedometer2 me-2"></i>Painel — Busca Fonada</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'roteiro']) ?>"><i class="bi bi-card-checklist me-1"></i>Roteiro</a>
        <?php if (core_can('ccih.manage')): ?>
            <a class="btn btn-primary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'editar']) ?>"><i class="bi bi-plus-lg me-1"></i>Nova cirurgia</a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 g-md-3 mb-3">
    <?php foreach ($cards as [$titulo, $n, $icone, $cor, $filtro]): ?>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="card text-decoration-none h-100 border-<?= $cor ?>-subtle" href="<?= core_module_url('enfermagem', array_merge(['page' => 'pacientes'], $filtro)) ?>">
            <div class="card-body text-center py-3">
                <i class="bi <?= $icone ?> fs-3 text-<?= $cor ?>"></i>
                <div class="fs-3 fw-bold lh-1 mt-1"><?= (int) $n ?></div>
                <div class="small text-muted"><?= $titulo ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- Fila de ligações do dia -->
    <div class="col-12 col-lg-7">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-telephone-outbound me-1"></i>Ligações do dia (30 dias)</span>
                <span class="badge text-bg-warning"><?= count($ligarHoje) ?></span>
            </div>
            <?php $fila = array_merge($ligarHoje, $emAndamento);
            usort($fila, fn ($a, $b) => strcmp($a['_ligar_a_partir'], $b['_ligar_a_partir']));
            if (!$fila): ?>
                <div class="card-body text-center text-muted py-5"><i class="bi bi-check2-circle fs-2 d-block mb-2 opacity-50"></i>Nenhuma ligação pendente. Tudo em dia.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach (array_slice($fila, 0, 30) as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center gap-2">
                    <div class="min-w-0">
                        <a href="<?= $linkPac($c) ?>" class="fw-semibold text-decoration-none text-truncate d-block"><?= core_e($c['paciente']) ?></a>
                        <div class="small text-muted">Operada em <?= enf_data_br($c['data_cirurgia']) ?> · <?= core_e($c['procedimento']) ?></div>
                    </div>
                    <div class="text-end flex-shrink-0">
                        <?php if ($c['celular']): ?><a class="btn btn-sm btn-outline-success py-0 px-2" href="tel:<?= core_e($c['celular']) ?>"><i class="bi bi-telephone"></i></a><?php endif; ?>
                        <span class="badge <?= $statusMeta[$c['_status']][1] ?>"><?= $statusMeta[$c['_status']][0] ?></span>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php if (count($fila) > 30): ?><div class="card-footer text-center small"><a href="<?= core_module_url('enfermagem', ['page' => 'pacientes', 'status' => 'ligar']) ?>">Ver todas</a></div><?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alertas e retornos -->
    <div class="col-12 col-lg-5">
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span class="text-danger"><i class="bi bi-exclamation-octagon me-1"></i>Avisar CCIH</span>
                <span class="badge text-bg-danger"><?= count($vermelhos) ?></span>
            </div>
            <?php if (!$vermelhos): ?>
                <div class="card-body text-center text-muted py-4 small">Nenhum alerta vermelho.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach (array_slice($vermelhos, 0, 10) as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="<?= $linkPac($c) ?>" class="text-decoration-none text-truncate"><?= core_e($c['paciente']) ?></a>
                    <span class="small text-muted"><?= enf_data_br($c['contato_data']) ?></span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>

        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <span><i class="bi bi-shield-exclamation me-1"></i>Retornos 60/90 vencidos</span>
                <span class="badge text-bg-dark"><?= count($retornos) ?></span>
            </div>
            <?php if (!$retornos): ?>
                <div class="card-body text-center text-muted py-4 small">Nenhum retorno de prótese vencido.</div>
            <?php else: ?>
            <ul class="list-group list-group-flush">
                <?php foreach (array_slice($retornos, 0, 10) as $c): ?>
                <li class="list-group-item d-flex justify-content-between align-items-center">
                    <a href="<?= $linkPac($c) ?>" class="text-decoration-none text-truncate"><?= core_e($c['paciente']) ?></a>
                    <span class="small">
                        <?php if ($c['_r60'] === 'vencido'): ?><span class="badge text-bg-warning">60d</span><?php endif; ?>
                        <?php if ($c['_r90'] === 'vencido'): ?><span class="badge text-bg-warning">90d</span><?php endif; ?>
                    </span>
                </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
Layout::render(['title' => 'Painel', 'content' => (string) ob_get_clean(), 'active' => 'painel']);
