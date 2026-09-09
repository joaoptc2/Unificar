<div class="page-header">
    <h1 class="h5"><i class="bi bi-building me-2"></i>Departamentos</h1>
    <?php if (core_can('departments.create')): ?>
        <a href="<?= core_admin_url('rh', 'departments', ['action' => 'create']) ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Novo Departamento
        </a>
    <?php endif; ?>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Nome</th><th>Descrição</th><th>Funcionários</th><th>Status</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                    <?php if (empty($departments)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Nenhum departamento cadastrado.</td></tr>
                    <?php else: foreach ($departments as $d): ?>
                        <tr>
                            <td class="fw-semibold"><?= Sanitize::e($d['name']) ?></td>
                            <td class="small text-muted"><?= Sanitize::e($d['description'] ?: '-') ?></td>
                            <td><span class="badge bg-primary"><?= $d['employee_count'] ?></span></td>
                            <td><span class="badge <?= $d['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $d['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td class="text-end">
                                <?php if (core_can('departments.edit')): ?>
                                    <a href="<?= core_admin_url('rh', 'departments', ['action' => 'edit', 'id' => (int)$d['id']]) ?>" class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (core_can('departments.delete')): ?>
                                    <form method="POST" action="index.php?m=rh&page=departments&action=delete" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $d['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir departamento?"><i class="bi bi-trash"></i></button>
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
