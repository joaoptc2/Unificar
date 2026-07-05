<div class="page-header">
    <h1><i class="bi bi-people me-2"></i>Gerenciar Usuários</h1>
    <a href="index.php?page=admin" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="filter-panel">
    <form class="row g-2 align-items-end">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="action" value="users">
        <div class="col-md-6">
            <input type="text" name="search" class="form-control" placeholder="Buscar por nome ou e-mail..."
                   value="<?= Sanitize::e($search ?? '') ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-primary w-100">Filtrar</button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr><th>Nome</th><th>E-mail</th><th>Perfil</th><th>Status</th><th>Ativo</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td class="fw-semibold"><?= Sanitize::e($u['name']) ?></td>
                    <td><?= Sanitize::e($u['email']) ?></td>
                    <td>
                        <span class="badge bg-<?= match($u['role']) { 'admin' => 'danger', 'manager' => 'warning', default => 'primary' } ?>-subtle text-<?= match($u['role']) { 'admin' => 'danger', 'manager' => 'warning', default => 'primary' } ?>">
                            <?= match($u['role']) { 'admin' => 'Admin', 'manager' => 'Gestor', default => 'Membro' } ?>
                        </span>
                    </td>
                    <td>
                        <span class="dm-status <?= Sanitize::e($u['status']) ?>"></span>
                        <?= match($u['status']) { 'online' => 'Online', 'away' => 'Ausente', 'dnd' => 'Ocupado', default => 'Offline' } ?>
                    </td>
                    <td>
                        <?php if ($u['is_active']): ?>
                            <span class="badge bg-success-subtle text-success">Sim</span>
                        <?php else: ?>
                            <span class="badge bg-danger-subtle text-danger">Não</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <a href="index.php?page=admin&action=editUser&id=<?= $u['id'] ?>" class="btn btn-outline-warning btn-action">
                            <i class="bi bi-pencil"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pagination->totalPages > 1): ?>
    <div class="card-footer bg-transparent"><?= $pagination->render('&page=admin&action=users' . ($search ? '&search=' . urlencode($search) : '')) ?></div>
    <?php endif; ?>
</div>
