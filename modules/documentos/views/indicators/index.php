<div class="page-header">
    <h1><i class="bi bi-graph-up me-2"></i>Indicadores de Enfermagem</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('indicators/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo Indicador
        </a>
    </div>
</div>

<!-- Filtros -->
<div class="filter-panel">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="indicators">
        <div class="col-md-2">
            <label class="form-label">Periodicidade</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">Todas</option>
                <option value="daily"   <?php echo $type_filter === 'daily'   ? 'selected' : ''; ?>>Diário</option>
                <option value="monthly" <?php echo $type_filter === 'monthly' ? 'selected' : ''; ?>>Mensal</option>
                <option value="yearly"  <?php echo $type_filter === 'yearly'  ? 'selected' : ''; ?>>Anual</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Categoria</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach (($categories ?? []) as $c): ?>
                    <option value="<?php echo e($c['category']); ?>"
                            <?php echo ($category ?? '') === $c['category'] ? 'selected' : ''; ?>>
                        <?php echo e($c['category']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-5">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Nome ou descrição..." value="<?php echo e($search ?? ''); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
        </div>
    </form>
</div>

<?php if (empty($indicators)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-graph-up display-1 text-muted"></i>
            <p class="text-muted mt-2">Nenhum indicador cadastrado.</p>
            <a href="<?php echo url('indicators/create'); ?>" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Cadastrar primeiro indicador
            </a>
        </div>
    </div>
<?php else:
    $type_labels = ['daily' => 'Diário', 'monthly' => 'Mensal', 'yearly' => 'Anual'];
    $status_map = [
        'met'       => ['badge-ativo',    '✓ Meta'],
        'tolerance' => ['badge-proximo',  '~ Tolerância'],
        'missed'    => ['badge-desligado','✗ Fora'],
    ];
?>
    <div class="row g-3">
    <?php foreach ($indicators as $ind):
        $tlabel = $type_labels[$ind['type']] ?? $ind['type'];
        $dec = (int) ($ind['decimal_places'] ?? 2);
        $goal_status = $ind['goal_status'] ?? null;
        $spark_values = $ind['spark_values'] ?? [];
        $goal_numeric = $ind['goal_numeric'] ?? null;
        $last_value   = $ind['last_value']   ?? null;
    ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <div class="flex-grow-1 me-2">
                            <h6 class="fw-semibold mb-1"><?php echo e($ind['name']); ?></h6>
                            <small class="text-muted">
                                <?php echo e($tlabel); ?>
                                <?php if (!empty($ind['category'])): ?>
                                    · <?php echo e($ind['category']); ?>
                                <?php endif; ?>
                            </small>
                        </div>
                        <?php if ($goal_status && isset($status_map[$goal_status])): ?>
                            <span class="badge <?php echo $status_map[$goal_status][0]; ?>"
                                  style="font-size:.7rem">
                                <?php echo $status_map[$goal_status][1]; ?>
                            </span>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mt-2 small text-muted">
                        <div>
                            <?php if ($last_value !== null): ?>
                                <strong class="text-dark"><?php echo format_number($last_value, $dec); ?></strong>
                                <?php echo e($ind['unit'] ?? ''); ?>
                            <?php endif; ?>
                        </div>
                        <div>
                            <?php if ($goal_numeric !== null): ?>
                                Meta: <strong><?php echo format_number($goal_numeric, $dec); ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="d-flex gap-1 mt-3">
                        <a href="<?php echo url('indicators/view?id=' . $ind['id']); ?>"
                           class="btn btn-sm btn-outline-primary flex-fill">
                            <i class="bi bi-bar-chart me-1"></i>Ver
                        </a>
                        <a href="<?php echo url('indicators/data?id=' . $ind['id']); ?>"
                           class="btn btn-sm btn-outline-success flex-fill">
                            <i class="bi bi-plus-lg me-1"></i>Lançar
                        </a>
                        <a href="<?php echo url('indicators/edit?id=' . $ind['id']); ?>"
                           class="btn btn-sm btn-outline-warning">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <?php if (!empty($pagination) && $pagination['pages'] > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-3">
        <small class="text-muted">Total: <strong><?php echo $pagination['total']; ?></strong></small>
        <?php echo pagination_html($pagination); ?>
    </div>
    <?php endif; ?>
<?php endif; ?>

<!-- sparklines removidos — disponíveis no Painel (/indicators/dashboard) -->
