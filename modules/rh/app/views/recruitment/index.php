<div class="page-header">
    <h1><i class="bi bi-briefcase me-2"></i>Processos Seletivos</h1>
    <div class="d-flex gap-2">
        <a href="index.php?m=rh&page=recruitment&status=aberta" class="btn btn-outline-success btn-sm <?= $status === 'aberta' ? 'active' : '' ?>">Abertas</a>
        <a href="index.php?m=rh&page=recruitment&status=fechada" class="btn btn-outline-secondary btn-sm <?= $status === 'fechada' ? 'active' : '' ?>">Fechadas</a>
        <a href="index.php?m=rh&page=recruitment" class="btn btn-outline-primary btn-sm <?= !$status ? 'active' : '' ?>">Todas</a>
        <?php if (Auth::can('recruitment', 'create')): ?>
            <a href="index.php?m=rh&page=recruitment&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Nova Vaga
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="row g-3">
    <?php if (empty($jobs)): ?>
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center text-muted py-5">Nenhuma vaga encontrada.</div>
            </div>
        </div>
    <?php else: foreach ($jobs as $job): ?>
        <div class="col-md-6 col-lg-4">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start mb-2">
                        <h6 class="fw-bold mb-0">
                            <a href="index.php?m=rh&page=recruitment&action=show&id=<?= $job['id'] ?>" class="text-decoration-none">
                                <?= Sanitize::e($job['title']) ?>
                            </a>
                        </h6>
                        <span class="badge <?= $job['status'] === 'aberta' ? 'bg-success' : 'bg-secondary' ?>">
                            <?= ucfirst($job['status']) ?>
                        </span>
                    </div>
                    <p class="text-muted small mb-2"><?= Sanitize::e($job['department_name'] ?? 'Sem departamento') ?></p>
                    <?php if ($job['description']): ?>
                        <p class="small text-truncate-2 mb-2"><?= Sanitize::e($job['description']) ?></p>
                    <?php endif; ?>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="badge bg-light text-dark">
                            <i class="bi bi-people me-1"></i><?= $job['candidate_count'] ?> candidato(s)
                        </span>
                        <small class="text-muted"><?= Sanitize::formatDate(substr($job['created_at'], 0, 10)) ?></small>
                    </div>
                </div>
                <div class="card-footer bg-transparent">
                    <a href="index.php?m=rh&page=recruitment&action=show&id=<?= $job['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-kanban me-1"></i> Ver Processo
                    </a>
                </div>
            </div>
        </div>
    <?php endforeach; endif; ?>
</div>
