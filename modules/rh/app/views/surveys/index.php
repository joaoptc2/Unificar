<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i>Pesquisas de Clima</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('surveys.create')): ?>
            <a href="index.php?m=rh&page=surveys&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova pesquisa</a>
        <?php endif; ?>
    </div>
</div>
<div class="row g-3">
<?php foreach ($surveys as $s): ?>
<div class="col-md-4">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <span class="badge <?= match($s['status']) { 'ativa' => 'bg-success', 'encerrada' => 'bg-secondary', default => 'bg-warning text-dark' } ?> mb-2"><?= ucfirst($s['status']) ?></span>
            <span class="badge bg-info"><?= ucfirst($s['type']) ?></span>
            <?= (int)$s['anonymous'] ? '<span class="badge bg-dark">Anonima</span>' : '' ?>
            <h6 class="fw-semibold mt-2"><?= Sanitize::e($s['title']) ?></h6>
            <?php if ($s['starts_at'] || $s['ends_at']): ?><small class="text-muted"><?= Sanitize::formatDate($s['starts_at']) ?> - <?= Sanitize::formatDate($s['ends_at']) ?></small><?php endif; ?>
        </div>
        <div class="card-footer bg-transparent d-flex gap-1">
            <a href="index.php?m=rh&page=surveys&action=show&id=<?= $s['id'] ?>" class="btn btn-outline-primary btn-sm flex-grow-1">Resultados</a>
            <?php if ($s['status'] === 'ativa'): ?><a href="index.php?m=rh&page=surveys&action=show&id=<?= $s['id'] ?>#respond" class="btn btn-primary btn-sm">Responder</a><?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($surveys)): ?><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhuma pesquisa criada.</div></div></div><?php endif; ?>
</div>
