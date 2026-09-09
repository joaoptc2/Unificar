<?php
/**
 * Seção "Acesso ao sistema" — usada na ficha (show.php) e no formulário de
 * edição (form.php). Espera: $employee, $portalUser (array|null),
 * $unlinkedUsers (array). Ações exigem employees.edit.
 */
$canEdit = core_can('employees.edit') && empty($employee['anonymized_at']);
$loginPadrao = Sanitize::formatCpf($employee['cpf'] ?? '');
$senhaPadrao = EmployeeAccess::defaultPassword($employee);
?>
<div class="card border-0 shadow-sm mb-3" id="acesso">
    <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
        <span><i class="bi bi-person-lock me-1"></i> Acesso ao sistema</span>
        <?php if ($portalUser): ?>
            <span class="badge <?= (int)$portalUser['active'] ? 'bg-success' : 'bg-secondary' ?>"><?= (int)$portalUser['active'] ? 'Ativo' : 'Inativo' ?></span>
        <?php else: ?>
            <span class="badge bg-warning text-dark">Sem acesso</span>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <?php if ($portalUser): ?>
            <div class="row g-2 mb-3">
                <div class="col-md-4"><small class="text-muted">Usuário (login)</small><div><code><?= Sanitize::e($portalUser['username']) ?></code></div></div>
                <div class="col-md-4"><small class="text-muted">Nome</small><div><?= Sanitize::e($portalUser['name']) ?></div></div>
                <div class="col-md-4"><small class="text-muted">E-mail</small><div class="text-truncate"><?= EmployeeAccess::isPlaceholderEmail($portalUser['email']) ? '<span class="text-muted">— (sem e-mail)</span>' : Sanitize::e($portalUser['email']) ?></div></div>
                <div class="col-md-4"><small class="text-muted">Último acesso</small><div><?= $portalUser['last_login_at'] ? Sanitize::formatDateTime($portalUser['last_login_at']) : 'Nunca acessou' ?></div></div>
                <div class="col-md-4"><small class="text-muted">Senha</small><div><?= (int)$portalUser['force_password_change'] ? '<span class="badge bg-warning text-dark">Troca obrigatória no próximo login</span>' : '<span class="badge bg-light text-dark border">Definida pelo usuário</span>' ?></div></div>
                <div class="col-md-4"><small class="text-muted">Perfil</small><div><?= (int)$portalUser['is_admin'] ? '<span class="badge bg-primary">Administrador global</span>' : '<span class="badge bg-light text-dark border">Funcionário (portal)</span>' ?></div></div>
            </div>
            <?php if ($canEdit): ?>
                <div class="d-flex flex-wrap gap-2">
                    <form method="POST" action="index.php?m=rh&page=employees&action=reset_password" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                        <button type="submit" class="btn btn-outline-warning btn-sm"
                                data-confirm="Redefinir a senha deste usuário para a senha padrão (data de nascimento <?= Sanitize::e($senhaPadrao) ?>)?">
                            <i class="bi bi-key me-1"></i> Redefinir senha padrão
                        </button>
                    </form>
                    <?php if (core_can('employee_access.manage') || Core\Auth::isGlobalAdmin()): ?>
                        <a href="<?= core_url('index.php?m=admin&a=user_form&id=' . (int)$portalUser['id']) ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-shield-lock me-1"></i> Usuário na administração central
                        </a>
                    <?php endif; ?>
                </div>
                <small class="text-muted d-block mt-2">Login padrão: <code><?= Sanitize::e($loginPadrao) ?></code> (CPF) · senha padrão: data de nascimento no formato <code>ddmmaaaa</code>.</small>
            <?php endif; ?>
        <?php else: ?>
            <p class="text-muted mb-3">Este funcionário ainda não possui usuário para acessar o portal.</p>
            <?php if ($canEdit): ?>
                <div class="row g-3">
                    <div class="col-md-6">
                        <form method="POST" action="index.php?m=rh&page=employees&action=create_access">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="bi bi-person-plus me-1"></i> Criar acesso padrão
                            </button>
                            <small class="text-muted d-block mt-2">Login <code><?= Sanitize::e($loginPadrao) ?></code> (CPF) · senha inicial <code><?= Sanitize::e($senhaPadrao) ?></code> (nascimento), com troca obrigatória no primeiro acesso.</small>
                        </form>
                    </div>
                    <div class="col-md-6">
                        <form method="POST" action="index.php?m=rh&page=employees&action=link_user" class="d-flex gap-2 align-items-start">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="id" value="<?= (int)$employee['id'] ?>">
                            <select name="user_id" class="form-select form-select-sm" required>
                                <option value="">Vincular a um usuário existente…</option>
                                <?php foreach ($unlinkedUsers as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= Sanitize::e($u['name']) ?> (<?= Sanitize::e($u['username']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit" class="btn btn-outline-primary btn-sm text-nowrap"><i class="bi bi-link-45deg"></i> Vincular</button>
                        </form>
                        <small class="text-muted d-block mt-2">Somente usuários ativos ainda sem vínculo com funcionário.</small>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
