<div class="page-header">
    <h1><i class="bi bi-diagram-3 me-2"></i>Processos</h1>
    <?php if (Auth::can('processes', 'create')): ?>
    <a href="index.php?page=processes&action=create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> Novo Processo
    </a>
    <?php endif; ?>
</div>

<?php if (empty($processes)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-diagram-3 display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhum processo ativo.</p>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($processes as $proc): ?>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <h5 class="mb-0">
                        <a href="index.php?page=processes&action=show&id=<?= $proc['id'] ?>" class="text-decoration-none">
                            <?= Sanitize::e($proc['title']) ?>
                        </a>
                    </h5>
                    <span class="badge bg-<?= match($proc['status']) {
                        'active' => 'success', 'paused' => 'warning', 'completed' => 'primary', 'cancelled' => 'danger', default => 'secondary'
                    } ?>"><?= match($proc['status']) {
                        'active' => 'Ativo', 'paused' => 'Pausado', 'completed' => 'Concluído', 'cancelled' => 'Cancelado', default => $proc['status']
                    } ?></span>
                </div>

                <?php if ($proc['description']): ?>
                <p class="text-muted small"><?= Sanitize::e(mb_substr($proc['description'], 0, 100)) ?></p>
                <?php endif; ?>

                <div class="progress mb-2" style="height: 8px">
                    <div class="progress-bar bg-<?= $proc['progress'] >= 100 ? 'success' : 'primary' ?>"
                         style="width: <?= (int)$proc['progress'] ?>%"></div>
                </div>
                <div class="d-flex justify-content-between small text-muted">
                    <span><?= (int)$proc['progress'] ?>% completo</span>
                    <span><?= $proc['completed_steps'] ?? 0 ?>/<?= $proc['total_steps'] ?? 0 ?> etapas</span>
                </div>

                <div class="mt-2 small text-muted">
                    <i class="bi bi-person me-1"></i><?= Sanitize::e($proc['creator_name'] ?? '') ?>
                    <span class="mx-2">·</span>
                    <?= Sanitize::timeAgo($proc['created_at']) ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
