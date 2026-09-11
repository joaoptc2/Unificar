<?php
/**
 * Indicadores — página ÚNICA (lista + painel): filtros, cartões de situação
 * (total / na meta / fora da meta / dentro da tolerância), gráficos e a
 * tabela de indicadores com ações. O setor vem do seletor global.
 */
$status_colors = ['met' => 'success', 'tolerance' => 'warning', 'missed' => 'danger'];
$status_labels = ['met' => 'Na meta', 'tolerance' => 'Dentro da tolerância', 'missed' => 'Fora da meta'];
$type_labels   = ['daily' => 'Diário', 'weekly' => 'Semanal', 'monthly' => 'Mensal', 'quarterly' => 'Trimestral', 'semester' => 'Semestral', 'yearly' => 'Anual'];
$trend_icons   = ['up' => 'bi-arrow-up-short', 'down' => 'bi-arrow-down-short', 'stable' => 'bi-dash'];
$trend_colors  = ['up' => 'success', 'down' => 'danger', 'stable' => 'secondary'];

$base_filters = array_filter([
    'type' => $type_filter, 'search' => $search, 'category' => $category,
    'accreditation' => $accreditation, 'responsible_user_id' => $responsible ?: '',
], fn($v) => $v !== '' && $v !== null);
$kpi_url = function ($goal) use ($base_filters) {
    $q = $base_filters;
    if ($goal !== '') $q['goal'] = $goal;
    return url('indicators' . ($q ? '?' . http_build_query($q) : ''));
};
$has_filters = !empty($base_filters) || $goal_filter !== '';
$sector_label = get_sector_id() > 0 ? get_sector_name() : 'Todos os setores';
?>
<div class="page-header">
    <h1><i class="bi bi-graph-up me-2"></i>Indicadores</h1>
    <div class="d-flex gap-2 flex-wrap">
        <?php if (core_can('actions.view')): ?>
        <a href="<?php echo url('indicators/actions'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-check me-1"></i>Planos de ação
        </a>
        <?php endif; ?>
        <?php if (core_can('indicators.create')): ?>
        <a href="<?php echo url('indicators/templates'); ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-collection me-1"></i>Criar a partir de modelo
        </a>
        <a href="<?php echo url('indicators/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo indicador
        </a>
        <?php endif; ?>
    </div>
</div>

<!-- ── Filtros ───────────────────────────────────────────────────────────── -->
<div class="filter-panel">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="indicators">
        <?php if ($goal_filter !== ''): ?><input type="hidden" name="goal" value="<?php echo e($goal_filter); ?>"><?php endif; ?>
        <div class="col-6 col-md-2">
            <label class="form-label">Setor</label>
            <input type="text" class="form-control form-control-sm" value="<?php echo e($sector_label); ?>" readonly
                   title="Altere no seletor 'Setor em foco' no topo da página">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Periodicidade</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach (['daily' => 'Diário', 'monthly' => 'Mensal', 'yearly' => 'Anual'] as $tv => $tl): ?>
                    <option value="<?php echo $tv; ?>" <?php echo $type_filter === $tv ? 'selected' : ''; ?>><?php echo $tl; ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Categoria</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach (($categories ?? []) as $c): ?>
                    <option value="<?php echo e($c['category']); ?>" <?php echo $category === $c['category'] ? 'selected' : ''; ?>>
                        <?php echo e($c['category']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Acreditação</label>
            <select name="accreditation" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach (($accreditations ?? []) as $a): ?>
                    <option value="<?php echo e($a); ?>" <?php echo $accreditation === $a ? 'selected' : ''; ?>><?php echo e($a); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Responsável</label>
            <select name="responsible_user_id" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach (($users ?? []) as $u): ?>
                    <option value="<?php echo (int) $u['id']; ?>" <?php echo (int) $responsible === (int) $u['id'] ? 'selected' : ''; ?>><?php echo e($u['name']); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Nome ou descrição..." value="<?php echo e($search); ?>">
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
            <?php if ($has_filters): ?>
            <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x-lg me-1"></i>Limpar</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- ── Cartões de situação (clique para filtrar) ─────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <a class="kpi-link" href="<?php echo $kpi_url(''); ?>">
        <div class="card stat-card shadow-sm h-100 <?php echo $goal_filter === '' ? 'active' : ''; ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="stat-value text-primary"><?php echo (int) $kpi['total']; ?></div>
                    <div class="stat-label">Total<?php echo $kpi['no_data'] > 0 ? ' <small class="text-muted">(' . (int) $kpi['no_data'] . ' sem dados/meta)</small>' : ''; ?></div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="kpi-link" href="<?php echo $kpi_url('met'); ?>">
        <div class="card stat-card shadow-sm h-100 <?php echo $goal_filter === 'met' ? 'active' : ''; ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success-bg-opacity-10 text-success me-3"><i class="bi bi-check-circle"></i></div>
                <div>
                    <div class="stat-value text-success"><?php echo (int) $kpi['met']; ?></div>
                    <div class="stat-label">Na meta</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="kpi-link" href="<?php echo $kpi_url('missed'); ?>">
        <div class="card stat-card shadow-sm h-100 <?php echo $goal_filter === 'missed' ? 'active' : ''; ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-value text-danger"><?php echo (int) $kpi['missed']; ?></div>
                    <div class="stat-label">Fora da meta</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-3">
        <a class="kpi-link" href="<?php echo $kpi_url('tolerance'); ?>">
        <div class="card stat-card shadow-sm h-100 <?php echo $goal_filter === 'tolerance' ? 'active' : ''; ?>">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value text-warning"><?php echo (int) $kpi['tolerance']; ?></div>
                    <div class="stat-label">Dentro da tolerância</div>
                </div>
            </div>
        </div>
        </a>
    </div>
</div>

<?php if ($kpi['missed'] > 0 && $goal_filter === ''): ?>
<div class="alert alert-danger py-2 small d-flex align-items-center gap-2">
    <i class="bi bi-exclamation-octagon"></i>
    <span><strong><?php echo (int) $kpi['missed']; ?></strong> indicador(es) fora da meta no último lançamento.</span>
    <a href="<?php echo $kpi_url('missed'); ?>" class="ms-auto btn btn-sm btn-outline-danger">Ver quais</a>
</div>
<?php elseif ($kpi['total'] > 0 && $kpi['missed'] === 0 && $goal_filter === ''): ?>
<div class="alert alert-success py-2 small">
    <i class="bi bi-check-circle me-1"></i>Nenhum indicador fora da meta<?php echo $kpi['tolerance'] > 0 ? ' (' . (int) $kpi['tolerance'] . ' dentro da tolerância)' : ''; ?>.
</div>
<?php endif; ?>

<?php if (empty($indicators)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-graph-up display-1 text-muted"></i>
            <p class="text-muted mt-2">Nenhum indicador encontrado<?php echo $has_filters ? ' para os filtros selecionados' : (get_sector_id() ? ' no setor em foco' : ''); ?>.</p>
            <?php if (core_can('indicators.create') && !$has_filters): ?>
            <a href="<?php echo url('indicators/templates'); ?>" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-collection me-1"></i>Criar a partir de modelo
            </a>
            <a href="<?php echo url('indicators/create'); ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Cadastrar indicador
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>

<!-- ── Gráficos do painel ────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-pie-chart me-1"></i>Situação geral</div>
            <div class="card-body d-flex align-items-center justify-content-center" style="min-height:220px">
                <canvas id="chartStatus" height="200"
                        data-values='<?php echo json_encode([(int) $kpi['met'], (int) $kpi['tolerance'], (int) $kpi['missed'], (int) $kpi['no_data']]); ?>'></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart-steps me-1"></i>Situação por categoria</div>
            <div class="card-body" style="min-height:220px">
                <canvas id="chartByCat" height="200"
                        data-labels='<?php echo json_encode(array_keys($by_cat_chart), JSON_UNESCAPED_UNICODE); ?>'
                        data-met='<?php echo json_encode(array_column($by_cat_chart, 'met')); ?>'
                        data-tolerance='<?php echo json_encode(array_column($by_cat_chart, 'tolerance')); ?>'
                        data-missed='<?php echo json_encode(array_column($by_cat_chart, 'missed')); ?>'
                        data-nodata='<?php echo json_encode(array_column($by_cat_chart, 'no_data')); ?>'></canvas>
                <noscript><p class="text-muted small">Gráficos requerem JavaScript.</p></noscript>
            </div>
        </div>
    </div>
</div>

<!-- ── Tabela de indicadores (por categoria) ─────────────────────────────── -->
<?php foreach ($grouped as $cat_name => $inds): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold d-flex align-items-center">
        <i class="bi bi-tag me-1"></i><?php echo e($cat_name); ?>
        <span class="badge bg-secondary ms-1"><?php echo count($inds); ?></span>
        <?php
            $cm = count(array_filter($inds, fn($i) => $i['goal_status'] === 'missed'));
            if ($cm > 0) echo '<span class="badge bg-danger ms-2" title="Fora da meta">' . $cm . ' fora da meta</span>';
        ?>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:28%">Indicador</th>
                        <th>Setor</th>
                        <th class="text-center">Período</th>
                        <th class="text-end">Último</th>
                        <th class="text-end">Meta</th>
                        <th class="text-center">Status</th>
                        <th class="text-center">Tendência</th>
                        <th style="width:110px">Evolução</th>
                        <th class="text-center">N</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($inds as $ind):
                    $dec = (int) ($ind['decimal_places'] ?? 2);
                    $gs  = $ind['goal_status'] ?? null;
                    $td  = $ind['trend_dir'] ?? null;
                ?>
                    <tr>
                        <td>
                            <a href="<?php echo url('indicators/view?id=' . $ind['id']); ?>" class="text-decoration-none fw-semibold">
                                <?php echo e($ind['name']); ?>
                            </a>
                            <?php if (!empty($ind['accreditation'])): ?>
                                <span class="badge bg-info" style="font-size:.55rem"><?php echo e($ind['accreditation']); ?></span>
                            <?php endif; ?>
                            <?php if (!empty($ind['responsible_name'])): ?>
                                <div class="small text-muted"><i class="bi bi-person me-1"></i><?php echo e($ind['responsible_name']); ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo e($ind['sector_name'] ?: '—'); ?></td>
                        <td class="text-center"><span class="badge bg-light text-dark"><?php echo e($type_labels[$ind['type']] ?? $ind['type']); ?></span></td>
                        <td class="text-end fw-bold">
                            <?php echo $ind['last_value'] !== null ? format_number($ind['last_value'], $dec) : '<span class="text-muted">—</span>'; ?>
                            <small class="text-muted"><?php echo e($ind['unit'] ?? ''); ?></small>
                            <?php if (!empty($ind['last_date'])): ?><div class="small text-muted fw-normal"><?php echo format_date($ind['last_date']); ?></div><?php endif; ?>
                        </td>
                        <td class="text-end text-muted">
                            <?php echo ($ind['goal_numeric'] ?? null) !== null ? format_number($ind['goal_numeric'], $dec) : '—'; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($gs && isset($status_colors[$gs])): ?>
                                <span class="badge bg-<?php echo $status_colors[$gs]; ?> ind-status-dot" title="<?php echo $status_labels[$gs]; ?>">
                                    <?php echo $gs === 'met' ? '✓' : ($gs === 'tolerance' ? '~' : '✗'); ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted" title="Sem dados ou sem meta numérica">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($td): ?>
                                <i class="bi <?php echo $trend_icons[$td]; ?> text-<?php echo $trend_colors[$td]; ?>" style="font-size:1.1rem"
                                   title="<?php echo number_format(abs((float) $ind['trend_pct']), 1, ',', '.'); ?>%"></i>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td>
                            <?php if (!empty($ind['spark_values'])): ?>
                                <canvas class="dash-spark" height="25"
                                        data-values='<?php echo json_encode($ind['spark_values']); ?>'
                                        data-color="<?php echo $gs === 'met' ? '#10b981' : ($gs === 'missed' ? '#ef4444' : '#0d6efd'); ?>"></canvas>
                            <?php endif; ?>
                        </td>
                        <td class="text-center text-muted small"><?php echo (int) ($ind['entries_count'] ?? 0); ?></td>
                        <td class="text-end text-nowrap">
                            <a href="<?php echo url('indicators/view?id=' . $ind['id']); ?>" class="btn btn-outline-primary btn-action" title="Ver / análise"><i class="bi bi-bar-chart"></i></a>
                            <?php if (core_can('indicators.record')): ?>
                            <a href="<?php echo url('indicators/data?id=' . $ind['id']); ?>" class="btn btn-outline-success btn-action" title="Lançar dados"><i class="bi bi-plus-lg"></i></a>
                            <?php endif; ?>
                            <?php if (core_can('actions.view')): ?>
                            <a href="<?php echo url('indicators/actions?indicator_id=' . $ind['id']); ?>" class="btn btn-outline-secondary btn-action" title="Planos de ação"><i class="bi bi-list-check"></i></a>
                            <?php endif; ?>
                            <?php if (core_can('indicators.edit')): ?>
                            <a href="<?php echo url('indicators/edit?id=' . $ind['id']); ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (core_can('indicators.delete')): ?>
                            <form method="POST" action="<?php echo url('indicators/delete'); ?>" class="d-inline" data-confirm="Remover este indicador e seus lançamentos?">
                                <?php echo csrf_field(); ?>
                                <input type="hidden" name="id" value="<?php echo (int) $ind['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-action" title="Remover"><i class="bi bi-trash"></i></button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    if (!window.Chart) return;
    // Sparklines por indicador
    document.querySelectorAll('.dash-spark').forEach(function (c) {
        var vals = JSON.parse(c.dataset.values || '[]');
        if (!vals.length) return;
        new Chart(c, { type: 'line',
            data: { labels: vals.map(function (_, i) { return i; }), datasets: [{
                data: vals, borderColor: c.dataset.color || '#0d6efd',
                backgroundColor: 'transparent', tension: .3, pointRadius: 0, borderWidth: 1.5 }] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } } }
        });
    });
    // Situação geral (rosca)
    var cs = document.getElementById('chartStatus');
    if (cs) {
        var v = JSON.parse(cs.dataset.values || '[0,0,0,0]');
        new Chart(cs, { type: 'doughnut',
            data: { labels: ['Na meta', 'Dentro da tolerância', 'Fora da meta', 'Sem dados'],
                datasets: [{ data: v, backgroundColor: ['#10b981', '#f59e0b', '#ef4444', '#cbd5e1'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
        });
    }
    // Situação por categoria (barras empilhadas)
    var cc = document.getElementById('chartByCat');
    if (cc) {
        var mk = function (k) { return JSON.parse(cc.dataset[k] || '[]'); };
        new Chart(cc, { type: 'bar',
            data: { labels: JSON.parse(cc.dataset.labels || '[]'), datasets: [
                { label: 'Na meta', data: mk('met'), backgroundColor: '#10b981' },
                { label: 'Tolerância', data: mk('tolerance'), backgroundColor: '#f59e0b' },
                { label: 'Fora da meta', data: mk('missed'), backgroundColor: '#ef4444' },
                { label: 'Sem dados', data: mk('nodata'), backgroundColor: '#cbd5e1' }
            ] },
            options: { responsive: true, maintainAspectRatio: false,
                plugins: { legend: { position: 'bottom' } },
                scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, ticks: { precision: 0 } } } }
        });
    }
})();
</script>
<?php endif; ?>
