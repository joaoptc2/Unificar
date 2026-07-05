<div class="page-header">
    <h1><i class="bi bi-person-circle me-2"></i>Meu Perfil</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('profile/change-password'); ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-key me-1"></i>Alterar senha
        </a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-person me-1"></i>Dados pessoais</div>
            <div class="card-body">
                <form method="POST" action="<?php echo url('profile/update'); ?>">
                    <?php echo csrf_field(); ?>
                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" name="name" class="form-control" required
                               value="<?php echo e($user['name'] ?? ''); ?>">
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">E-mail</label>
                        <input type="email" name="email" class="form-control" required
                               value="<?php echo e($user['email'] ?? ''); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg me-1"></i>Salvar
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-info-circle me-1"></i>Conta</div>
            <div class="card-body">
                <table class="table table-sm table-borderless mb-0">
                    <?php if (get_sector_name()): ?>
                    <tr><th class="text-muted">Setor</th><td><?php echo e(get_sector_name()); ?></td></tr>
                    <?php endif; ?>
                    <tr><th class="text-muted">Perfil</th><td>
                        <?php $roles = [1 => 'Administrador Global', 2 => 'Gestor', 3 => 'Operador'];
                              echo e($roles[$user['role_id'] ?? 0] ?? '—'); ?>
                    </td></tr>
                    <tr><th class="text-muted">Último acesso</th>
                        <td><?php echo format_datetime($user['last_login'] ?? null); ?></td></tr>
                    <tr><th class="text-muted">Cadastrado em</th>
                        <td><?php echo format_datetime($user['created_at'] ?? null); ?></td></tr>
                </table>
            </div>
        </div>
    </div>
</div>
