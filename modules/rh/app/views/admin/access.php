<?php
/** Aba "Acessos dos funcionários" — Administração central. Espera $rows, $withAccess, $without. */
$canManage = core_can('employee_access.manage');
$actUrl = fn (string $a) => core_admin_url('rh', 'access', ['action' => $a]);
?>
<div class="page-header">
    <h1 class="h5"><i class="bi bi-person-lock me-2"></i>Acessos dos funcionários</h1>
    <?php if ($canManage && $without > 0): ?>
        <form method="POST" action="<?= $actUrl('create_all') ?>">
            <?= Csrf::field() ?>
            <button type="submit" class="btn btn-primary btn-sm"
                    data-confirm="Criar o acesso padrão (login = CPF, senha = data de nascimento) para <?= (int)$without ?> funcionário(s) sem login?">
                <i class="bi bi-people-fill me-1"></i> Criar acessos padrão para todos sem login (<?= (int)$without ?>)
            </button>
        </form>
    <?php endif; ?>
</div>

<div class="alert alert-info small py-2">
    <i class="bi bi-info-circle me-1"></i>
    Login padrão do funcionário: <strong>CPF</strong> (com ou sem pontuação) · senha inicial: <strong>data de nascimento</strong> no formato
    <code>ddmmaaaa</code>, com troca obrigatória no primeiro acesso. O acesso recebe o preset de permissões "Funcionário" (portal, solicitações,
    comunicados, pesquisas e brindes).
</div>

<div class="row g-3 mb-3">
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-success"><?= (int)$withAccess ?></div><div class="stat-label">Com acesso</div></div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body text-center"><div class="stat-value text-warning"><?= (int)$without ?></div><div class="stat-label">Sem acesso</div></div></div></div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr><th>Funcionário</th><th>Departamento</th><th>Login (CPF)</th><th>Situação</th><th>Último acesso</th><th class="text-end">Ações</th></tr>
                </thead>
                <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum funcionário ativo.</td></tr>
                <?php else: foreach ($rows as $r): ?>
                    <tr>
                        <td>
                            <a href="<?= core_module_url('rh', ['page' => 'employees', 'action' => 'show', 'id' => (int)$r['id']]) ?>" class="fw-semibold text-decoration-none"><?= Sanitize::e($r['full_name']) ?></a>
                            <?php if ($r['user_email'] && !EmployeeAccess::isPlaceholderEmail($r['user_email'])): ?>
                                <div class="text-muted small"><?= Sanitize::e($r['user_email']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= Sanitize::e($r['department_name'] ?? '-') ?></td>
                        <td><code><?= Sanitize::formatCpf($r['cpf']) ?></code></td>
                        <td>
                            <?php if (!$r['user_id']): ?>
                                <span class="badge bg-warning text-dark">Sem acesso</span>
                            <?php elseif (!(int)$r['user_active']): ?>
                                <span class="badge bg-secondary">Usuário inativo</span>
                            <?php elseif ((int)$r['force_password_change']): ?>
                                <span class="badge bg-info text-dark">Senha padrão (troca pendente)</span>
                            <?php else: ?>
                                <span class="badge bg-success">Ativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?= $r['last_login_at'] ? Sanitize::formatDateTime($r['last_login_at']) : '—' ?></td>
                        <td class="text-end text-nowrap">
                            <?php if ($canManage): ?>
                                <?php if (!$r['user_id']): ?>
                                    <form method="POST" action="<?= $actUrl('ensure') ?>" class="d-inline">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-outline-primary btn-sm" title="Criar acesso padrão"><i class="bi bi-person-plus me-1"></i>Criar acesso</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="<?= $actUrl('reset') ?>" class="d-inline">
                                        <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                        <button type="submit" class="btn btn-outline-warning btn-sm" title="Redefinir senha padrão"
                                                data-confirm="Redefinir a senha de <?= Sanitize::e($r['full_name']) ?> para a data de nascimento?"><i class="bi bi-key me-1"></i>Redefinir senha</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
