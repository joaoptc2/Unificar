<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Onboarding / Offboarding</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('onboarding.manage_templates')): ?>
            <a href="index.php?m=rh&page=onboarding&action=create_template" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo Template</a>
            <a href="index.php?m=rh&page=onboarding&action=assign" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i> Atribuir Checklist</a>
        <?php endif; ?>
    </div>
</div>
<div class="row g-3">
<?php foreach ($templates as $t): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="badge <?= $t['type'] === 'onboarding' ? 'bg-success' : 'bg-warning text-dark' ?> mb-2"><?= ucfirst($t['type']) ?></span>
                <h6 class="fw-semibold"><?= Sanitize::e($t['name']) ?></h6>
                <small class="text-muted"><?= $t['active'] ? 'Ativo' : 'Inativo' ?></small>
            </div>
            <div class="card-footer bg-transparent">
                <a href="index.php?m=rh&page=onboarding&action=show&id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-eye me-1"></i> Ver itens</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($templates)): ?>
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum template criado ainda.</div></div></div>
<?php endif; ?>
</div>
