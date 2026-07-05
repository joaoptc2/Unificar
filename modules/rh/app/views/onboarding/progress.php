<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Checklist: <?= Sanitize::e($employee['full_name']) ?></h1>
    <a href="index.php?page=employees&action=show&id=<?= $employee['id'] ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="mb-3">
    <div class="progress" style="height:24px">
        <?php $pct = $total > 0 ? round(($completed / $total) * 100) : 0; ?>
        <div class="progress-bar bg-success" style="width:<?= $pct ?>%"><?= $completed ?>/<?= $total ?> (<?= $pct ?>%)</div>
    </div>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
<div class="table-responsive">
    <table class="table table-sm table-hover mb-0">
        <thead><tr><th style="width:50px"></th><th>Item</th><th>Template</th><th>Responsavel</th><th>Concluido por</th><th>Data</th></tr></thead>
        <tbody>
        <?php foreach ($items as $i): ?>
            <tr class="<?= (int)$i['completed'] ? 'table-success' : '' ?>">
                <td class="text-center">
                    <?php if (Auth::can('onboarding', 'edit')): ?>
                        <form method="POST" action="index.php?page=onboarding&action=toggle_item" class="d-inline">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="progress_id" value="<?= $i['id'] ?>">
                            <input type="hidden" name="employee_id" value="<?= $employee['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= (int)$i['completed'] ? 'btn-success' : 'btn-outline-secondary' ?>">
                                <i class="bi <?= (int)$i['completed'] ? 'bi-check-square' : 'bi-square' ?>"></i>
                            </button>
                        </form>
                    <?php else: ?>
                        <i class="bi <?= (int)$i['completed'] ? 'bi-check-square text-success' : 'bi-square text-muted' ?>"></i>
                    <?php endif; ?>
                </td>
                <td class="fw-semibold"><?= Sanitize::e($i['item_title']) ?><?php if ($i['item_desc']): ?><br><small class="text-muted"><?= Sanitize::e($i['item_desc']) ?></small><?php endif; ?></td>
                <td><span class="badge <?= $i['template_type'] === 'onboarding' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= Sanitize::e($i['template_name']) ?></span></td>
                <td><?= Sanitize::e($i['responsible_role'] ? ucfirst($i['responsible_role']) : 'Qualquer') ?></td>
                <td><?= Sanitize::e($i['completed_by_name'] ?? '-') ?></td>
                <td><?= $i['completed_at'] ? Sanitize::formatDateTime($i['completed_at']) : '-' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div></div>
