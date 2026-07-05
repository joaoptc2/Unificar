<?php
/**
 * DASHBOARD — Design System "RH Hospital"
 */
requireModule("dashboard");

$hid = hospitalId();

/* ------------------------------------------------------------------
 * Estatísticas
 * ------------------------------------------------------------------ */
$stats   = [];
$queries = [
    'total_equipment'   => "SELECT COUNT(*) FROM man_equipment WHERE hospital_id = ?",
    'active_equipment'  => "SELECT COUNT(*) FROM man_equipment WHERE hospital_id = ? AND status = 'active'",
    'os_open'           => "SELECT COUNT(*) FROM man_service_orders WHERE hospital_id = ? AND status IN ('open','in_progress','waiting_part')",
    'os_completed'      => "SELECT COUNT(*) FROM man_service_orders WHERE hospital_id = ? AND status = 'completed'",
    'os_overdue'        => "SELECT COUNT(*) FROM man_service_orders WHERE hospital_id = ? AND status IN ('open','in_progress') AND scheduled_date < CURDATE()",
    'low_stock'         => "SELECT COUNT(*) FROM man_parts WHERE hospital_id = ? AND quantity <= min_quantity AND status = 'active'",
    'total_technicians' => "SELECT COUNT(*) FROM man_technicians WHERE hospital_id = ? AND status = 'active'",
    'total_parts'       => "SELECT COUNT(*) FROM man_parts WHERE hospital_id = ? AND status = 'active'",
];
foreach ($queries as $key => $sql) {
    $stmt = db()->prepare($sql);
    $stmt->execute([$hid]);
    $stats[$key] = (int)$stmt->fetchColumn();
}

/* MTTR (Mean Time To Repair) — horas, últimos 90 dias */
$mttr = db()->prepare("
    SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, completed_at)) / 60 AS mttr
    FROM man_service_orders
    WHERE hospital_id = ? AND status = 'completed'
      AND completed_at IS NOT NULL
      AND completed_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
");
$mttr->execute([$hid]);
$mttrHours = (float)($mttr->fetchColumn() ?: 0);

/* % Conformidade de calibração */
$calibRow = db()->prepare("
    SELECT
      SUM(CASE WHEN next_date >= CURDATE() THEN 1 ELSE 0 END) AS ok,
      COUNT(*) AS total
    FROM (
      SELECT equipment_id, MAX(next_date) AS next_date
      FROM man_equipment_calibrations WHERE hospital_id = ?
      GROUP BY equipment_id
    ) t
");
$calibRow->execute([$hid]);
$calib = $calibRow->fetch() ?: ['ok' => 0, 'total' => 0];
$calibPct = $calib['total'] > 0 ? (int)round(($calib['ok'] / $calib['total']) * 100) : null;

/* OS por mês (6 meses) */
$osMonthly = db()->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS ym, COUNT(*) AS total
    FROM man_service_orders
    WHERE hospital_id = ? AND created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY ym ORDER BY ym
");
$osMonthly->execute([$hid]);
$osMonthly = $osMonthly->fetchAll();

$labels = [];
$vals   = [];
for ($i = 5; $i >= 0; $i--) {
    $m = date('Y-m', strtotime("-$i month"));
    $labels[] = date('M/y', strtotime($m . '-01'));
    $found = 0;
    foreach ($osMonthly as $r) {
        if ($r['ym'] === $m) { $found = (int)$r['total']; break; }
    }
    $vals[] = $found;
}

/* OS por status (doughnut) */
$statusStmt = db()->prepare("
    SELECT status, COUNT(*) AS total
    FROM man_service_orders WHERE hospital_id = ?
    GROUP BY status
");
$statusStmt->execute([$hid]);
$osByStatus = $statusStmt->fetchAll();
$statusLabels = ['open'=>'Abertas','in_progress'=>'Em andamento','waiting_part'=>'Aguardando peça','completed'=>'Concluídas','cancelled'=>'Canceladas'];

/* Últimas OS */
$stmt = db()->prepare("
    SELECT so.*, e.name AS equip_name
    FROM man_service_orders so
    LEFT JOIN man_equipment e ON e.id = so.equipment_id
    WHERE so.hospital_id = ?
    ORDER BY so.created_at DESC
    LIMIT 5
");
$stmt->execute([$hid]);
$recentOrders = $stmt->fetchAll();

/* Calibrações vencidas */
$calibOverdue = db()->prepare("
    SELECT e.name, c.next_date
    FROM man_equipment_calibrations c
    JOIN man_equipment e ON e.id = c.equipment_id
    WHERE c.hospital_id = ?
      AND c.next_date = (SELECT MAX(c2.next_date) FROM man_equipment_calibrations c2 WHERE c2.equipment_id = c.equipment_id)
      AND c.next_date < CURDATE()
    ORDER BY c.next_date ASC
    LIMIT 5
");
$calibOverdue->execute([$hid]);
$calibOverdue = $calibOverdue->fetchAll();

$pageTitle = 'Dashboard';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="<?php echo url('indicators'); ?>">
            <i class="bi bi-graph-up me-1"></i> Indicadores
        </a>
    </div>
</div>

<?php if (!empty($calibOverdue)): ?>
    <div class="alert alert-danger d-flex align-items-center" role="alert">
        <i class="bi bi-exclamation-octagon fs-4 me-2"></i>
        <div>
            <strong><?php echo count($calibOverdue); ?> equipamento(s) com calibração vencida:</strong>
            <?php foreach ($calibOverdue as $i => $c): ?><?php echo $i > 0 ? ', ' : ' '; ?><?php echo e($c['name']); ?><?php endforeach; ?>
            <a href="<?php echo url('calibration', ['status' => 'overdue']); ?>" class="alert-link ms-2">ver detalhes</a>
        </div>
    </div>
<?php endif; ?>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-hdd-rack"></i></div>
                <div>
                    <div class="stat-value"><?php echo $stats['total_equipment']; ?></div>
                    <div class="stat-label">Equipamentos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-clipboard-check"></i></div>
                <div>
                    <div class="stat-value"><?php echo $stats['os_open']; ?></div>
                    <div class="stat-label">OS Abertas</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value text-<?php echo $stats['os_overdue'] > 0 ? 'danger' : 'success'; ?>"><?php echo $stats['os_overdue']; ?></div>
                    <div class="stat-label">OS Atrasadas</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-box-seam"></i></div>
                <div>
                    <div class="stat-value text-<?php echo $stats['low_stock'] > 0 ? 'warning' : 'success'; ?>"><?php echo $stats['low_stock']; ?></div>
                    <div class="stat-label">Estoque Baixo</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3"><i class="bi bi-clock-history"></i></div>
                <div>
                    <div class="stat-value"><?php echo $mttrHours > 0 ? number_format($mttrHours, 1, ',', '.') . 'h' : '—'; ?></div>
                    <div class="stat-label">MTTR 90d</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-rulers"></i></div>
                <div>
                    <div class="stat-value text-<?php echo ($calibPct !== null && $calibPct < 80) ? 'warning' : 'success'; ?>"><?php echo $calibPct !== null ? $calibPct . '%' : '—'; ?></div>
                    <div class="stat-label">Conformidade Calib.</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Gráficos -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-bar-chart me-1"></i> OS por mês (últimos 6 meses)
            </div>
            <div class="card-body">
                <div style="height:260px"><canvas id="chartOsMonthly"></canvas></div>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-pie-chart me-1"></i> OS por status
            </div>
            <div class="card-body">
                <div style="height:260px"><canvas id="chartOsStatus"></canvas></div>
            </div>
        </div>
    </div>
</div>

<!-- Últimas OS + Resumo -->
<div class="row g-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold"><i class="bi bi-clipboard2-pulse me-1"></i> Últimas Ordens de Serviço</span>
                <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('service-orders'); ?>">
                    <i class="bi bi-arrow-right me-1"></i> Ver todas
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($recentOrders)): ?>
                    <p class="text-center text-muted py-4 mb-0">Nenhuma OS registrada.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>OS</th><th>Título</th><th>Status</th><th>Data</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($recentOrders as $os): ?>
                                <tr>
                                    <td><strong><?php echo e($os['os_number']); ?></strong></td>
                                    <td><?php echo e($os['title']); ?></td>
                                    <td><span class="badge badge-<?php echo e($os['status']); ?>"><?php echo e($statusLabels[$os['status']] ?? $os['status']); ?></span></td>
                                    <td class="text-muted"><?php echo formatDate($os['created_at']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-1"></i> Resumo
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <tbody>
                        <tr><td>Equipamentos Ativos</td><td class="text-end"><strong><?php echo $stats['active_equipment']; ?></strong></td></tr>
                        <tr><td>OS Concluídas</td><td class="text-end"><strong><?php echo $stats['os_completed']; ?></strong></td></tr>
                        <tr><td>Técnicos Ativos</td><td class="text-end"><strong><?php echo $stats['total_technicians']; ?></strong></td></tr>
                        <tr><td>Peças em Estoque</td><td class="text-end"><strong><?php echo $stats['total_parts']; ?></strong></td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
(function(){
    if (typeof Chart === 'undefined') return;
    new Chart(document.getElementById('chartOsMonthly'), {
        type: 'bar',
        data: {
            labels: <?php echo json_encode($labels); ?>,
            datasets: [{
                label: 'OS criadas',
                data: <?php echo json_encode($vals); ?>,
                backgroundColor: '#0d6efd',
                borderRadius: 6
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
    });
    new Chart(document.getElementById('chartOsStatus'), {
        type: 'doughnut',
        data: {
            labels: <?php echo json_encode(array_map(fn($r)=>$statusLabels[$r['status']] ?? $r['status'], $osByStatus)); ?>,
            datasets: [{
                data: <?php echo json_encode(array_map(fn($r)=>(int)$r['total'], $osByStatus)); ?>,
                backgroundColor: ['#3b82f6','#f59e0b','#f97316','#10b981','#64748b']
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom' } }
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
