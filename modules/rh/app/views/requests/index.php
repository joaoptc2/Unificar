<div class="page-header">
    <h1><i class="bi bi-envelope-paper me-2"></i>Solicitacoes</h1>
</div>
<div class="filter-panel">
    <form class="row g-2 align-items-end"><input type="hidden" name="page" value="requests">
        <div class="col-md-3"><select name="status" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (['pendente','em_analise','aprovada','rejeitada'] as $s): ?><option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0">
<thead><tr><th>Funcionario</th><th>Tipo</th><th>Assunto</th><th>Status</th><th>Data</th><th></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
    <td class="fw-semibold"><?= Sanitize::e($r['employee_name']) ?></td>
    <td><span class="badge bg-info"><?= ucfirst(str_replace('_',' ',$r['type'])) ?></span></td>
    <td><?= Sanitize::e($r['subject']) ?></td>
    <td><span class="badge <?= match($r['status']) { 'aprovada' => 'bg-success', 'rejeitada' => 'bg-danger', 'em_analise' => 'bg-warning text-dark', default => 'bg-secondary' } ?>"><?= ucfirst(str_replace('_',' ',$r['status'])) ?></span></td>
    <td><?= Sanitize::formatDateTime($r['created_at']) ?></td>
    <td>
        <?php if (core_can('requests.respond') && $r['status'] === 'pendente'): ?>
        <form method="POST" action="index.php?m=rh&page=requests&action=respond" class="d-inline">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
            <input type="hidden" name="response" value="">
            <button name="status" value="aprovada" class="btn btn-outline-success btn-action" title="Aprovar"><i class="bi bi-check-lg"></i></button>
            <button name="status" value="rejeitada" class="btn btn-outline-danger btn-action" title="Rejeitar"><i class="bi bi-x-lg"></i></button>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhuma solicitacao.</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>
