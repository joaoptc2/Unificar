<?php
/**
 * RELATÓRIO ANVISA — Design System "RH Hospital"
 *
 * Três seções de conformidade regulatória:
 *   1. Calibrações vencidas
 *   2. Manutenções preventivas atrasadas
 *   3. Equipamentos sem plano preventivo
 *
 * KPIs no topo + exportação CSV/impressão.
 */
requireModule('anvisa-report');

$hid = hospitalId();

// ============================================================
// DADOS
// ============================================================
try {
    // 1. Calibrações vencidas — equipamentos cuja última calibração tem next_date < hoje
    $stCalib = db()->prepare("
        SELECT e.id AS equip_id, e.code, e.name AS equip_name, e.serial_number,
               s.name AS sector_name,
               ec.calibration_date, ec.next_date, ec.responsible_body, ec.result
        FROM equipment e
        INNER JOIN equipment_calibrations ec ON ec.id = (
            SELECT ec2.id FROM equipment_calibrations ec2
            WHERE ec2.equipment_id = e.id
            ORDER BY ec2.calibration_date DESC LIMIT 1
        )
        LEFT JOIN sectors s ON s.id = e.sector_id
        WHERE e.hospital_id = ? AND ec.next_date < CURDATE()
        ORDER BY ec.next_date ASC
    ");
    $stCalib->execute([$hid]);
    $calibVencidas = $stCalib->fetchAll();
} catch (\Throwable $ex) {
    $calibVencidas = [];
}

try {
    // 2. Manutenções atrasadas
    $stMaint = db()->prepare("
        SELECT mp.id, mp.title, mp.frequency, mp.next_date, mp.last_executed,
               e.name AS equip_name, e.code AS equip_code, s.name AS sector_name
        FROM maintenance_plans mp
        LEFT JOIN equipment e ON e.id = mp.equipment_id
        LEFT JOIN sectors s ON s.id = e.sector_id
        WHERE mp.hospital_id = ? AND mp.status = 'active' AND mp.next_date < CURDATE()
        ORDER BY mp.next_date ASC
    ");
    $stMaint->execute([$hid]);
    $maintAtrasadas = $stMaint->fetchAll();
} catch (\Throwable $ex) {
    $maintAtrasadas = [];
}

try {
    // 3. Equipamentos sem plano preventivo ativo
    $stNoPlan = db()->prepare("
        SELECT e.id, e.code, e.name, e.serial_number, e.criticality,
               s.name AS sector_name
        FROM equipment e
        LEFT JOIN sectors s ON s.id = e.sector_id
        WHERE e.hospital_id = ? AND e.status = 'active'
          AND e.id NOT IN (
              SELECT mp.equipment_id FROM maintenance_plans mp
              WHERE mp.status = 'active' AND mp.equipment_id IS NOT NULL
          )
        ORDER BY e.name ASC
    ");
    $stNoPlan->execute([$hid]);
    $semPlano = $stNoPlan->fetchAll();
} catch (\Throwable $ex) {
    $semPlano = [];
}

$countCalib = count($calibVencidas);
$countMaint = count($maintAtrasadas);
$countNoPlan = count($semPlano);

$freqLabels = [
    'daily'      => 'Diária',
    'weekly'     => 'Semanal',
    'biweekly'   => 'Quinzenal',
    'monthly'    => 'Mensal',
    'quarterly'  => 'Trimestral',
    'semiannual' => 'Semestral',
    'annual'     => 'Anual',
];
$critLabels = ['low'=>'Baixa','medium'=>'Média','high'=>'Alta','critical'=>'Crítica'];

$pageTitle = 'Relatório ANVISA';
ob_start();
?>

<div class="page-header">
    <h1><i class="bi bi-shield-check me-2"></i>Relatório ANVISA</h1>
    <div class="d-flex gap-2">
        <a class="btn btn-outline-primary btn-sm" href="export.php?type=calibrations&format=csv"><i class="bi bi-download me-1"></i> CSV Calibrações</a>
        <a class="btn btn-outline-primary btn-sm" href="export.php?type=calibrations&format=print&status=overdue" target="_blank"><i class="bi bi-printer me-1"></i> Imprimir</a>
    </div>
</div>

<!-- KPIs -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-danger bg-opacity-10 text-danger me-3"><i class="bi bi-rulers"></i></div>
            <div><div class="stat-value text-danger"><?php echo $countCalib; ?></div><div class="stat-label">Calibrações Vencidas</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-warning bg-opacity-10 text-warning me-3"><i class="bi bi-exclamation-triangle"></i></div>
            <div><div class="stat-value text-warning"><?php echo $countMaint; ?></div><div class="stat-label">Manutenções Atrasadas</div></div>
        </div></div>
    </div>
    <div class="col-6 col-md-4">
        <div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
            <div class="stat-icon bg-info bg-opacity-10 text-info me-3"><i class="bi bi-clipboard-x"></i></div>
            <div><div class="stat-value text-info"><?php echo $countNoPlan; ?></div><div class="stat-label">Sem Plano Preventivo</div></div>
        </div></div>
    </div>
</div>

<!-- SEÇÃO 1: Calibrações vencidas -->
<div class="alert alert-danger fw-semibold mb-2">
    <i class="bi bi-rulers me-1"></i> Calibrações Vencidas (<?php echo $countCalib; ?>)
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Equipamento</th>
                        <th>N/S</th>
                        <th>Setor</th>
                        <th>Última Calibração</th>
                        <th>Vencimento</th>
                        <th>Dias Atraso</th>
                        <th>Órgão</th>
                        <th>Resultado</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($calibVencidas)): ?>
                    <tr><td colspan="9" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i> Nenhuma calibração vencida.</td></tr>
                <?php else: ?>
                    <?php foreach ($calibVencidas as $c):
                        $dias = (int)((time() - strtotime($c['next_date'])) / 86400);
                        $resultLabels = ['conforme'=>'Conforme','nao_conforme'=>'Não conforme','conforme_com_ressalvas'=>'Com ressalvas'];
                    ?>
                    <tr>
                        <td><code><?php echo e($c['code'] ?? '—'); ?></code></td>
                        <td><a href="<?php echo url('equipment', ['action'=>'view','id'=>$c['equip_id']]); ?>"><?php echo e($c['equip_name']); ?></a></td>
                        <td class="text-muted"><?php echo e($c['serial_number'] ?? '—'); ?></td>
                        <td><?php echo e($c['sector_name'] ?? '—'); ?></td>
                        <td><?php echo formatDate($c['calibration_date']); ?></td>
                        <td><span class="text-danger fw-semibold"><?php echo formatDate($c['next_date']); ?></span></td>
                        <td><span class="badge bg-danger"><?php echo $dias; ?>d</span></td>
                        <td><?php echo e($c['responsible_body'] ?? '—'); ?></td>
                        <td><?php echo e($resultLabels[$c['result']] ?? $c['result']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SEÇÃO 2: Manutenções atrasadas -->
<div class="alert alert-warning fw-semibold mb-2">
    <i class="bi bi-tools me-1"></i> Manutenções Preventivas Atrasadas (<?php echo $countMaint; ?>)
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Plano</th>
                        <th>Equipamento</th>
                        <th>Setor</th>
                        <th>Frequência</th>
                        <th>Data Prevista</th>
                        <th>Dias Atraso</th>
                        <th>Última Execução</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($maintAtrasadas)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i> Nenhuma manutenção atrasada.</td></tr>
                <?php else: ?>
                    <?php foreach ($maintAtrasadas as $m):
                        $dias = (int)((time() - strtotime($m['next_date'])) / 86400);
                    ?>
                    <tr>
                        <td><strong><?php echo e($m['title']); ?></strong></td>
                        <td><?php echo e($m['equip_name'] ?? '—'); ?></td>
                        <td><?php echo e($m['sector_name'] ?? '—'); ?></td>
                        <td><?php echo e($freqLabels[$m['frequency']] ?? $m['frequency']); ?></td>
                        <td><span class="text-warning fw-semibold"><?php echo formatDate($m['next_date']); ?></span></td>
                        <td><span class="badge bg-warning text-dark"><?php echo $dias; ?>d</span></td>
                        <td class="text-muted"><?php echo $m['last_executed'] ? formatDate($m['last_executed'], 'd/m/Y H:i') : '—'; ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- SEÇÃO 3: Equipamentos sem plano preventivo -->
<div class="alert alert-info fw-semibold mb-2">
    <i class="bi bi-clipboard-x me-1"></i> Equipamentos sem Plano Preventivo (<?php echo $countNoPlan; ?>)
</div>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Equipamento</th>
                        <th>N/S</th>
                        <th>Setor</th>
                        <th>Criticidade</th>
                        <th class="text-end">Ação</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($semPlano)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4"><i class="bi bi-check-circle text-success me-1"></i> Todos os equipamentos possuem plano preventivo.</td></tr>
                <?php else: ?>
                    <?php foreach ($semPlano as $eq): ?>
                    <tr>
                        <td><code><?php echo e($eq['code'] ?? '—'); ?></code></td>
                        <td><a href="<?php echo url('equipment', ['action'=>'view','id'=>$eq['id']]); ?>"><?php echo e($eq['name']); ?></a></td>
                        <td class="text-muted"><?php echo e($eq['serial_number'] ?? '—'); ?></td>
                        <td><?php echo e($eq['sector_name'] ?? '—'); ?></td>
                        <td><span class="badge badge-<?php echo e($eq['criticality']); ?>"><?php echo e($critLabels[$eq['criticality']] ?? $eq['criticality']); ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-outline-primary btn-sm" href="<?php echo url('maintenance', ['action'=>'add','equipment_id'=>$eq['id']]); ?>">
                                <i class="bi bi-plus-lg me-1"></i> Criar plano
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/layout.php';
