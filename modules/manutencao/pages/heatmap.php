<?php
/**
 * MAPA DE CALOR DE CHAMADOS POR SETOR — Design System "RH Hospital"
 *
 * Exibe volume de OS e solicitações QR por setor, com gráfico Chart.js
 * e tabela com barras de progresso relativas.
 * Filtrável por período (30/90/365 dias).
 */
requireModule('heatmap');

$hid = hospitalId();

// ============================================================
// FILTRO DE PERÍODO
// ============================================================
$period  = (int)($_GET['period'] ?? 30);
$allowed = [30, 90, 365];
if (!in_array($period, $allowed, true)) {
    $period = 30;
}
$dateFrom = date('Y-m-d', strtotime("-{$period} days"));

// ============================================================
// DADOS: OS por setor
// ============================================================
$sectorData = [];
try {
    $st = db()->prepare("
        SELECT s.id AS sector_id, s.name AS sector_name,
               COUNT(so.id) AS os_count
        FROM man_sectors s
        LEFT JOIN man_equipment e ON e.sector_id = s.id AND e.hospital_id = s.hospital_id
        LEFT JOIN man_service_orders so ON so.equipment_id = e.id
            AND so.created_at >= ?
        WHERE s.hospital_id = ? AND s.status = 'active'
        GROUP BY s.id, s.name
        ORDER BY os_count DESC
    ");
    $st->execute([$dateFrom . ' 00:00:00', $hid]);
    $sectorData = $st->fetchAll();
} catch (\Throwable $ex) {
    $sectorData = [];
}

// DADOS: Solicitações QR por setor (qr_locations vinculados a setores)
$qrData = [];
try {
    $st = db()->prepare("
        SELECT ql.sector_id, COUNT(ql.id) AS qr_count
        FROM man_qr_locations ql
        WHERE ql.hospital_id = ? AND ql.sector_id IS NOT NULL
        GROUP BY ql.sector_id
    ");
    $st->execute([$hid]);
    foreach ($st->fetchAll() as $r) {
        $qrData[(int)$r['sector_id']] = (int)$r['qr_count'];
    }
} catch (\Throwable $ex) {
    $qrData = [];
}

// Merge QR counts into sector data
$maxTotal = 1;
foreach ($sectorData as &$row) {
    $row['qr_count'] = $qrData[(int)$row['sector_id']] ?? 0;
    $row['total']    = (int)$row['os_count'] + (int)$row['qr_count'];
    if ($row['total'] > $maxTotal) {
        $maxTotal = $row['total'];
    }
}
unset($row);

// Totais gerais
$totalOs = array_sum(array_column($sectorData, 'os_count'));
$totalQr = array_sum(array_column($sectorData, 'qr_count'));

// Labels e valores para Chart.js
$chartLabels = json_encode(array_column($sectorData, 'sector_name'), JSON_UNESCAPED_UNICODE);
$chartOsVals = json_encode(array_map('intval', array_column($sectorData, 'os_count')));
$chartQrVals = json_encode(array_map('intval', array_column($sectorData, 'qr_count')));

$periodLabels = [30 => 'Últimos 30 dias', 90 => 'Últimos 90 dias', 365 => 'Último ano'];

$pageTitle = 'Mapa de Calor';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-fire me-2"></i>Mapa de Calor por Setor</h1>
    <div class="d-flex gap-2">
        <?php foreach ($periodLabels as $p => $label): ?>
            <a class="btn btn-<?php echo $period === $p ? 'primary' : 'outline-secondary'; ?> btn-sm"
               href="<?php echo url('heatmap', ['period' => $p]); ?>">
                <?php echo $p; ?>d
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3"><i class="bi bi-clipboard-data"></i></div>
            <div><div class="stat-value"><?php echo $totalOs; ?></div><div class="stat-label">OS no período</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-success bg-opacity-10 text-success me-3"><i class="bi bi-qr-code"></i></div>
            <div><div class="stat-value"><?php echo $totalQr; ?></div><div class="stat-label">Locais QR</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-building"></i></div>
            <div><div class="stat-value"><?php echo count($sectorData); ?></div><div class="stat-label">Setores ativos</div></div>
        </div></div>
    </div>
</div>

<!-- GRÁFICO -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart-line me-1"></i> Chamados por Setor — <?php echo e($periodLabels[$period]); ?></div>
    <div class="card-body">
        <canvas id="heatmapChart" height="<?php echo max(200, count($sectorData) * 35); ?>"></canvas>
    </div>
</div>

<!-- TABELA -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white fw-semibold"><i class="bi bi-table me-1"></i> Detalhamento por Setor</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Setor</th>
                        <th>OS</th>
                        <th>QR</th>
                        <th>Total</th>
                        <th style="min-width:250px">Volume relativo</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($sectorData)): ?>
                    <tr><td colspan="5" class="text-center text-muted py-4">Nenhum setor cadastrado.</td></tr>
                <?php else: ?>
                    <?php foreach ($sectorData as $row):
                        $pct = $maxTotal > 0 ? round($row['total'] / $maxTotal * 100) : 0;
                        $barColor = $pct >= 75 ? 'bg-danger' : ($pct >= 40 ? 'bg-warning' : 'bg-primary');
                    ?>
                    <tr>
                        <td><strong><?php echo e($row['sector_name']); ?></strong></td>
                        <td><?php echo (int)$row['os_count']; ?></td>
                        <td><?php echo (int)$row['qr_count']; ?></td>
                        <td><strong><?php echo (int)$row['total']; ?></strong></td>
                        <td>
                            <div class="progress" style="height:18px">
                                <div class="progress-bar <?php echo $barColor; ?>" style="width:<?php echo $pct; ?>%">
                                    <?php echo (int)$row['total']; ?>
                                </div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
    var ctx = document.getElementById('heatmapChart');
    if (!ctx) return;
    if (typeof Chart === 'undefined') {  // biblioteca de CDN indisponível
        ctx.replaceWith(Object.assign(document.createElement('p'), {
            className: 'text-muted small text-center my-3',
            textContent: 'Gráfico indisponível (biblioteca não carregada).'
        }));
        return;
    }
    new Chart(ctx, {
        type: 'bar',
        data: {
            labels: <?php echo $chartLabels; ?>,
            datasets: [
                {
                    label: 'Ordens de Serviço',
                    data: <?php echo $chartOsVals; ?>,
                    backgroundColor: 'rgba(13,110,253,0.7)',
                    borderColor: 'rgba(13,110,253,1)',
                    borderWidth: 1
                },
                {
                    label: 'Locais QR',
                    data: <?php echo $chartQrVals; ?>,
                    backgroundColor: 'rgba(25,135,84,0.7)',
                    borderColor: 'rgba(25,135,84,1)',
                    borderWidth: 1
                }
            ]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' }
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: { stepSize: 1 }
                }
            }
        }
    });
})();
</script>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
