<?php
$type_labels = ['daily' => 'Diário', 'monthly' => 'Mensal', 'yearly' => 'Anual'];
$type_label  = $type_labels[$indicator['type']] ?? $indicator['type'];
$dec = (int) ($indicator['decimal_places'] ?? 2);

$goal = $indicator['goal_numeric'] ?? null;
$tol  = (float) ($indicator['goal_tolerance'] ?? 0);
$dir  = $indicator['goal_direction'] ?? 'higher_better';

$chart_rows = array_reverse($data_entries);
$labels = array_map(function($d) { return date('d/m/Y', strtotime($d['reference_date'])); }, $chart_rows);
$values = array_map(function($d) { return (float) $d['value']; }, $chart_rows);

$stats = $analysis['stats'] ?? null;
$trend = $analysis['trend'] ?? null;
$cmp   = $analysis['compare'] ?? null;
$goal_pct    = $analysis['goal_pct'] ?? null;
$goal_status = $analysis['goal_status'] ?? null;

$status_badge = [
    'met'       => ['badge-valido',  '✓ Meta atingida'],
    'tolerance' => ['badge-proximo', '~ Na tolerância'],
    'missed'    => ['badge-vencido', '✗ Fora da meta'],
];
$dir_labels = [
    'higher_better' => 'Quanto maior, melhor',
    'lower_better'  => 'Quanto menor, melhor',
    'target'        => 'Alvo exato',
];
?>
<!-- Cabeçalho -->
<div class="page-header">
    <h1><i class="bi bi-bar-chart me-2"></i><?php echo e($indicator['name']); ?></h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (core_can('indicators.record')): ?>
        <a href="<?php echo url('indicators/data?id=' . $indicator['id']); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Lançar Dados
        </a>
        <?php endif; ?>
        <?php if (!empty($data_entries) && core_can('indicators.export')): ?>
        <a href="<?php echo url('indicators/export?id=' . $indicator['id'] . '&format=csv'); ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-download me-1"></i>CSV
        </a>
        <?php endif; ?>
        <?php if (core_can('indicators.edit')): ?>
        <a href="<?php echo url('indicators/edit?id=' . $indicator['id']); ?>" class="btn btn-warning btn-sm">
            <i class="bi bi-pencil me-1"></i>Editar
        </a>
        <?php endif; ?>
        <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<p class="text-muted small mb-3">
    <?php echo $type_label; ?>
    <?php if (!empty($indicator['category'])): ?>
        &middot; <span class="badge bg-secondary"><?php echo e($indicator['category']); ?></span>
    <?php endif; ?>
    <?php if (!empty($indicator['unit'])): ?>
        &middot; Unidade: <strong><?php echo e($indicator['unit']); ?></strong>
    <?php endif; ?>
</p>

<!-- ── KPIs ──────────────────────────────────────────────────────────────── -->
<?php if ($stats && $stats['count'] > 0): ?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3">
                    <i class="bi bi-speedometer2"></i>
                </div>
                <div>
                    <div class="stat-value text-primary"><?php echo format_number($stats['last'], $dec); ?></div>
                    <div class="stat-label">Último valor</div>
                    <?php if ($goal_status && isset($status_badge[$goal_status])): ?>
                        <span class="badge <?php echo $status_badge[$goal_status][0]; ?> mt-1" style="font-size:.65rem">
                            <?php echo $status_badge[$goal_status][1]; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info-bg-opacity-10 text-info me-3">
                    <i class="bi bi-calculator"></i>
                </div>
                <div>
                    <div class="stat-value text-info"><?php echo format_number($stats['mean'], $dec); ?></div>
                    <div class="stat-label">Média (n=<?php echo $stats['count']; ?>)</div>
                    <small class="text-muted">&sigma; <?php echo format_number($stats['stddev'], $dec); ?></small>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-secondary-bg-opacity-10 text-secondary me-3">
                    <i class="bi bi-arrows-collapse"></i>
                </div>
                <div>
                    <div class="stat-value" style="font-size:1.1rem">
                        <span class="text-danger"><?php echo format_number($stats['min'], $dec); ?></span>
                        —
                        <span class="text-success"><?php echo format_number($stats['max'], $dec); ?></span>
                    </div>
                    <div class="stat-label">Mín / Máx</div>
                </div>
            </div>
        </div>
    </div>
    <?php if ($goal_pct !== null): ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?php echo $goal_pct >= 80 ? 'success' : ($goal_pct >= 50 ? 'warning' : 'danger'); ?>-bg-opacity-10 text-<?php echo $goal_pct >= 80 ? 'success' : ($goal_pct >= 50 ? 'warning' : 'danger'); ?> me-3">
                    <i class="bi bi-bullseye"></i>
                </div>
                <div>
                    <div class="stat-value text-<?php echo $goal_pct >= 80 ? 'success' : ($goal_pct >= 50 ? 'warning' : 'danger'); ?>">
                        <?php echo number_format($goal_pct, 1, ',', '.'); ?>%
                    </div>
                    <div class="stat-label">Dentro da meta</div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?php echo $trend['direction'] === 'up' ? 'success' : ($trend['direction'] === 'down' ? 'danger' : 'secondary'); ?>-bg-opacity-10 text-<?php echo $trend['direction'] === 'up' ? 'success' : ($trend['direction'] === 'down' ? 'danger' : 'secondary'); ?> me-3">
                    <i class="bi bi-<?php echo $trend['direction'] === 'up' ? 'arrow-up' : ($trend['direction'] === 'down' ? 'arrow-down' : 'dash'); ?>"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo number_format(abs($trend['pct_change'] ?? 0), 1, ',', '.'); ?>%</div>
                    <div class="stat-label">Tendência</div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php if ($cmp && $cmp['previous'] !== null): ?>
<div class="alert alert-<?php echo ($cmp['delta'] >= 0 ? 'success' : 'danger'); ?> py-2 mb-4 small">
    <i class="bi bi-arrow-left-right me-2"></i>
    <strong>Período anterior:</strong>
    <?php echo format_number($cmp['previous'], $dec); ?> &rarr; <?php echo format_number($cmp['current'], $dec); ?>
    (<?php echo ($cmp['delta'] >= 0 ? '+' : '') . format_number($cmp['delta'], $dec); ?>
    <?php if ($cmp['delta_pct'] !== null): ?>
        &middot; <?php echo ($cmp['delta_pct'] >= 0 ? '+' : '') . number_format($cmp['delta_pct'], 1, ',', '.'); ?>%
    <?php endif; ?>)
</div>
<?php endif; ?>
<?php endif; ?>

<div class="row g-3">
    <!-- Esquerda: config -->
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-gear me-1"></i>Configuração
            </div>
            <div class="card-body">
                <table class="table table-borderless table-sm mb-0 small">
                    <tr><th class="text-muted">Meta</th><td>
                        <?php if ($goal !== null): ?>
                            <?php echo format_number($goal, $dec); ?> <?php echo e($indicator['unit']); ?>
                            <?php if ($tol > 0): ?> (&pm; <?php echo format_number($tol, 2); ?>)<?php endif; ?>
                            <br><small class="text-muted"><?php echo $dir_labels[$dir] ?? ''; ?></small>
                        <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                    </td></tr>
                    <?php if (($indicator['benchmark_value'] ?? null) !== null): ?>
                    <tr><th class="text-muted">Benchmark</th><td>
                        <?php echo format_number($indicator['benchmark_value'], $dec); ?> <?php echo e($indicator['unit']); ?>
                        <?php if (!empty($indicator['benchmark_source'])): ?>
                            <br><small class="text-muted"><?php echo e($indicator['benchmark_source']); ?></small>
                        <?php endif; ?>
                    </td></tr>
                    <?php endif; ?>
                    <?php if (!empty($indicator['accreditation'])): ?>
                    <tr><th class="text-muted">Acreditação</th>
                        <td><?php foreach (explode(',', $indicator['accreditation']) as $acc): ?>
                            <span class="badge bg-info" style="font-size:.65rem"><?php echo e(trim($acc)); ?></span>
                        <?php endforeach; ?></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($indicator['formula'])): ?>
                    <tr><th class="text-muted">Fórmula</th>
                        <td><code><?php echo e($indicator['formula']); ?></code></td></tr>
                    <?php endif; ?>
                    <?php if (!empty($variables)): ?>
                    <tr><th class="text-muted">Variáveis</th><td>
                        <?php foreach ($variables as $v): ?>
                            <div><span class="badge bg-light text-dark font-monospace"><?php echo e($v['code']); ?></span>
                            <?php echo e($v['label']); ?>
                            <?php if (!empty($v['unit'])): ?><small class="text-muted">(<?php echo e($v['unit']); ?>)</small><?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted">Cadastrado por</th>
                        <td><?php echo e($indicator['created_by_name'] ?? '—'); ?></td></tr>
                </table>
            </div>
        </div>

        <?php if (!empty($indicator['description'])): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i>Descrição</div>
            <div class="card-body"><p class="mb-0 small"><?php echo nl2br(e($indicator['description'])); ?></p></div>
        </div>
        <?php endif; ?>

        <?php if (core_can('indicators.delete')): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="<?php echo url('indicators/delete'); ?>"
                      data-confirm="Remover este indicador e TODOS os seus dados?">
                    <?php echo csrf_field(); ?>
                    <input type="hidden" name="id" value="<?php echo (int) $indicator['id']; ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm w-100">
                        <i class="bi bi-trash me-1"></i>Remover Indicador
                    </button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Direita: gráfico + tabela -->
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-graph-up me-1"></i>Evolução</span>
                <?php if (!empty($var_series)): ?>
                <div class="btn-group btn-group-sm" role="group">
                    <input type="radio" class="btn-check" name="chartMode" id="cm_result" value="result" checked>
                    <label class="btn btn-outline-primary" for="cm_result">Resultado</label>
                    <input type="radio" class="btn-check" name="chartMode" id="cm_vars" value="vars">
                    <label class="btn btn-outline-primary" for="cm_vars">Por variável</label>
                </div>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($chart_rows)): ?>
                    <p class="text-muted text-center py-4 mb-0">Nenhum dado lançado ainda.</p>
                <?php else: ?>
                    <canvas id="mainChart" height="260"></canvas>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($stats && $stats['count'] >= 5): ?>
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-1"></i>Distribuição</div>
            <div class="card-body"><canvas id="histChart" height="120"></canvas></div>
        </div>
        <?php endif; ?>

        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-table me-1"></i>Histórico</span>
                <span class="badge bg-primary"><?php echo count($data_entries); ?> registros</span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($data_entries)): ?>
                    <p class="text-muted text-center py-4 mb-0">Nenhum dado lançado.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Data</th>
                                    <th class="text-end">Valor</th>
                                    <?php foreach ($variables as $v): ?>
                                        <th class="text-end"><?php echo e($v['code']); ?></th>
                                    <?php endforeach; ?>
                                    <th>Status</th>
                                    <th>Por</th>
                                    <th class="text-end">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($data_entries as $entry):
                                $st = $goal !== null ? stats_goal_status($entry['value'], $goal, $dir, $tol) : null;
                            ?>
                                <tr>
                                    <td><?php echo format_date($entry['reference_date']); ?></td>
                                    <td class="fw-bold text-end"><?php echo format_number($entry['value'], $dec); ?></td>
                                    <?php foreach ($variables as $v):
                                        $vv = $entry['variables'][$v['code']] ?? null;
                                    ?>
                                        <td class="text-end small text-muted"><?php echo $vv ? format_number($vv['value'], 2) : '—'; ?></td>
                                    <?php endforeach; ?>
                                    <td>
                                        <?php if ($st && isset($status_badge[$st])): ?>
                                            <span class="badge <?php echo $status_badge[$st][0]; ?>" style="font-size:.65rem">
                                                <?php echo $st === 'met' ? '✓' : ($st === 'tolerance' ? '~' : '✗'); ?>
                                            </span>
                                        <?php else: ?>—<?php endif; ?>
                                    </td>
                                    <td class="small"><?php echo e($entry['recorded_by_name'] ?? '—'); ?></td>
                                    <td class="text-end">
                                        <?php if (core_can('indicators.record')): ?>
                                        <a href="<?php echo url('indicators/data?id=' . $indicator['id'] . '&data_id=' . $entry['id']); ?>"
                                           class="btn btn-outline-warning btn-action" data-bs-toggle="tooltip" title="Editar">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <form method="POST" action="<?php echo url('indicators/delete_data'); ?>"
                                              class="d-inline" data-confirm="Remover este lançamento?">
                                            <?php echo csrf_field(); ?>
                                            <input type="hidden" name="data_id" value="<?php echo (int) $entry['id']; ?>">
                                            <input type="hidden" name="indicator_id" value="<?php echo (int) $indicator['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger btn-action" data-bs-toggle="tooltip" title="Remover">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($chart_rows)): ?>
<script>
(function() {
    var labels   = <?php echo json_encode($labels); ?>;
    var values   = <?php echo json_encode($values); ?>;
    var goal     = <?php echo $goal !== null ? (float) $goal : 'null'; ?>;
    var tol      = <?php echo (float) $tol; ?>;
    var unit     = <?php echo json_encode($indicator['unit']); ?>;
    var chartType= <?php echo json_encode($indicator['chart_type'] ?? 'line'); ?>;
    var dec      = <?php echo (int) $dec; ?>;
    var varSeries= <?php echo json_encode($var_series); ?>;
    var varMeta  = <?php echo json_encode(array_map(fn($v)=>['code'=>$v['code'],'label'=>$v['label']], $variables)); ?>;
    var benchmark= <?php echo ($indicator['benchmark_value'] ?? null) !== null ? (float) $indicator['benchmark_value'] : 'null'; ?>;

    var mainCanvas = document.getElementById('mainChart');
    var chart = null;
    var palette = ['#0d6efd','#10b981','#f59e0b','#ef4444','#3b82f6','#8b5cf6','#ec4899'];

    // SPC: limites de controle ±3σ
    var mean = null, ucl = null, lcl = null;
    if (values.length >= 3) {
        var sum = values.reduce(function(a,b){return a+b},0);
        mean = sum / values.length;
        var variance = values.reduce(function(a,v){return a+(v-mean)*(v-mean)},0) / (values.length-1);
        var sd = Math.sqrt(variance);
        ucl = mean + 3*sd;
        lcl = mean - 3*sd;
    }

    function buildResultDS() {
        var ds = [{
            label: 'Valor', data: values,
            borderColor: '#0d6efd',
            backgroundColor: chartType==='area'?'rgba(13,110,253,0.15)':'rgba(13,110,253,0.5)',
            fill: chartType==='area', tension: 0.3, pointRadius: 4,
            pointBackgroundColor: values.map(function(v) {
                if (ucl !== null && (v > ucl || v < lcl)) return '#ef4444';
                return '#0d6efd';
            }),
        }];
        // Meta
        if (goal !== null) {
            ds.push({ label:'Meta', data:Array(labels.length).fill(goal),
                borderColor:'#ef4444', borderDash:[6,4], pointRadius:0, fill:false, type:'line' });
            if (tol > 0) {
                ds.push({ label:'Meta+tol', data:Array(labels.length).fill(goal+tol),
                    borderColor:'rgba(239,68,68,0.25)', borderDash:[2,3], pointRadius:0, fill:false, type:'line' });
                ds.push({ label:'Meta-tol', data:Array(labels.length).fill(goal-tol),
                    borderColor:'rgba(239,68,68,0.25)', borderDash:[2,3], pointRadius:0, fill:false, type:'line' });
            }
        }
        // Benchmark
        if (benchmark !== null) {
            ds.push({ label:'Benchmark', data:Array(labels.length).fill(benchmark),
                borderColor:'#8b5cf6', borderDash:[8,4], borderWidth:1.5, pointRadius:0, fill:false, type:'line' });
        }
        // SPC: média, UCL, LCL
        if (mean !== null) {
            ds.push({ label:'Média', data:Array(labels.length).fill(mean),
                borderColor:'#64748b', borderDash:[3,3], borderWidth:1, pointRadius:0, fill:false, type:'line' });
            ds.push({ label:'LSC (3σ)', data:Array(labels.length).fill(ucl),
                borderColor:'rgba(239,68,68,0.3)', borderDash:[2,2], borderWidth:1, pointRadius:0, fill:false, type:'line' });
            ds.push({ label:'LIC (3σ)', data:Array(labels.length).fill(lcl),
                borderColor:'rgba(239,68,68,0.3)', borderDash:[2,2], borderWidth:1, pointRadius:0, fill:false, type:'line' });
        }
        return ds;
    }

    function buildVarDS() {
        var ds = [], i = 0;
        for (var code in varSeries) {
            if (!varSeries.hasOwnProperty(code)) continue;
            var meta = varMeta.find(function(m){ return m.code===code; });
            ds.push({ label: meta?meta.label+' ('+code+')':code,
                data: varSeries[code].map(function(p){return p.value;}),
                borderColor: palette[i%palette.length],
                tension: 0.3, fill: false, type: 'line' });
            i++;
        }
        return ds;
    }

    function render(mode) {
        if (chart) chart.destroy();
        var type = chartType==='bar'?'bar':'line';
        var datasets = mode==='vars'?buildVarDS():buildResultDS();
        chart = new Chart(mainCanvas, { type: type,
            data: { labels: labels, datasets: datasets },
            options: { responsive:true, maintainAspectRatio:false,
                interaction:{mode:'index',intersect:false},
                plugins:{legend:{position:'top'},
                    tooltip:{callbacks:{label:function(ctx){return ctx.dataset.label+': '+Number(ctx.parsed.y).toFixed(dec)+(unit?' '+unit:'');}}}},
                scales:{y:{beginAtZero:false,title:{display:!!unit,text:unit||''}},x:{title:{display:true,text:'Período'}}}
            }
        });
    }
    render('result');
    document.querySelectorAll('input[name="chartMode"]').forEach(function(r){
        r.addEventListener('change',function(){render(this.value);});
    });

    var histCanvas = document.getElementById('histChart');
    if (histCanvas && values.length >= 5) {
        var min=Math.min.apply(null,values), max=Math.max.apply(null,values);
        var bins=Math.min(10,Math.ceil(Math.sqrt(values.length))), width=(max-min)/bins||1;
        var counts=Array(bins).fill(0), binLabels=[];
        for(var b=0;b<bins;b++) binLabels.push((min+b*width).toFixed(dec)+'–'+(min+(b+1)*width).toFixed(dec));
        values.forEach(function(v){var idx=Math.min(bins-1,Math.floor((v-min)/width));if(idx<0)idx=0;counts[idx]++;});
        new Chart(histCanvas,{type:'bar',
            data:{labels:binLabels,datasets:[{label:'Frequência',data:counts,
                backgroundColor:'rgba(13,110,253,0.3)',borderColor:'#0d6efd',borderWidth:1}]},
            options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},
                scales:{y:{beginAtZero:true,ticks:{precision:0}}}}
        });
    }
})();
</script>
<?php endif; ?>
