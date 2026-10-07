<?php /** Solicitações de vacina. Variáveis: $items, $status. */ $canEdit = core_can('vaccines.edit'); ?>
<div class="page-header">
    <h1><i class="bi bi-envelope-paper me-2"></i>Solicitações de vacina</h1>
    <a href="index.php?m=rh&page=vaccines" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Painel de vacinas</a>
</div>
<div class="filter-panel">
    <form class="row g-2 align-items-end"><input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="vaccines"><input type="hidden" name="action" value="requests">
        <div class="col-md-3"><label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">Todas</option><?php foreach (VaccineRequest::STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle">
<thead class="table-light"><tr><th>Funcionário</th><th>Vacina</th><th>Tipo</th><th>Prazo</th><th>Status</th><th>Aberta em</th><th class="text-end"></th></tr></thead>
<tbody>
<?php foreach ($items as $r): ?>
<tr>
    <td><a href="index.php?m=rh&page=employees&action=show&id=<?= (int) $r['employee_id'] ?>#tabVacinas" class="fw-semibold text-decoration-none"><?= Sanitize::e($r['employee_name']) ?></a><?= $r['department_name'] ? '<small class="text-muted d-block">' . Sanitize::e($r['department_name']) . '</small>' : '' ?></td>
    <td><?= Sanitize::e($r['vaccine_name']) ?></td>
    <td><span class="badge <?= $r['kind'] === 'pendente' ? 'bg-secondary' : 'bg-warning text-dark' ?>"><?= $r['kind'] === 'pendente' ? 'Regularização' : 'Renovação' ?></span><?= $r['requested_by'] ? '' : '<small class="text-muted d-block">automática</small>' ?></td>
    <td class="small"><?= $r['due_date'] ? Sanitize::formatDate($r['due_date']) : '—' ?></td>
    <td><span class="badge <?= VaccineRequest::badge($r['status']) ?>"><?= VaccineRequest::STATUS_LABELS[$r['status']] ?></span></td>
    <td class="small text-muted text-nowrap"><?= Sanitize::formatDateTime($r['created_at']) ?></td>
    <td class="text-end text-nowrap">
        <?php if ($canEdit && in_array($r['status'], ['aberta', 'enviada'], true)): ?>
        <form method="POST" action="index.php?m=rh&page=vaccines&action=cancel_request" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <button class="btn btn-outline-secondary btn-action" title="Cancelar" data-confirm="Cancelar esta solicitação?"><i class="bi bi-x-lg"></i></button></form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma solicitação.</td></tr><?php endif; ?>
</tbody></table></div></div></div>
