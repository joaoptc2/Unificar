<?php
/**
 * HISTÓRICO DO EQUIPAMENTO — incluído por equipment.php (action=history)
 *
 * Dados do equipamento + código de identificação (barcode/QR) + linha do
 * tempo consolidada (OS com status/técnico/custo, calibrações, preventivas,
 * peças por OS, mudanças de status, cadastro/desativação) + totais
 * (custo acumulado, tempo parado, MTBF simples).
 *
 * Variáveis do contexto: $hid, $critLabels, $statusLabels, $typeLabels,
 * $prioLabels, $osStatusLabels.
 */
$id = (int)($_GET['id'] ?? 0);
$stmt = db()->prepare("SELECT e.*, s.name AS sector_name, c.name AS category_name FROM man_equipment e LEFT JOIN man_sectors s ON s.id=e.sector_id LEFT JOIN man_equipment_categories c ON c.id=e.category_id WHERE e.id=? AND e.hospital_id=?");
$stmt->execute([$id, $hid]);
$eq = $stmt->fetch();
if (!$eq) { flash('error', 'Equipamento não encontrado.'); redirect(url('equipment')); }

// Garante o código de identificação (equipamentos antigos)
if (empty($eq['asset_code'])) {
    try { $eq['asset_code'] = man_asset_code_ensure((int)$eq['id']); } catch (Throwable $ignored) {}
}
$assetCode = (string)($eq['asset_code'] ?? '');
$pageTitle = $eq['name'];

$freqLabels   = ['daily'=>'Diária','weekly'=>'Semanal','biweekly'=>'Quinzenal','monthly'=>'Mensal','quarterly'=>'Trimestral','semiannual'=>'Semestral','annual'=>'Anual'];
$resultLabels = ['conforme'=>'Conforme','nao_conforme'=>'Não conforme','conforme_com_ressalvas'=>'Com ressalvas'];

// ---------- Ordens de serviço (com técnico responsável) ----------
$st = db()->prepare("
    SELECT so.id, so.os_number, so.title, so.type, so.status, so.priority, so.created_at, so.started_at, so.completed_at,
           so.downtime_start, so.downtime_end, so.cost, so.labor_hours, so.labor_cost_per_hour, so.scheduled_date,
           u.name AS tech_name
    FROM man_service_orders so
    LEFT JOIN users u ON u.id = so.assigned_to
    WHERE so.equipment_id = ? AND so.hospital_id = ?
    ORDER BY so.created_at DESC
");
$st->execute([$id, $hid]);
$osList = $st->fetchAll();
$osIds  = array_map(fn($o) => (int)$o['id'], $osList);
$osById = [];
foreach ($osList as $o) { $osById[(int)$o['id']] = $o; }

// ---------- Peças por OS e mudanças de status (uma consulta cada) ----------
$partsByOs = [];
$statusEvents = [];
if ($osIds !== []) {
    $in = implode(',', array_fill(0, count($osIds), '?'));
    try {
        $st = db()->prepare("SELECT op.os_id, op.quantity, op.unit_cost, op.created_at, p.name AS part_name, p.code AS part_code FROM man_os_parts op JOIN man_parts p ON p.id = op.part_id WHERE op.os_id IN ({$in}) ORDER BY op.created_at");
        $st->execute($osIds);
        foreach ($st->fetchAll() as $r) { $partsByOs[(int)$r['os_id']][] = $r; }
    } catch (Throwable $ignored) {}
    try {
        $st = db()->prepare("SELECT os_id, action, details, user_name, created_at FROM man_os_history WHERE os_id IN ({$in}) AND action = 'Status alterado' ORDER BY created_at");
        $st->execute($osIds);
        $statusEvents = $st->fetchAll();
    } catch (Throwable $ignored) {}
}

// ---------- Calibrações ----------
$calibHistory = [];
try {
    $st = db()->prepare("SELECT id, calibration_date, next_date, result, responsible_body, responsible_person, cost, certificate_path FROM man_equipment_calibrations WHERE equipment_id=? AND hospital_id=? ORDER BY calibration_date DESC");
    $st->execute([$id, $hid]);
    $calibHistory = $st->fetchAll();
} catch (Throwable $ignored) {}

// ---------- Planos preventivos ----------
$maintPlans = db()->prepare("SELECT id, title, frequency, next_date, last_executed, status, created_at FROM man_maintenance_plans WHERE equipment_id=? AND hospital_id=? ORDER BY next_date");
$maintPlans->execute([$id, $hid]);
$maintPlans = $maintPlans->fetchAll();

// ---------- Totais ----------
$osCostOf = function (array $o) use ($partsByOs): float {
    $parts = 0.0;
    foreach ($partsByOs[(int)$o['id']] ?? [] as $p) { $parts += (float)$p['unit_cost'] * (int)$p['quantity']; }
    $labor = (float)($o['labor_hours'] ?? 0) * (float)($o['labor_cost_per_hour'] ?? 0);
    return max($parts + $labor, (float)($o['cost'] ?? 0));
};
$totalOsCost = 0.0; $totalPartsCost = 0.0; $downtimeSeconds = 0; $failures = 0; $osOpen = 0; $osCompleted = 0;
$now = time();
foreach ($osList as $o) {
    $totalOsCost += $osCostOf($o);
    foreach ($partsByOs[(int)$o['id']] ?? [] as $p) { $totalPartsCost += (float)$p['unit_cost'] * (int)$p['quantity']; }
    if ($o['type'] === 'corrective' && $o['status'] !== 'cancelled') { $failures++; }
    if (in_array($o['status'], ['open','in_progress','waiting_part'], true)) { $osOpen++; }
    if ($o['status'] === 'completed') { $osCompleted++; }
    if (!empty($o['downtime_start'])) {
        $start = strtotime($o['downtime_start']);
        $end   = !empty($o['downtime_end']) ? strtotime($o['downtime_end']) : (in_array($o['status'], ['open','in_progress','waiting_part'], true) ? $now : $start);
        if ($start && $end > $start) { $downtimeSeconds += $end - $start; }
    }
}
$totalCalibCost = 0.0;
foreach ($calibHistory as $c) { $totalCalibCost += (float)$c['cost']; }
$totalCost = $totalOsCost + $totalCalibCost;

// MTBF simples: (tempo em operação desde a instalação/aquisição/cadastro − tempo parado) / nº de falhas (OS corretivas)
$opStart = strtotime($eq['installation_date'] ?? '') ?: (strtotime($eq['acquisition_date'] ?? '') ?: strtotime($eq['created_at']));
$opEnd   = !empty($eq['deactivation_date']) ? (strtotime($eq['deactivation_date']) ?: $now) : $now;
$opSeconds = max(0, $opEnd - $opStart - $downtimeSeconds);
$mtbfDays  = $failures > 0 ? $opSeconds / 86400 / $failures : null;
$availability = ($opEnd - $opStart) > 0 ? max(0, 100 - $downtimeSeconds * 100 / ($opEnd - $opStart)) : null;

$fmtDuration = function (int $seconds): string {
    if ($seconds <= 0) return '0 h';
    $h = intdiv($seconds, 3600);
    $d = intdiv($h, 24);
    $h = $h % 24;
    $m = intdiv($seconds % 3600, 60);
    if ($d > 0) return $d . ' d ' . $h . ' h';
    return $h . ' h ' . sprintf('%02d', $m) . ' min';
};

// ---------- Linha do tempo consolidada ----------
$timeline = [];
$timeline[] = ['at' => $eq['created_at'], 'kind' => 'equipment', 'icon' => 'bi-plus-circle', 'color' => 'secondary', 'title' => 'Equipamento cadastrado', 'detail' => 'Código de identificação ' . man_asset_code_format($assetCode)];
if (!empty($eq['deactivation_date'])) {
    $timeline[] = ['at' => $eq['deactivation_date'], 'kind' => 'equipment', 'icon' => 'bi-x-circle', 'color' => 'danger', 'title' => 'Equipamento desativado', 'detail' => (string)($eq['deactivation_reason'] ?? '')];
}
foreach ($osList as $o) {
    $cost = $osCostOf($o);
    $timeline[] = [
        'at' => $o['created_at'], 'kind' => 'os', 'icon' => 'bi-clipboard-check', 'color' => $o['type'] === 'corrective' ? 'danger' : ($o['type'] === 'preventive' ? 'primary' : 'info'),
        'title' => 'OS ' . $o['os_number'] . ' — ' . ($typeLabels[$o['type']] ?? $o['type']) . ': ' . $o['title'],
        'detail' => 'Status: ' . ($osStatusLabels[$o['status']] ?? $o['status']) . ' · Prioridade: ' . ($prioLabels[$o['priority']] ?? $o['priority'])
                  . ' · Técnico: ' . ($o['tech_name'] ?? '—') . ($cost > 0 ? ' · Custo: R$ ' . number_format($cost, 2, ',', '.') : '')
                  . (!empty($o['completed_at']) ? ' · Concluída em ' . formatDate($o['completed_at'], 'd/m/Y H:i') : ''),
        'link' => core_can('service_orders.view') ? url('service-orders', ['action' => 'edit', 'id' => $o['id']]) : null,
        'status' => $o['status'],
    ];
    foreach ($partsByOs[(int)$o['id']] ?? [] as $p) {
        $timeline[] = [
            'at' => $p['created_at'], 'kind' => 'part', 'icon' => 'bi-box-seam', 'color' => 'warning',
            'title' => 'Peça utilizada na OS ' . $o['os_number'] . ': ' . $p['part_name'] . ($p['part_code'] ? ' (' . $p['part_code'] . ')' : ''),
            'detail' => (int)$p['quantity'] . ' un × R$ ' . number_format((float)$p['unit_cost'], 2, ',', '.') . ' = R$ ' . number_format((float)$p['unit_cost'] * (int)$p['quantity'], 2, ',', '.'),
        ];
    }
}
foreach ($statusEvents as $ev) {
    $o = $osById[(int)$ev['os_id']] ?? null;
    $timeline[] = [
        'at' => $ev['created_at'], 'kind' => 'status', 'icon' => 'bi-arrow-repeat', 'color' => 'secondary',
        'title' => 'OS ' . ($o['os_number'] ?? '#' . $ev['os_id']) . ': ' . $ev['action'],
        'detail' => trim((string)$ev['details']) . ($ev['user_name'] ? ' · por ' . $ev['user_name'] : ''),
    ];
}
foreach ($calibHistory as $c) {
    $timeline[] = [
        'at' => $c['calibration_date'] . ' 00:00:00', 'kind' => 'calibration', 'icon' => 'bi-rulers', 'color' => $c['result'] === 'conforme' ? 'success' : ($c['result'] === 'nao_conforme' ? 'danger' : 'warning'),
        'title' => 'Calibração — ' . ($resultLabels[$c['result']] ?? $c['result']),
        'detail' => 'Órgão: ' . ($c['responsible_body'] ?? '—') . ($c['responsible_person'] ? ' · Resp.: ' . $c['responsible_person'] : '') . ' · Próxima: ' . formatDate($c['next_date']) . ((float)$c['cost'] > 0 ? ' · Custo: R$ ' . number_format((float)$c['cost'], 2, ',', '.') : ''),
    ];
}
foreach ($maintPlans as $mp) {
    $timeline[] = [
        'at' => $mp['created_at'], 'kind' => 'plan', 'icon' => 'bi-tools', 'color' => 'primary',
        'title' => 'Plano preventivo criado: ' . $mp['title'],
        'detail' => 'Frequência: ' . ($freqLabels[$mp['frequency']] ?? $mp['frequency']) . ' · Próxima: ' . formatDate($mp['next_date']) . ' · ' . ($mp['status'] === 'active' ? 'Ativo' : 'Inativo'),
    ];
    if (!empty($mp['last_executed'])) {
        $timeline[] = ['at' => $mp['last_executed'], 'kind' => 'plan', 'icon' => 'bi-check2-circle', 'color' => 'primary', 'title' => 'Preventiva executada: ' . $mp['title'], 'detail' => 'Plano #' . $mp['id']];
    }
}
usort($timeline, fn($a, $b) => strcmp((string)$b['at'], (string)$a['at']));

$lookupUrl  = man_asset_code_url($assetCode);
$qrSvg      = $assetCode !== '' ? man_qr_svg($lookupUrl, 160) : '';
$barcodeSvg = $assetCode !== '' ? man_barcode_code128_svg($assetCode, 40, false) : '';
$kindLabels = ['equipment'=>'Equipamento','os'=>'OS','part'=>'Peças','status'=>'Status','calibration'=>'Calibração','plan'=>'Preventiva'];
?>

<div class="page-header">
    <h1><i class="bi bi-hdd-rack me-2"></i><?php echo e($eq['name']); ?> <span class="badge badge-<?php echo e($eq['status']); ?> align-middle fs-6"><?php echo e($statusLabels[$eq['status']] ?? $eq['status']); ?></span></h1>
    <div class="d-flex flex-wrap gap-2">
        <a href="<?php echo url('equipment', ['action'=>'label','id'=>$eq['id']]); ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-printer me-1"></i> Etiqueta</a>
        <?php if (core_can('equipment.edit')): ?>
        <a href="<?php echo url('equipment', ['action'=>'edit','id'=>$eq['id']]); ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
        <?php endif; ?>
        <?php if (core_can('service_orders.create')): ?>
        <a href="<?php echo url('service-orders', ['equipment_id'=>$eq['id']]); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clipboard-plus me-1"></i> Nova OS</a>
        <?php endif; ?>
        <a href="<?php echo url('equipment'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- DADOS DO EQUIPAMENTO -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i> Dados do equipamento</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-sm-4"><small class="text-muted d-block">Código de identificação</small><strong class="font-monospace fs-6"><?php echo e(man_asset_code_format($assetCode)); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Código patrimonial</small><strong><?php echo e($eq['code'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Nº Série</small><strong><?php echo e($eq['serial_number'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Fabricante</small><strong><?php echo e($eq['manufacturer'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Modelo</small><strong><?php echo e($eq['model'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Categoria</small><strong><?php echo e($eq['category_name'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Setor</small><strong><?php echo e($eq['sector_name'] ?? '—'); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Unidade</small><strong><?php echo e(manOrgName()); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Criticidade</small><span class="badge badge-<?php echo e($eq['criticality']); ?>"><?php echo e($critLabels[$eq['criticality']] ?? $eq['criticality']); ?></span></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Aquisição</small><strong><?php echo formatDate($eq['acquisition_date'] ?? ''); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Instalação</small><strong><?php echo formatDate($eq['installation_date'] ?? ''); ?></strong></div>
                    <div class="col-sm-4"><small class="text-muted d-block">Vida útil (anos)</small><strong><?php echo e($eq['useful_life_years'] ?? '—'); ?></strong></div>
                    <?php if (!empty($eq['deactivation_date'])): ?>
                    <div class="col-sm-4"><small class="text-muted d-block">Desativado em</small><strong><?php echo formatDate($eq['deactivation_date'], 'd/m/Y H:i'); ?></strong></div>
                    <div class="col-sm-8"><small class="text-muted d-block">Motivo da desativação</small><strong><?php echo e($eq['deactivation_reason'] ?? '—'); ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($eq['description']): ?>
                    <hr><small class="text-muted d-block mb-1">Descrição</small><p class="mb-0 small"><?php echo nl2br(e($eq['description'])); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- CÓDIGO / ETIQUETA -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm text-center h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-qr-code me-1"></i> Identificação</div>
            <div class="card-body d-flex flex-column align-items-center justify-content-center">
                <?php if ($assetCode !== ''): ?>
                    <div class="man-qr mb-2"><?php echo $qrSvg; ?></div>
                    <div class="man-barcode mb-1" style="max-width:240px;width:100%"><?php echo $barcodeSvg; ?></div>
                    <div class="font-monospace fw-bold fs-5"><?php echo e(man_asset_code_format($assetCode)); ?></div>
                    <p class="small text-muted mb-2">Escaneie o QR ou leia o código de barras para abrir esta página.</p>
                    <a href="<?php echo url('equipment', ['action'=>'label','id'=>$eq['id']]); ?>" class="btn btn-outline-primary btn-sm" target="_blank"><i class="bi bi-printer me-1"></i> Imprimir etiqueta</a>
                <?php else: ?>
                    <p class="text-muted">Código de identificação não gerado.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- TOTAIS -->
<div class="row g-3 mb-3">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <small class="text-muted d-block">Custo acumulado</small>
            <div class="fs-5 fw-bold">R$ <?php echo number_format($totalCost, 2, ',', '.'); ?></div>
            <small class="text-muted">OS: R$ <?php echo number_format($totalOsCost, 2, ',', '.'); ?> · Peças: R$ <?php echo number_format($totalPartsCost, 2, ',', '.'); ?> · Calibração: R$ <?php echo number_format($totalCalibCost, 2, ',', '.'); ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <small class="text-muted d-block">Tempo parado</small>
            <div class="fs-5 fw-bold"><?php echo e($fmtDuration($downtimeSeconds)); ?></div>
            <small class="text-muted">Disponibilidade: <?php echo $availability !== null ? number_format($availability, 1, ',', '.') . '%' : '—'; ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <small class="text-muted d-block">MTBF (simples)</small>
            <div class="fs-5 fw-bold"><?php echo $mtbfDays !== null ? number_format($mtbfDays, 1, ',', '.') . ' dias' : '—'; ?></div>
            <small class="text-muted"><?php echo $failures; ?> falha(s) corretiva(s) desde <?php echo date('d/m/Y', $opStart); ?></small>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
            <small class="text-muted d-block">Ordens de serviço</small>
            <div class="fs-5 fw-bold"><?php echo count($osList); ?></div>
            <small class="text-muted"><?php echo $osOpen; ?> aberta(s) · <?php echo $osCompleted; ?> concluída(s) · <?php echo count($calibHistory); ?> calibração(ões)</small>
        </div></div>
    </div>
</div>

<!-- LINHA DO TEMPO -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold"><i class="bi bi-clock-history me-1"></i> Linha do tempo (<?php echo count($timeline); ?>)</span>
        <div class="ms-auto d-flex flex-wrap gap-1" id="tlFilters">
            <button type="button" class="btn btn-sm btn-primary" data-kind="">Tudo</button>
            <?php foreach ($kindLabels as $k => $lbl): ?>
            <button type="button" class="btn btn-sm btn-outline-secondary" data-kind="<?php echo $k; ?>"><?php echo $lbl; ?></button>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($timeline)): ?>
            <p class="text-center text-muted py-3 mb-0">Nenhum evento registrado.</p>
        <?php else: ?>
        <ul class="man-timeline list-unstyled mb-0" id="tlList">
            <?php foreach ($timeline as $ev): ?>
            <li class="man-timeline-item" data-kind="<?php echo e($ev['kind']); ?>">
                <span class="man-timeline-dot bg-<?php echo e($ev['color']); ?>"><i class="bi <?php echo e($ev['icon']); ?>"></i></span>
                <div class="man-timeline-body">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <strong>
                            <?php if (!empty($ev['link'])): ?><a href="<?php echo e($ev['link']); ?>" class="text-decoration-none"><?php echo e($ev['title']); ?></a>
                            <?php else: ?><?php echo e($ev['title']); ?><?php endif; ?>
                            <?php if (!empty($ev['status'])): ?><span class="badge badge-<?php echo e($ev['status']); ?> ms-1"><?php echo e($osStatusLabels[$ev['status']] ?? $ev['status']); ?></span><?php endif; ?>
                        </strong>
                        <small class="text-muted text-nowrap"><?php echo formatDate($ev['at'], 'd/m/Y H:i'); ?></small>
                    </div>
                    <?php if ($ev['detail'] !== ''): ?><div class="small text-muted"><?php echo e($ev['detail']); ?></div><?php endif; ?>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>
</div>
<script>
(function () {
    var wrap = document.getElementById('tlFilters'), list = document.getElementById('tlList');
    if (!wrap || !list) return;
    wrap.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-kind]'); if (!b) return;
        var kind = b.getAttribute('data-kind');
        wrap.querySelectorAll('button').forEach(function (x) { x.className = 'btn btn-sm ' + (x === b ? 'btn-primary' : 'btn-outline-secondary'); });
        list.querySelectorAll('.man-timeline-item').forEach(function (li) { li.hidden = kind !== '' && li.getAttribute('data-kind') !== kind; });
    });
})();
</script>

<!-- ORDENS DE SERVIÇO -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1"></i> Ordens de Serviço (<?php echo count($osList); ?>)</div>
    <div class="card-body p-0">
        <?php if (empty($osList)): ?>
            <p class="text-center text-muted py-4 mb-0">Nenhuma OS registrada para este equipamento.</p>
        <?php else: ?>
            <div class="table-responsive"><table class="table table-sm table-hover mb-0">
                <thead><tr><th>OS</th><th>Título</th><th>Tipo</th><th>Prioridade</th><th>Status</th><th>Técnico</th><th>Criada</th><th>Concluída</th><th class="text-end">Custo</th></tr></thead>
                <tbody>
                <?php foreach ($osList as $o): ?>
                <tr>
                    <td><strong><?php if (core_can('service_orders.view')): ?><a href="<?php echo url('service-orders', ['action'=>'edit','id'=>$o['id']]); ?>" class="text-decoration-none"><?php echo e($o['os_number']); ?></a><?php else: echo e($o['os_number']); endif; ?></strong></td>
                    <td><?php echo e($o['title']); ?></td>
                    <td><?php echo e($typeLabels[$o['type']] ?? $o['type']); ?></td>
                    <td><span class="badge badge-<?php echo e($o['priority']); ?>"><?php echo e($prioLabels[$o['priority']] ?? $o['priority']); ?></span></td>
                    <td><span class="badge badge-<?php echo e($o['status']); ?>"><?php echo e($osStatusLabels[$o['status']] ?? $o['status']); ?></span></td>
                    <td><?php echo e($o['tech_name'] ?? '—'); ?></td>
                    <td class="text-muted"><?php echo formatDate($o['created_at']); ?></td>
                    <td class="text-muted"><?php echo formatDate($o['completed_at'] ?? ''); ?></td>
                    <td class="text-end">R$ <?php echo number_format($osCostOf($o), 2, ',', '.'); ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<!-- CALIBRAÇÕES -->
<?php if (!empty($calibHistory)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-rulers me-1"></i> Calibrações (<?php echo count($calibHistory); ?>)</div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th>Data</th><th>Próxima</th><th>Resultado</th><th>Órgão</th><th>Responsável</th><th class="text-end">Custo</th></tr></thead>
            <tbody>
            <?php foreach ($calibHistory as $c): ?>
            <tr>
                <td><?php echo formatDate($c['calibration_date']); ?></td>
                <td><?php echo formatDate($c['next_date']); ?></td>
                <td><span class="badge badge-<?php echo $c['result']==='conforme'?'ok':($c['result']==='nao_conforme'?'vencido':'proximo'); ?>"><?php echo e($resultLabels[$c['result']] ?? $c['result']); ?></span></td>
                <td><?php echo e($c['responsible_body'] ?? '—'); ?></td>
                <td><?php echo e($c['responsible_person'] ?? '—'); ?></td>
                <td class="text-end">R$ <?php echo number_format((float)$c['cost'], 2, ',', '.'); ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>

<!-- PLANOS DE MANUTENÇÃO -->
<?php if (!empty($maintPlans)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-tools me-1"></i> Planos de manutenção preventiva</div>
    <div class="card-body p-0">
        <div class="table-responsive"><table class="table table-sm table-hover mb-0">
            <thead><tr><th>Plano</th><th>Frequência</th><th>Próxima</th><th>Última execução</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($maintPlans as $mp): ?>
            <tr>
                <td><strong><?php echo e($mp['title']); ?></strong></td>
                <td><?php echo e($freqLabels[$mp['frequency']] ?? $mp['frequency']); ?></td>
                <td><?php echo formatDate($mp['next_date']); ?></td>
                <td><?php echo formatDate($mp['last_executed'] ?? '', 'd/m/Y H:i'); ?></td>
                <td><span class="badge badge-<?php echo e($mp['status']); ?>"><?php echo $mp['status']==='active'?'Ativo':'Inativo'; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
<?php endif; ?>
