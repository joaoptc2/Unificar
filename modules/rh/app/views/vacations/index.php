<div class="page-header">
    <h1><i class="bi bi-sun me-2"></i>Ferias</h1>
    <div class="d-flex gap-2">
        <?php if (Auth::can('vacations', 'create')): ?>
            <a href="index.php?page=vacations&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Nova</a>
        <?php endif; ?>
    </div>
</div>
<div class="filter-panel">
    <form class="row g-2 align-items-end">
        <input type="hidden" name="page" value="vacations">
        <div class="col-md-3">
            <select name="status" class="form-select form-select-sm">
                <option value="">Todos os status</option>
                <?php foreach (['planejada','solicitada','aprovada','em_gozo','concluida','rejeitada'] as $s): ?>
                    <option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Funcionario</th><th>Departamento</th><th>Inicio</th><th>Fim</th><th>Dias</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php if (empty($vacations)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Nenhum registro.</td></tr>
                <?php else: foreach ($vacations as $v): ?>
                    <tr>
                        <td class="fw-semibold"><?= Sanitize::e($v['employee_name']) ?></td>
                        <td><?= Sanitize::e($v['department_name'] ?? '-') ?></td>
                        <td><?= Sanitize::formatDate($v['start_date']) ?></td>
                        <td><?= Sanitize::formatDate($v['end_date']) ?></td>
                        <td><?= (int)$v['days'] ?></td>
                        <td><span class="badge <?= match($v['status']) { 'aprovada','em_gozo','concluida' => 'bg-success', 'rejeitada' => 'bg-danger', 'solicitada' => 'bg-warning text-dark', default => 'bg-secondary' } ?>"><?= ucfirst($v['status']) ?></span></td>
                        <td class="text-end">
                            <?php if (Auth::can('vacations', 'edit') && in_array($v['status'], ['planejada','solicitada'])): ?>
                                <a href="index.php?page=vacations&action=edit&id=<?= $v['id'] ?>" class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                                <form method="POST" action="index.php?page=vacations&action=approve" class="d-inline">
                                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= $v['id'] ?>">
                                    <button name="decision" value="aprovar" class="btn btn-outline-success btn-action" title="Aprovar"><i class="bi bi-check-lg"></i></button>
                                    <button name="decision" value="rejeitar" class="btn btn-outline-danger btn-action" title="Rejeitar" data-confirm="Rejeitar estas ferias?"><i class="bi bi-x-lg"></i></button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if (isset($pagination) && $pagination->totalPages > 1): ?>
        <div class="card-footer bg-transparent"><?= $pagination->render() ?></div>
    <?php endif; ?>
</div>
