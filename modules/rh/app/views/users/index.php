<div class="page-header">
    <h1><i class="bi bi-person-badge me-2"></i>Vínculos de usuários</h1>
    <div>
        <a href="<?= core_url('index.php?m=admin&a=users') ?>" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-shield-lock me-1"></i> Usuários (administração central)
        </a>
    </div>
</div>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Criação, edição, senha e permissões dos usuários são gerenciadas na
    <a href="<?= core_url('index.php?m=admin&a=users') ?>" class="alert-link">administração central</a>.
    Aqui você define apenas o vínculo funcional do módulo RH: qual funcionário
    e departamento cada usuário representa.
</div>

<?php // Formulários fora da tabela (HTML válido) — campos associados via atributo form="..." ?>
<?php foreach ($users as $u): ?>
    <form method="POST" action="index.php?m=rh&page=users&action=link" id="linkForm<?= (int)$u['id'] ?>">
        <?= Csrf::field() ?>
        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
    </form>
<?php endforeach; ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Usuário</th>
                        <th>E-mail</th>
                        <th>Permissões no RH</th>
                        <th style="min-width:220px">Funcionário vinculado</th>
                        <th style="min-width:180px">Departamento</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($users)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Nenhum usuário com acesso ao módulo RH.</td></tr>
                <?php else: foreach ($users as $u): ?>
                    <tr>
                        <td>
                            <strong><?= Sanitize::e($u['name']) ?></strong>
                            <?php if (!empty($u['is_admin'])): ?>
                                <span class="badge text-bg-primary ms-1">Admin global</span>
                            <?php endif; ?>
                            <?php if (!(int)$u['active']): ?>
                                <span class="badge text-bg-secondary ms-1">Inativo</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small"><?= Sanitize::e($u['email']) ?></td>
                        <td>
                            <span class="badge text-bg-light border"><?= (int)($u['perm_count'] ?? 0) ?> permissão(ões)</span>
                        </td>
                        <td>
                            <select name="employee_id" form="linkForm<?= (int)$u['id'] ?>" class="form-select form-select-sm">
                                <option value="">— sem vínculo —</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= (int)$emp['id'] ?>" <?= (int)($u['employee_id'] ?? 0) === (int)$emp['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($emp['full_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td>
                            <select name="department_id" form="linkForm<?= (int)$u['id'] ?>" class="form-select form-select-sm">
                                <option value="">—</option>
                                <?php foreach ($departments as $dep): ?>
                                    <option value="<?= (int)$dep['id'] ?>" <?= (int)($u['department_id'] ?? 0) === (int)$dep['id'] ? 'selected' : '' ?>>
                                        <?= Sanitize::e($dep['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="text-end">
                            <button type="submit" form="linkForm<?= (int)$u['id'] ?>" class="btn btn-sm btn-primary">
                                <i class="bi bi-check-lg"></i> Salvar
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="text-muted small mt-3">
    <i class="bi bi-journal-text me-1"></i>
    O log de auditoria do módulo está disponível em
    <a href="<?= core_url('index.php?m=admin&a=audit&module=rh') ?>">Administração &rsaquo; Auditoria</a>.
</div>
