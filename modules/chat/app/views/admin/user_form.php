<div class="page-header">
    <h1><i class="bi bi-person me-2"></i>Editar Usuário</h1>
    <a href="index.php?page=admin&action=users" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=admin&action=updateUser">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $editUser['id'] ?>">

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" class="form-control" required
                                   value="<?= Sanitize::e($editUser['name']) ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label required">E-mail</label>
                            <input type="email" name="email" class="form-control" required
                                   value="<?= Sanitize::e($editUser['email']) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Perfil</label>
                            <select name="role" class="form-select">
                                <option value="member" <?= $editUser['role'] === 'member' ? 'selected' : '' ?>>Membro</option>
                                <option value="manager" <?= $editUser['role'] === 'manager' ? 'selected' : '' ?>>Gestor</option>
                                <option value="admin" <?= $editUser['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Status</label>
                            <select name="is_active" class="form-select">
                                <option value="1" <?= $editUser['is_active'] ? 'selected' : '' ?>>Ativo</option>
                                <option value="0" <?= !$editUser['is_active'] ? 'selected' : '' ?>>Inativo</option>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <a href="index.php?page=admin&action=users" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> Salvar
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
