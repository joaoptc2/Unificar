<?php
$status_colors = ['met'=>'success','tolerance'=>'warning','missed'=>'danger'];
$status_labels = ['met'=>'Na meta','tolerance'=>'Tolerância','missed'=>'Fora da meta'];
$type_labels = ['daily'=>'D','weekly'=>'S','monthly'=>'M','quarterly'=>'T','semester'=>'Sm','yearly'=>'A'];
$trend_icons = ['up'=>'bi-arrow-up-short','down'=>'bi-arrow-down-short','stable'=>'bi-dash'];
$trend_colors = ['up'=>'success','down'=>'danger','stable'=>'secondary'];
?>
<div class="page-header">
    <h1><i class="bi bi-speedometer me-2"></i>Painel de Indicadores</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('indicators.create')): ?>
        <a href="<?php echo url('indicators/templates'); ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-collection me-1"></i>Templates
        </a>
        <a href="<?php echo url('indicators/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo
        </a>
        <?php endif; ?>
        <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list me-1"></i>Lista
        </a>
    </div>
</div>

<!-- KPIs globais -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="stat-value text-primary"><?php echo $kpi['total']; ?></div>
                    <div class="stat-label">Total</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success-bg-opacity-10 text-success me-3"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-value text-success"><?php echo $kpi['met']; ?></div>
                    <div class="stat-label">Na meta</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value text-warning"><?php echo $kpi['tolerance']; ?></div>
                    <div class="stat-label">Tolerância</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-value text-danger"><?php echo $kpi['missed']; ?></div>
                    <div class="stat-label">Fora da meta</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Filtro por categoria -->
<div class="mb-3">
    <div class="btn-group btn-group-sm flex-wrap">
        <a href="<?php echo url('indicators/dashboard'); ?>"
           class="btn <?php echo $category === '' ? 'btn-primary' : 'btn-outline-primary'; ?>">Todas</a>
        <?php foreach ($categories as $c): ?>
            <a href="<?php echo url('indicators/dashboard?category=' . urlencode($c['category'])); ?>"
               class="btn <?php echo $category === $c['category'] ? 'btn-primary' : 'btn-outline-primary'; ?>">
                <?php echo e($c['category']); ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<!-- Grid por categoria -->
<?php if (empty($grouped)): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
        Nenhum indicador cadastrado.
    </div></div>
<?php else: ?>
    <?php foreach ($grouped as $cat_name => $inds): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-tag me-1"></i><?php echo e($cat_name); ?>
            <span class="badge bg-secondary ms-1"><?php echo count($inds); ?></span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th style="width:30%">Indicador</th>
                            <th class="text-center" style="width:60px">Tipo</th>
                            <th class="text-end">Último</th>
                            <th class="text-end">Meta</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Tendência</th>
                            <th style="width:120px">Evolução</th>
                            <th class="text-center">N</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($inds as $ind):
                        $dec = (int) ($ind['decimal_places'] ?? 2);
                        $gs = $ind['goal_status'] ?? null;
                        $td = $ind['trend_dir'] ?? null;
                    ?>
                        <tr>
                            <td>
                                <a href="<?php echo url('indicators/view?id=' . $ind['id']); ?>" class="text-decoration-none fw-semibold">
                                    <?php echo e($ind['name']); ?>
                                </a>
                                <?php if (!empty($ind['accreditation'])): ?>
                                    <span class="badge bg-info" style="font-size:.55rem"><?php echo e($ind['accreditation']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?php echo $type_labels[$ind['type']] ?? '?'; ?></span></td>
                            <td class="text-end fw-bold">
                                <?php echo $ind['last_value'] !== null ? format_number($ind['last_value'], $dec) : '<span class="text-muted">—</span>'; ?>
                                <small class="text-muted"><?php echo e($ind['unit'] ?? ''); ?></small>
                            </td>
                            <td class="text-end text-muted">
                                <?php echo ($ind['goal_numeric'] ?? null) !== null ? format_number($ind['goal_numeric'], $dec) : '—'; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($gs && isset($status_colors[$gs])): ?>
                                    <span class="badge bg-<?php echo $status_colors[$gs]; ?>" style="font-size:.65rem;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center"
                                          data-bs-toggle="tooltip" title="<?php echo $status_labels[$gs]; ?>">
                                        <?php echo $gs === 'met' ? '✓' : ($gs === 'tolerance' ? '~' : '✗'); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($td): ?>
                                    <i class="bi <?php echo $trend_icons[$td]; ?> text-<?php echo $trend_colors[$td]; ?>" style="font-size:1.1rem"
                                       data-bs-toggle="tooltip" title="<?php echo number_format(abs($ind['trend_pct']), 1, ',', '.'); ?>%"></i>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($ind['spark_values'])): ?>
                                    <canvas class="dash-spark" height="25"
                                            data-values='<?php echo json_encode($ind['spark_values']); ?>'
                                            data-color="<?php echo $gs === 'met' ? '#10b981' : ($gs === 'missed' ? '#ef4444' : '#0d6efd'); ?>"></canvas>
                                <?php endif; ?>
                            </td>
                            <td class="text-center text-muted small"><?php echo $ind['entries_count'] ?? 0; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<script>
document.querySelectorAll('.dash-spark').forEach(function(c){
    var vals = JSON.parse(c.dataset.values||'[]');
    if(!vals.length) return;
    new Chart(c,{type:'line',
        data:{labels:vals.map(function(_,i){return i}),datasets:[{
            data:vals,borderColor:c.dataset.color||'#0d6efd',
            backgroundColor:'transparent',tension:.3,pointRadius:0,borderWidth:1.5}]},
        options:{responsive:true,maintainAspectRatio:false,
            plugins:{legend:{display:false},tooltip:{enabled:false}},
            scales:{x:{display:false},y:{display:false}}}
    });
});
</script>
