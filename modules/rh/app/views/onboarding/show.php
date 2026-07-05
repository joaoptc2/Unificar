<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i><?= Sanitize::e($template['name']) ?></h1>
    <a href="index.php?m=rh&page=onboarding" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="card border-0 shadow-sm"><div class="card-body">
    <span class="badge <?= $template['type'] === 'onboarding' ? 'bg-success' : 'bg-warning text-dark' ?> mb-3"><?= ucfirst($template['type']) ?></span>
    <?php if (!empty($template['items'])): ?>
    <ol class="list-group list-group-numbered">
        <?php foreach ($template['items'] as $i): ?>
            <li class="list-group-item d-flex justify-content-between align-items-start">
                <div class="ms-2 me-auto"><div class="fw-semibold"><?= Sanitize::e($i['title']) ?></div><?php if ($i['description']): ?><small class="text-muted"><?= Sanitize::e($i['description']) ?></small><?php endif; ?></div>
                <?php if ($i['responsible_role']): ?><span class="badge bg-primary"><?= ucfirst($i['responsible_role']) ?></span><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php else: ?><p class="text-muted">Nenhum item neste template.</p><?php endif; ?>
</div></div>
