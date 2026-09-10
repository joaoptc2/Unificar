<?php /** Solicitações (RH). Variáveis: $items (com dados das férias vinculadas), $status, $type. */
$canRespond = core_can('requests.respond');
?>
<div class="page-header">
    <h1><i class="bi bi-envelope-paper me-2"></i>Solicitações</h1>
    <?php if (core_can('my.view')): ?><a href="index.php?m=rh&page=my#solicitacoes" class="btn btn-outline-secondary btn-sm"><i class="bi bi-person-badge me-1"></i> Minhas solicitações (portal)</a><?php endif; ?>
</div>
<div class="filter-panel">
    <form class="row g-2 align-items-end"><input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="requests">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (EmployeeRequest::STATUS_LABELS as $s => $lbl): ?><option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= Sanitize::e($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select name="type" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (EmployeeRequest::TYPES as $k => $label): ?><option value="<?= $k ?>" <?= ($type ?? '') === $k ? 'selected' : '' ?>><?= $label ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle">
<thead class="table-light"><tr><th>Funcionário</th><th>Tipo</th><th>Assunto / detalhes</th><th>Status</th><th>Data</th><th class="text-end">Ações</th></tr></thead>
<tbody>
<?php foreach ($items as $r): $isVac = $r['type'] === 'ferias' && !empty($r['vacation_id']); ?>
<tr>
    <td class="fw-semibold"><?= Sanitize::e($r['employee_name']) ?><?= $r['department_name'] ? '<small class="text-muted d-block">' . Sanitize::e($r['department_name']) . '</small>' : '' ?></td>
    <td><span class="badge <?= $r['type'] === 'ferias' ? 'bg-warning text-dark' : 'bg-info text-dark' ?>"><?= Sanitize::e(EmployeeRequest::TYPES[$r['type']] ?? ucfirst($r['type'])) ?></span></td>
    <td>
        <div><?= Sanitize::e($r['subject']) ?></div>
        <?php if ($isVac): ?>
            <small class="text-muted d-block">
                <i class="bi bi-sun me-1"></i>Gozo: <strong><?= Sanitize::formatDate($r['vac_start']) ?> a <?= Sanitize::formatDate($r['vac_end']) ?></strong> (<?= (int)$r['vac_days'] ?> dias<?= (int)$r['vac_sold'] ? ', ' . (int)$r['vac_sold'] . ' vendidos' : '' ?>)
                · aquisitivo <?= Sanitize::formatDate($r['vac_pstart']) ?> – <?= Sanitize::formatDate($r['vac_pend']) ?>
                · férias: <span class="badge bg-light text-dark border"><?= ucfirst((string)$r['vac_status']) ?></span>
            </small>
        <?php endif; ?>
        <?php if (!empty($r['body'])): ?>
            <details class="small text-muted"><summary>Detalhes</summary><div class="mt-1"><?= nl2br(Sanitize::e($r['body'])) ?></div></details>
        <?php endif; ?>
        <?php if (!empty($r['response'])): ?>
            <small class="text-primary d-block"><i class="bi bi-reply me-1"></i><?= Sanitize::e($r['response']) ?><?= $r['responded_by_name'] ? ' — ' . Sanitize::e($r['responded_by_name']) : '' ?></small>
        <?php endif; ?>
    </td>
    <td><span class="badge <?= match($r['status']) { 'aprovada' => 'bg-success', 'rejeitada' => 'bg-danger', 'em_analise' => 'bg-info text-dark', default => 'bg-warning text-dark' } ?>"><?= Sanitize::e(EmployeeRequest::STATUS_LABELS[$r['status']] ?? ucfirst(str_replace('_', ' ', $r['status']))) ?></span>
        <?php if ($r['responded_at']): ?><small class="text-muted d-block"><?= Sanitize::formatDateTime($r['responded_at']) ?></small><?php endif; ?></td>
    <td class="small text-muted text-nowrap"><?= Sanitize::formatDateTime($r['created_at']) ?></td>
    <td class="text-end text-nowrap">
        <?php if ($canRespond && in_array($r['status'], ['pendente', 'em_analise'], true)): ?>
        <form method="POST" action="index.php?m=rh&page=requests&action=respond" class="d-inline-flex gap-1 align-items-center">
            <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="text" name="response" class="form-control form-control-sm" placeholder="Resposta (opcional)" maxlength="2000" style="width:170px">
            <button name="status" value="aprovada" class="btn btn-outline-success btn-action" title="Aprovar<?= $isVac ? ' (aprova as férias)' : '' ?>"><i class="bi bi-check-lg"></i></button>
            <button name="status" value="rejeitada" class="btn btn-outline-danger btn-action" title="Rejeitar<?= $isVac ? ' (rejeita as férias)' : '' ?>" data-confirm="Rejeitar esta solicitação?"><i class="bi bi-x-lg"></i></button>
            <?php if ($r['status'] === 'pendente'): ?><button name="status" value="em_analise" class="btn btn-outline-info btn-action" title="Marcar em análise"><i class="bi bi-hourglass-split"></i></button><?php endif; ?>
        </form>
        <?php endif; ?>
    </td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?><tr><td colspan="6" class="text-center text-muted py-4">Nenhuma solicitação.</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>
