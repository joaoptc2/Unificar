<?php /** Vacinas — painel geral. Variáveis: $rows, $catalog, $totais, $departments, $fStatus, $fVac, $fDept, $pendingValidation. */
$canEdit = core_can('vaccines.edit');
$visiveis = array_filter($catalog, fn ($v) => (int) $v['applies_to_all'] === 1);
?>
<div class="page-header">
    <h1><i class="bi bi-shield-plus me-2"></i>Vacinas dos funcionários</h1>
    <div class="d-flex gap-2">
        <a href="index.php?m=rh&page=vaccines&action=requests" class="btn btn-outline-primary btn-sm"><i class="bi bi-envelope-paper me-1"></i> Solicitações
            <?php if ($pendingValidation): ?><span class="badge bg-info text-dark"><?= (int) $pendingValidation ?> p/ validar</span><?php endif; ?></a>
        <?php if (core_can('vaccines.config')): ?><a href="<?= core_admin_url('rh', 'vaccines') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-list-ul me-1"></i> Catálogo</a><?php endif; ?>
        <?php if ($canEdit): ?>
        <form method="POST" action="index.php?m=rh&page=vaccines&action=request_all" class="d-inline">
            <?= Csrf::field() ?>
            <button class="btn btn-warning btn-sm" data-confirm="Enviar solicitação de renovação a TODOS os funcionários com vacinas vencidas ou vencendo?"><i class="bi bi-send me-1"></i> Solicitar renovações</button>
        </form>
        <?php endif; ?>
    </div>
</div>

<div class="row g-2 mb-3">
    <?php foreach (['vencida' => 'danger', 'vencendo' => 'warning', 'incompleta' => 'info', 'pendente' => 'secondary', 'em_dia' => 'success'] as $k => $cor): ?>
    <div class="col-6 col-md"><a class="card stat-card shadow-sm text-decoration-none" href="index.php?m=rh&page=vaccines&status=<?= $k ?>">
        <div class="card-body py-2 text-center"><div class="stat-value text-<?= $cor ?> fs-4"><?= (int) $totais[$k] ?></div><div class="stat-label"><?= Vaccine::STATUS_LABELS[$k] ?></div></div></a></div>
    <?php endforeach; ?>
</div>

<div class="filter-panel">
    <form class="row g-2 align-items-end"><input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="vaccines">
        <div class="col-md-3"><label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (Vaccine::STATUS_LABELS as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label">Vacina</label>
            <select name="vaccine_id" class="form-select form-select-sm"><option value="">Qualquer</option><?php foreach ($catalog as $v): ?><option value="<?= (int) $v['id'] ?>" <?= $fVac === (int) $v['id'] ? 'selected' : '' ?>><?= Sanitize::e($v['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">Departamento</label>
            <select name="department_id" class="form-select form-select-sm"><option value="">Todos</option><?php foreach ($departments as $d): ?><option value="<?= (int) $d['id'] ?>" <?= $fDept === (int) $d['id'] ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><button class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>

<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle" style="font-size:.85rem">
<thead class="table-light"><tr><th>Funcionário</th>
    <?php foreach ($visiveis as $v): ?><th class="text-center" title="<?= Sanitize::e($v['name']) ?>"><?= Sanitize::e(mb_strimwidth($v['name'], 0, 14, '…')) ?></th><?php endforeach; ?>
    <th class="text-center">Outras</th></tr></thead>
<tbody>
<?php foreach ($rows as $r): $e = $r['employee']; ?>
<tr>
    <td class="text-nowrap"><a href="index.php?m=rh&page=employees&action=show&id=<?= (int) $e['id'] ?>#tabVacinas" class="fw-semibold text-decoration-none"><?= Sanitize::e($e['full_name']) ?></a>
        <?php if ($e['department_name']): ?><small class="text-muted d-block"><?= Sanitize::e($e['department_name']) ?></small><?php endif; ?></td>
    <?php foreach ($visiveis as $v): $ev = $r['eval'][(int) $v['id']]; ?>
        <td class="text-center"><span class="badge <?= Vaccine::badge($ev['status']) ?>" title="<?= Sanitize::e(Vaccine::STATUS_LABELS[$ev['status']]) ?><?= $ev['valid_until'] ? ' · válida até ' . Sanitize::formatDate($ev['valid_until']) : '' ?>"><?= $ev['status'] === 'nao_aplica' ? 'n/a' : ($ev['status'] === 'incompleta' ? $ev['doses'] . '/' . $ev['doses_total'] : ($ev['status'] === 'em_dia' ? '✓' : ($ev['status'] === 'pendente' ? '—' : ($ev['status'] === 'vencendo' ? '!' : '✗')))) ?></span></td>
    <?php endforeach; ?>
    <td class="text-center small text-muted"><?php $outras = array_filter($r['eval'], fn ($x) => (int) $x['vaccine']['applies_to_all'] === 0 && $x['status'] !== 'nao_aplica'); echo $outras ? count($outras) : '—'; ?></td>
</tr>
<?php endforeach; ?>
<?php if (empty($rows)): ?><tr><td colspan="<?= count($visiveis) + 2 ?>" class="text-center text-muted py-4">Nenhum funcionário com esses filtros.</td></tr><?php endif; ?>
</tbody></table></div></div>
<div class="card-footer small text-muted">Legenda: ✓ em dia · ! vencendo (≤ <?= Vaccine::ALERT_DAYS ?> dias; reforços de 5/10 anos: ≤ <?= Vaccine::ALERT_DAYS_LONG ?>) · ✗ vencida · n/n esquema incompleto · — pendente · n/a não se aplica (dispensa).</div></div>
