<div class="page-header">
    <h1><i class="bi bi-mortarboard me-2"></i>Treinamentos</h1>
</div>
<?php if (!empty($expiring)): ?>
<div class="alert alert-warning"><i class="bi bi-exclamation-triangle me-1"></i> <strong><?= count($expiring) ?></strong> treinamento(s) vencem nos proximos 30 dias.</div>
<?php endif; ?>

<?php if (Auth::can('trainings', 'create')): ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-header bg-white fw-semibold"><i class="bi bi-plus-circle me-1"></i> Adicionar ao catalogo</div><div class="card-body">
<form method="POST" action="index.php?m=rh&page=trainings&action=store_catalog" class="row g-2 align-items-end">
    <?= Csrf::field() ?>
    <div class="col-md-3"><label class="form-label small">Titulo</label><input type="text" name="title" class="form-control form-control-sm" required></div>
    <div class="col-md-2"><label class="form-label small">Categoria</label><input type="text" name="category" class="form-control form-control-sm" placeholder="NR-32, BLS..."></div>
    <div class="col-md-1"><label class="form-label small">Horas</label><input type="number" name="hours" class="form-control form-control-sm" step="0.5" min="0"></div>
    <div class="col-md-2"><label class="form-label small">Validade (meses)</label><input type="number" name="validity_months" class="form-control form-control-sm" min="0" placeholder="0=sem"></div>
    <div class="col-md-1"><div class="form-check mt-3"><input class="form-check-input" type="checkbox" name="mandatory" value="1"><label class="form-check-label small">Obrig.</label></div></div>
    <div class="col-md-1"><button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-plus-lg"></i></button></div>
</form>
</div></div>
<?php endif; ?>

<div class="card border-0 shadow-sm"><div class="card-header bg-white fw-semibold">Catalogo de Treinamentos</div><div class="card-body p-0">
<div class="table-responsive"><table class="table table-sm table-hover mb-0">
<thead><tr><th>Titulo</th><th>Categoria</th><th>Horas</th><th>Validade</th><th>Obrigatorio</th></tr></thead>
<tbody>
<?php foreach ($catalog as $c): ?>
<tr>
    <td class="fw-semibold"><?= Sanitize::e($c['title']) ?></td>
    <td><?= Sanitize::e($c['category'] ?? '-') ?></td>
    <td><?= $c['hours'] ? number_format((float)$c['hours'], 1) . 'h' : '-' ?></td>
    <td><?= $c['validity_months'] ? $c['validity_months'] . ' meses' : 'Sem vencimento' ?></td>
    <td><?= (int)$c['mandatory'] ? '<span class="badge bg-danger">Sim</span>' : '<span class="badge bg-secondary">Nao</span>' ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($catalog)): ?><tr><td colspan="5" class="text-center text-muted py-4">Catalogo vazio.</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>
