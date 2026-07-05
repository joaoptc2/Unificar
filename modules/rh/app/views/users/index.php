<div class="page-header">
    <h1><i class="bi bi-people-fill me-2"></i>Usuários do Sistema</h1>
    <div class="d-flex gap-2">
        <a href="index.php?page=users&action=audit_log" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-journal-text me-1"></i> Log de Auditoria
        </a>
        <?php if (Auth::can('users', 'create')): ?>
            <a href="index.php?page=users&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo Usuário
            </a>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Última Ação</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="fw-semibold"><?= Sanitize::e($u['name']) ?></td>
                            <td><?= Sanitize::e($u['email']) ?></td>
                            <td>
                                <span class="badge <?= match($u['role']) {
                                    'admin' => 'bg-danger',
                                    'rh' => 'bg-primary',
                                    'gestor' => 'bg-warning text-dark',
                                    default => 'bg-secondary'
                                } ?>">
                                    <?= ucfirst($u['role']) ?>
                                </span>
                            </td>
                            <td><span class="badge <?= $u['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= $u['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                            <td><small class="text-muted"><?= $u['last_action'] ? date('d/m/Y H:i', strtotime($u['last_action'])) : '-' ?></small></td>
                            <td class="text-end">
                                <?php if (Auth::can('users', 'edit')): ?>
                                    <a href="index.php?page=users&action=edit&id=<?= $u['id'] ?>" class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (Auth::can('users', 'delete') && $u['id'] !== Session::userId()): ?>
                                    <form method="POST" action="index.php?page=users&action=delete" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir usuário?"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
