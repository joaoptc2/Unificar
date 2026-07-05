<div class="page-header">
    <h1><i class="bi bi-collection me-2"></i>Templates de Indicadores</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('indicators/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Criar em branco
        </a>
        <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<p class="text-muted small mb-3">
    Indicadores pré-configurados baseados em referências nacionais e internacionais (ONA, JCI, ANVISA, ANAHP, IHI, OMS).
    Selecione um template para criar o indicador com variáveis, fórmula e meta já preenchidos.
</p>

<div class="filter-panel mb-3">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="indicators/templates">
        <div class="col-md-3">
            <label class="form-label">Categoria</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo e($cat); ?>" <?php echo $category_filter === $cat ? 'selected' : ''; ?>>
                        <?php echo e($cat); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-7">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   value="<?php echo e($search); ?>" placeholder="Nome ou descrição...">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
        </div>
    </form>
</div>

<?php if (empty($templates)): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center py-5 text-muted">
        Nenhum template encontrado para os filtros selecionados.
    </div></div>
<?php else: ?>
    <?php
    $by_cat = [];
    foreach ($templates as $slug => $t) {
        $by_cat[$t['category']][$slug] = $t;
    }
    ksort($by_cat);
    $cat_icons = [
        'Gestão'=>'bi-building','Mortalidade'=>'bi-heart-pulse','Controle de Infecção'=>'bi-shield-plus',
        'Segurança do Paciente'=>'bi-shield-check','Centro Cirúrgico'=>'bi-scissors',
        'Obstetrícia'=>'bi-gender-female','Pronto-Socorro'=>'bi-lightning','Satisfação'=>'bi-emoji-smile',
        'Farmácia'=>'bi-capsule',
    ];
    ?>
    <?php foreach ($by_cat as $cat_name => $cat_templates): ?>
    <h6 class="fw-bold text-muted mt-4 mb-2">
        <i class="bi <?php echo $cat_icons[$cat_name] ?? 'bi-tag'; ?> me-1"></i><?php echo e($cat_name); ?>
        <span class="badge bg-secondary ms-1"><?php echo count($cat_templates); ?></span>
    </h6>
    <div class="row g-3 mb-3">
        <?php foreach ($cat_templates as $slug => $t): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <h6 class="fw-semibold mb-1"><?php echo e($t['name']); ?></h6>
                    <p class="text-muted small mb-2"><?php echo e(substr($t['description'] ?? '', 0, 120)); ?></p>

                    <div class="d-flex flex-wrap gap-1 mb-2">
                        <span class="badge bg-light text-dark" style="font-size:.65rem">
                            <i class="bi bi-bullseye me-1"></i>Meta: <?php echo e($t['goal'] ?? '—'); ?> <?php echo e($t['unit']); ?>
                        </span>
                        <?php if (!empty($t['benchmark'])): ?>
                        <span class="badge bg-light text-dark" style="font-size:.65rem">
                            <i class="bi bi-bar-chart me-1"></i>Bench: <?php echo e($t['benchmark']); ?>
                        </span>
                        <?php endif; ?>
                        <?php if (!empty($t['accreditation'])): ?>
                        <span class="badge bg-info text-white" style="font-size:.65rem">
                            <?php echo e($t['accreditation']); ?>
                        </span>
                        <?php endif; ?>
                    </div>

                    <div class="small text-muted mb-2">
                        <strong>Fórmula:</strong> <code class="small"><?php echo e($t['formula']); ?></code>
                    </div>
                    <div class="small text-muted mb-3">
                        <strong>Variáveis:</strong>
                        <?php foreach ($t['variables'] as $v): ?>
                            <span class="badge bg-light text-dark font-monospace"><?php echo e($v['code']); ?></span>
                        <?php endforeach; ?>
                    </div>

                    <a href="<?php echo url('indicators/create?template=' . urlencode($slug)); ?>"
                       class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-plus-lg me-1"></i>Usar este template
                    </a>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>
