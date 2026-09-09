<?php /** Lista de pesquisas. Variáveis: $surveys (com participants, question_count, department_name). */ ?>
<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i>Pesquisas</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('surveys.create')): ?>
            <a href="index.php?m=rh&page=surveys&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova pesquisa</a>
        <?php endif; ?>
    </div>
</div>
<div class="row g-3">
<?php foreach ($surveys as $s): $open = Survey::isOpen($s); ?>
<div class="col-md-6 col-lg-4">
    <div class="card border-0 shadow-sm h-100">
        <div class="card-body">
            <span class="badge <?= match($s['status']) { 'ativa' => 'bg-success', 'encerrada' => 'bg-secondary', default => 'bg-warning text-dark' } ?>"><?= ucfirst($s['status']) ?></span>
            <span class="badge bg-info text-dark"><?= Sanitize::e(Survey::TYPES[$s['type']] ?? ucfirst($s['type'])) ?></span>
            <?= (int)$s['anonymous'] ? '<span class="badge bg-dark"><i class="bi bi-incognito"></i> Anônima</span>' : '' ?>
            <?= (int)$s['show_in_portal'] ? '<span class="badge bg-light text-dark border">Portal</span>' : '' ?>
            <?php if ((int)$s['send_email']): ?><span class="badge <?= $s['emailed_at'] ? 'bg-success' : 'bg-light text-dark border' ?>"><i class="bi bi-envelope"></i><?= $s['emailed_at'] ? ' ✓' : '' ?></span><?php endif; ?>
            <h6 class="fw-semibold mt-2 mb-1"><?= Sanitize::e($s['title']) ?></h6>
            <small class="text-muted d-block">
                <i class="bi bi-people"></i> <?= $s['department_name'] ? Sanitize::e($s['department_name']) : 'Todos' ?>
                &middot; <?= (int)$s['question_count'] ?> pergunta(s)
                &middot; <?= (int)$s['participants'] ?> resposta(s)
            </small>
            <?php if ($s['starts_at'] || $s['ends_at']): ?><small class="text-muted d-block"><?= Sanitize::formatDate($s['starts_at']) ?> – <?= Sanitize::formatDate($s['ends_at']) ?></small><?php endif; ?>
        </div>
        <div class="card-footer bg-transparent d-flex gap-1">
            <a href="index.php?m=rh&page=surveys&action=show&id=<?= (int)$s['id'] ?>" class="btn btn-outline-primary btn-sm flex-grow-1"><i class="bi bi-bar-chart me-1"></i>Resultados</a>
            <?php if ($open && core_can('my.view')): ?><a href="index.php?m=rh&page=my&action=survey&id=<?= (int)$s['id'] ?>" class="btn btn-primary btn-sm" title="Responder pelo portal"><i class="bi bi-pencil-square"></i></a><?php endif; ?>
            <?php if (core_can('surveys.edit')): ?><a href="index.php?m=rh&page=surveys&action=edit&id=<?= (int)$s['id'] ?>" class="btn btn-outline-warning btn-sm" title="Editar"><i class="bi bi-pencil"></i></a><?php endif; ?>
            <?php if (core_can('surveys.delete')): ?>
                <form method="POST" action="index.php?m=rh&page=surveys&action=delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>"><button type="submit" class="btn btn-outline-danger btn-sm" title="Excluir" data-confirm="Excluir a pesquisa e todas as respostas?"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($surveys)): ?><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhuma pesquisa criada.</div></div></div><?php endif; ?>
</div>
