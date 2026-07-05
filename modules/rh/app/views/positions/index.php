<div class="page-header">
    <h1><i class="bi bi-person-workspace me-2"></i>Cargos</h1>
    <?php if (Auth::can('positions', 'create')): ?>
        <a href="index.php?page=positions&action=create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Novo Cargo
        </a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Título</th><th>Departamento</th><th>Funcionários</th><th>Status</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($positions)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum cargo cadastrado.</td></tr>
                    <?php else: foreach ($positions as $p): ?>
                        <tr>
                            <td class="fw-semibold"><?= Sanitize::e($p['title']) ?></td>
                            <td><?= Sanitize::e($p['department_name'] ?? '-') ?></td>
                            <td><span class="badge bg-primary"><?= $p['employee_count'] ?></span></td>
                            <td><span class="badge <?= $p['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td class="text-end">
                                <?php if (Auth::can('positions', 'edit')): ?>
                                    <a href="index.php?page=positions&action=edit&id=<?= $p['id'] ?>" class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (Auth::can('positions', 'delete')): ?>
                                    <form method="POST" action="index.php?page=positions&action=delete" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir cargo?"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
