<?php
/**
 * MÓDULO DE INDICADORES — Design System "RH Hospital"
 */
requireModule("indicators");

$hid = hospitalId();

// ============================================================
// DADOS
// ============================================================
$totalEquip = db()->prepare("SELECT COUNT(*) FROM man_equipment WHERE hospital_id = ?");
$totalEquip->execute([$hid]);
$totalEquip = (int)$totalEquip->fetchColumn();

$osStats = db()->prepare("SELECT status, COUNT(*) AS total FROM man_service_orders WHERE hospital_id = ? GROUP BY status");
$osStats->execute([$hid]);
$osMap = [];
foreach ($osStats->fetchAll() as $r) { $osMap[$r['status']] = (int)$r['total']; }
$totalOs = array_sum($osMap);

$mttr = db()->prepare("
    SELECT AVG(TIMESTAMPDIFF(HOUR, started_at, completed_at)) AS mttr
    FROM man_service_orders
    WHERE hospital_id = ? AND status = 'completed' AND started_at IS NOT NULL AND completed_at IS NOT NULL
");
$mttr->execute([$hid]);
$mttrVal = round((float)$mttr->fetchColumn(), 1);

$mtbfVal = 0;
try {
    $stmt = db()->prepare("
        SELECT equipment_id, created_at FROM man_service_orders
        WHERE hospital_id = ? AND type = 'corrective' AND equipment_id IS NOT NULL
        ORDER BY equipment_id, created_at
    ");
    $stmt->execute([$hid]);
    $diffs = []; $prev = [];
    foreach ($stmt->fetchAll() as $r) {
        $eid = $r['equipment_id'];
        if (isset($prev[$eid])) {
            $d = (strtotime($r['created_at']) - strtotime($prev[$eid])) / 86400;
            if ($d > 0) $diffs[] = $d;
        }
        $prev[$eid] = $r['created_at'];
    }
    if (count($diffs) > 0) $mtbfVal = round(array_sum($diffs) / count($diffs), 1);
} catch (Exception $ex) {}

$availability = ($mtbfVal > 0 && $mttrVal > 0)
    ? round(($mtbfVal * 24) / (($mtbfVal * 24) + $mttrVal) * 100, 1)
    : 100;

$osByMonth = db()->prepare("
    SELECT DATE_FORMAT(created_at, '%Y-%m') AS month, COUNT(*) AS total
    FROM man_service_orders WHERE hospital_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month
");
$osByMonth->execute([$hid]);
$osByMonth = $osByMonth->fetchAll();

$osByType = db()->prepare("SELECT type, COUNT(*) AS total FROM man_service_orders WHERE hospital_id = ? GROUP BY type");
$osByType->execute([$hid]);
$osByType = $osByType->fetchAll();

$equipByCrit = db()->prepare("SELECT criticality, COUNT(*) AS total FROM man_equipment WHERE hospital_id = ? GROUP BY criticality");
$equipByCrit->execute([$hid]);
$equipByCrit = $equipByCrit->fetchAll();

$overdueCount = db()->prepare("SELECT COUNT(*) FROM man_maintenance_plans WHERE hospital_id = ? AND status = 'active' AND next_date < CURDATE()");
$overdueCount->execute([$hid]);
$overdueCount = (int)$overdueCount->fetchColumn();

$lowStockCount = db()->prepare("SELECT COUNT(*) FROM man_parts WHERE hospital_id = ? AND status = 'active' AND quantity <= min_quantity");
$lowStockCount->execute([$hid]);
$lowStockCount = (int)$lowStockCount->fetchColumn();

$typeLabels = ['preventive'=>'Preventiva','corrective'=>'Corretiva','predictive'=>'Preditiva','calibration'=>'Calibração','inspection'=>'Inspeção'];
$critLabels = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];
$statusLabels = ['open'=>'Aberta','in_progress'=>'Em Andamento','waiting_part'=>'Ag. Peça','completed'=>'Concluída','cancelled'=>'Cancelada'];
$statusColors = ['open'=>'primary','in_progress'=>'warning','waiting_part'=>'warning','completed'=>'success','cancelled'=>'secondary'];

$pageTitle = 'Indicadores';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-graph-up me-2"></i>Indicadores e KPIs</h1>
    <a class="btn btn-outline-secondary btn-sm" href="<?php echo url('dashboard'); ?>">
        <i class="bi bi-arrow-left me-1"></i> Dashboard
    </a>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-clock-history"></i></div>
            <div><div class="stat-value"><?php echo $mttrVal > 0 ? $mttrVal.'h' : '—'; ?></div><div class="stat-label">MTTR</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-arrow-repeat"></i></div>
            <div><div class="stat-value"><?php echo $mtbfVal > 0 ? $mtbfVal.'d' : '—'; ?></div><div class="stat-label">MTBF</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-percent"></i></div>
            <div><div class="stat-value text-success"><?php echo $availability; ?>%</div><div class="stat-label">Disponibilidade</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-secondary bg-opacity-10 text-secondary me-3"><i class="bi bi-clipboard-data"></i></div>
            <div><div class="stat-value"><?php echo $totalOs; ?></div><div class="stat-label">Total OS</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="stat-value text-<?php echo $overdueCount > 0 ? 'danger' : 'success'; ?>"><?php echo $overdueCount; ?></div><div class="stat-label">Manut. Atrasadas</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <div class="card stat-card shadow-sm h-100"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-box-seam"></i></div>
            <div><div class="stat-value text-<?php echo $lowStockCount > 0 ? 'warning' : 'success'; ?>"><?php echo $lowStockCount; ?></div><div class="stat-label">Estoque Baixo</div></div>
        </div></div>
    </div>
</div>

<!-- OS POR STATUS -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-1"></i> OS por Status</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Status</th><th>Qtd</th><th>%</th><th style="min-width:200px">Barra</th></tr></thead>
                <tbody>
                <?php foreach ($statusLabels as $k => $v):
                    $qty = $osMap[$k] ?? 0;
                    $pct = $totalOs > 0 ? round($qty / $totalOs * 100, 1) : 0;
                    $color = $statusColors[$k] ?? 'secondary';
                ?>
                <tr>
                    <td><span class="badge badge-<?php echo e($k); ?>"><?php echo e($v); ?></span></td>
                    <td><strong><?php echo $qty; ?></strong></td>
                    <td><?php echo $pct; ?>%</td>
                    <td>
                        <div class="progress" style="height:18px">
                            <div class="progress-bar bg-<?php echo e($color); ?>" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="row g-3 mb-3">
    <!-- OS POR TIPO -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-tags me-1"></i> OS por Tipo</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Tipo</th><th>Qtd</th><th>%</th></tr></thead>
                        <tbody>
                        <?php foreach ($osByType as $r):
                            $pct = $totalOs > 0 ? round($r['total'] / $totalOs * 100, 1) : 0;
                        ?>
                        <tr>
                            <td><?php echo e($typeLabels[$r['type']] ?? $r['type']); ?></td>
                            <td><strong><?php echo $r['total']; ?></strong></td>
                            <td><?php echo $pct; ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($osByType)): ?><tr><td colspan="3" class="text-center text-muted">Sem dados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- EQUIPAMENTOS POR CRITICIDADE -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-exclamation me-1"></i> Equipamentos por Criticidade</div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead><tr><th>Criticidade</th><th>Qtd</th></tr></thead>
                        <tbody>
                        <?php foreach ($equipByCrit as $r): ?>
                        <tr>
                            <td><span class="badge badge-<?php echo e($r['criticality']); ?>"><?php echo e($critLabels[$r['criticality']] ?? $r['criticality']); ?></span></td>
                            <td><strong><?php echo $r['total']; ?></strong></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($equipByCrit)): ?><tr><td colspan="2" class="text-center text-muted">Sem dados.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- OS POR MÊS -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-calendar3 me-1"></i> OS por Mês (Últimos 6 Meses)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Mês</th><th>Qtd</th><th style="min-width:300px">Barra</th></tr></thead>
                <tbody>
                <?php
                $maxMonth = 1;
                foreach ($osByMonth as $r) { if ($r['total'] > $maxMonth) $maxMonth = $r['total']; }
                foreach ($osByMonth as $r):
                    $pct = round($r['total'] / $maxMonth * 100);
                ?>
                <tr>
                    <td><?php echo e($r['month']); ?></td>
                    <td><strong><?php echo $r['total']; ?></strong></td>
                    <td>
                        <div class="progress" style="height:18px">
                            <div class="progress-bar" style="width:<?php echo $pct; ?>%"><?php echo $r['total']; ?></div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($osByMonth)): ?><tr><td colspan="3" class="text-center text-muted">Sem dados.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
