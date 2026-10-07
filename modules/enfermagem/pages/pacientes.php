<?php
/** ENFERMAGEM — lista de cirurgias sob vigilância, com filtros e busca. */

declare(strict_types=1);

use Core\DB;
use Core\Layout;

core_require('ccih.view');

// ---- Filtros -------------------------------------------------------------
$busca   = trim((string) ($_GET['q'] ?? ''));
$fStatus = (string) ($_GET['status'] ?? '');
$fAlerta = (string) ($_GET['alerta'] ?? '');
$fProt   = (string) ($_GET['protese'] ?? '');
$fProc   = trim((string) ($_GET['proc'] ?? ''));
$fMed    = trim((string) ($_GET['medico'] ?? ''));
$fMes    = (int) ($_GET['mes'] ?? 0);
$fAno    = (int) ($_GET['ano'] ?? 0);
$pagina  = max(1, (int) ($_GET['p'] ?? 1));
$porPag  = 25;

// SQL só com os filtros "duros" (o status/alerta são calculados em PHP).
$where = [];
$args  = [];
if ($busca !== '') {
    $t = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca) . '%';
    $where[] = '(paciente LIKE ? OR medico LIKE ? OR celular LIKE ?)';
    array_push($args, $t, $t, $t);
}
if ($fProt === '1' || $fProt === '0') { $where[] = 'protese = ?'; $args[] = (int) $fProt; }
if ($fProc !== '') { $where[] = 'procedimento = ?'; $args[] = $fProc; }
if ($fMed !== '')  { $where[] = 'medico = ?'; $args[] = $fMed; }
if ($fMes >= 1 && $fMes <= 12 && $fAno >= 2000) {
    $ini = sprintf('%04d-%02d-01', $fAno, $fMes);
    $where[] = 'data_cirurgia BETWEEN ? AND ?';
    array_push($args, $ini, date('Y-m-t', strtotime($ini)));
}
$sql = 'SELECT * FROM enf_cirurgias';
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY data_cirurgia DESC, id DESC';

$rows = DB::query($sql, $args);

// Deriva status/alerta em bloco e aplica os filtros calculados.
$mapaTent = enf_tentativas_mapa(array_map(fn ($r) => (int) $r['id'], $rows));
$derivadas = [];
foreach ($rows as $r) {
    $d = enf_derivar($r, $mapaTent[(int) $r['id']] ?? []);
    if ($fStatus !== '' && $d['_status'] !== $fStatus) { continue; }
    if ($fAlerta !== '' && $d['_alerta'] !== $fAlerta) { continue; }
    $derivadas[] = $d;
}

$total  = count($derivadas);
$totPag = max(1, (int) ceil($total / $porPag));
$pagina = min($pagina, $totPag);
$slice  = array_slice($derivadas, ($pagina - 1) * $porPag, $porPag);

$statusMeta = enf_status_meta();
$alertaMeta = enf_alerta_meta();
$procs      = enf_procedimentos();
$medicos    = enf_medicos();
$anoAtual   = (int) date('Y');

$qbase = static function (array $extra) use ($busca, $fStatus, $fAlerta, $fProt, $fProc, $fMed, $fMes, $fAno): string {
    $q = array_filter([
        'page' => 'pacientes', 'q' => $busca, 'status' => $fStatus, 'alerta' => $fAlerta,
        'protese' => $fProt, 'proc' => $fProc, 'medico' => $fMed,
        'mes' => $fMes ?: '', 'ano' => $fAno ?: '',
    ], fn ($v) => $v !== '' && $v !== 0);
    return core_module_url('enfermagem', array_merge($q, $extra));
};

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-telephone me-2"></i>Pacientes <span class="text-muted fs-6">(<?= $total ?>)</span></h1>
    <?php if (core_can('ccih.manage')): ?>
        <a class="btn btn-primary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'editar']) ?>"><i class="bi bi-plus-lg me-1"></i>Nova cirurgia</a>
    <?php endif; ?>
</div>

<form method="get" class="card card-body mb-3">
    <input type="hidden" name="m" value="enfermagem">
    <input type="hidden" name="page" value="pacientes">
    <div class="row g-2 align-items-end">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Buscar</label>
            <input class="form-control form-control-sm" name="q" value="<?= core_e($busca) ?>" placeholder="Nome, médico ou celular">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Status</label>
            <select class="form-select form-select-sm" name="status">
                <option value="">Todos</option>
                <?php foreach ($statusMeta as $k => $m): ?>
                    <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= core_e($m[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Alerta</label>
            <select class="form-select form-select-sm" name="alerta">
                <option value="">Todos</option>
                <option value="vermelho" <?= $fAlerta === 'vermelho' ? 'selected' : '' ?>>Avisar CCIH</option>
                <option value="amarelo" <?= $fAlerta === 'amarelo' ? 'selected' : '' ?>>Observar</option>
                <option value="verde" <?= $fAlerta === 'verde' ? 'selected' : '' ?>>Sem queixas</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Prótese</label>
            <select class="form-select form-select-sm" name="protese">
                <option value="">Todas</option>
                <option value="1" <?= $fProt === '1' ? 'selected' : '' ?>>Com prótese</option>
                <option value="0" <?= $fProt === '0' ? 'selected' : '' ?>>Sem prótese</option>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1">Mês da cirurgia</label>
            <div class="input-group input-group-sm">
                <select class="form-select" name="mes">
                    <option value="">—</option>
                    <?php foreach (enf_meses() as $n => $nome): ?>
                        <option value="<?= $n ?>" <?= $fMes === $n ? 'selected' : '' ?>><?= ucfirst($nome) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="number" class="form-control" name="ano" style="max-width:5.5rem" placeholder="<?= $anoAtual ?>"
                       value="<?= $fAno ?: '' ?>" min="2000" max="<?= $anoAtual + 1 ?>">
            </div>
        </div>
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1">Procedimento</label>
            <select class="form-select form-select-sm" name="proc">
                <option value="">Todos</option>
                <?php foreach ($procs as $p): ?>
                    <option value="<?= core_e($p['nome']) ?>" <?= $fProc === $p['nome'] ? 'selected' : '' ?>><?= core_e($p['nome']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-8 col-md-4">
            <label class="form-label small mb-1">Médico</label>
            <select class="form-select form-select-sm" name="medico">
                <option value="">Todos</option>
                <?php foreach ($medicos as $m): ?>
                    <option value="<?= core_e($m) ?>" <?= $fMed === $m ? 'selected' : '' ?>><?= core_e($m) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-4 col-md-4 d-flex gap-2">
            <button class="btn btn-outline-primary btn-sm"><i class="bi bi-funnel me-1"></i>Filtrar</button>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('enfermagem', ['page' => 'pacientes']) ?>">Limpar</a>
        </div>
    </div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Paciente</th>
                    <th class="d-none d-md-table-cell">Cirurgia</th>
                    <th class="d-none d-lg-table-cell">Procedimento</th>
                    <th>Status</th>
                    <th>Alerta</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$slice): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhuma cirurgia encontrada com esses filtros.</td></tr>
                <?php else: foreach ($slice as $c):
                    $url = core_module_url('enfermagem', ['page' => 'paciente', 'id' => (int) $c['id']]); ?>
                    <tr style="cursor:pointer" onclick="location.href='<?= $url ?>'">
                        <td>
                            <a href="<?= $url ?>" class="fw-semibold text-decoration-none" onclick="event.stopPropagation()"><?= core_e($c['paciente']) ?></a>
                            <?php if ($c['_protese']): ?><i class="bi bi-shield-plus text-primary ms-1" title="Prótese — 90 dias"></i><?php endif; ?>
                            <div class="small text-muted d-md-none"><?= enf_data_br($c['data_cirurgia']) ?> · <?= core_e($c['medico']) ?></div>
                        </td>
                        <td class="d-none d-md-table-cell"><?= enf_data_br($c['data_cirurgia']) ?><div class="small text-muted"><?= core_e($c['medico']) ?></div></td>
                        <td class="d-none d-lg-table-cell small"><?= core_e($c['procedimento']) ?></td>
                        <td><span class="badge <?= $statusMeta[$c['_status']][1] ?>"><?= $statusMeta[$c['_status']][0] ?></span></td>
                        <td><span class="badge <?= $alertaMeta[$c['_alerta']][1] ?>"><?= $alertaMeta[$c['_alerta']][0] ?></span></td>
                    </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($totPag > 1): ?>
<nav class="mt-3"><ul class="pagination pagination-sm justify-content-center mb-0">
    <li class="page-item <?= $pagina <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= $qbase(['p' => $pagina - 1]) ?>">Anterior</a></li>
    <li class="page-item disabled"><span class="page-link"><?= $pagina ?> / <?= $totPag ?></span></li>
    <li class="page-item <?= $pagina >= $totPag ? 'disabled' : '' ?>"><a class="page-link" href="<?= $qbase(['p' => $pagina + 1]) ?>">Próxima</a></li>
</ul></nav>
<?php endif; ?>
<?php
Layout::render(['title' => 'Pacientes', 'content' => (string) ob_get_clean(), 'active' => 'pacientes']);
