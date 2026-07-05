<?php
$role_names  = [1 => 'Admin Global', 2 => 'Gestor', 3 => 'Operador'];
$role_colors = [1 => 'danger', 2 => 'warning', 3 => 'info'];

// Pré-carrega setores de cada usuário para exibir na tabela
$user_sector_map = [];
foreach ($users as $u) {
    try {
        $user_sector_map[$u['id']] = user_sector_list($u['id']);
    } catch (Exception $ex) {
        $user_sector_map[$u['id']] = [];
    }
}
?>
<div class="page-header">
    <h1><i class="bi bi-people me-2"></i>Gerenciar Usuários</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalUser" onclick="clearUserForm()">
            <i class="bi bi-plus-lg me-1"></i>Novo Usuário
        </button>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<div class="filter-panel mb-3">
    <form method="GET" action="<?php echo url('admin/users'); ?>" class="row g-2 align-items-end">
        <div class="col-md-8">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Nome ou e-mail..." value="<?php echo e($search ?? ''); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i>Buscar
            </button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($users)): ?>
            <div class="text-center py-5">
                <i class="bi bi-people display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhum usuário cadastrado.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Setores</th>
                            <th>Perfil</th>
                            <th>Último Login</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $role_name  = $role_names[$u['role_id']]  ?? 'Operador';
                        $role_color = $role_colors[$u['role_id']] ?? 'secondary';
                        $u_sectors  = $user_sector_map[$u['id']]  ?? [];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo e($u['name']); ?></div>
                                <small class="text-muted"><?php echo e($u['email']); ?></small>
                            </td>
                            <td>
                                <?php if (empty($u_sectors)): ?>
                                    <span class="text-muted small">Nenhum</span>
                                <?php else: ?>
                                    <?php foreach ($u_sectors as $us): ?>
                                        <span class="badge bg-light text-dark" style="font-size:.7rem"><?php echo e($us['name']); ?></span>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?php echo $role_color; ?>"><?php echo $role_name; ?></span></td>
                            <td class="small"><?php echo $u['last_login'] ? format_datetime($u['last_login']) : '—'; ?></td>
                            <td>
                                <span class="badge <?php echo $u['is_active'] ? 'badge-ativo' : 'badge-desligado'; ?>">
                                    <?php echo $u['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-warning btn-action"
                                        onclick="editUser(<?php echo e(json_encode($u)); ?>, <?php echo e(json_encode(array_column($u_sectors, 'id'))); ?>)"
                                        data-bs-toggle="tooltip" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php if ((int) $u['id'] !== get_user_id()): ?>
                                <form method="POST" action="<?php echo url('admin/user_delete'); ?>" class="d-inline"
                                      data-confirm="Remover este usuário?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $u['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!empty($pagination) && $pagination['pages'] > 1): ?>
            <div class="card-footer bg-transparent">
                <?php echo pagination_html($pagination); ?>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Usuário -->
<div class="modal fade" id="modalUser" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="userForm" method="POST" action="<?php echo url('admin/user_store'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="user_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold" id="userModalTitle">Novo Usuário</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" class="form-control" name="name" id="u_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required">E-mail</label>
                        <input type="email" class="form-control" name="email" id="u_email" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label required" id="passLabel">Senha</label>
                        <input type="password" class="form-control" name="password" id="u_password">
                        <small class="text-muted" id="passHint" style="display:none">Deixe em branco para manter a atual</small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Perfil</label>
                        <select class="form-select" name="role_id" id="u_role">
                            <?php if (is_admin()): ?>
                                <option value="1">Admin Global</option>
                            <?php endif; ?>
                            <option value="2">Gestor</option>
                            <option value="3" selected>Operador</option>
                        </select>
                    </div>

                    <!-- Setores -->
                    <?php if (!empty($all_sectors)): ?>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Setores</label>
                        <div class="border rounded p-2" style="max-height:150px; overflow-y:auto">
                            <?php foreach ($all_sectors as $sec): ?>
                            <div class="form-check">
                                <input class="form-check-input sector-check" type="checkbox"
                                       name="sector_ids[]" value="<?php echo (int) $sec['id']; ?>"
                                       id="sec_<?php echo (int) $sec['id']; ?>">
                                <label class="form-check-label small" for="sec_<?php echo (int) $sec['id']; ?>">
                                    <?php echo e($sec['name']); ?>
                                    <?php if (!empty($sec['code'])): ?>
                                        <span class="text-muted">(<?php echo e($sec['code']); ?>)</span>
                                    <?php endif; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <small class="text-muted">Selecione os setores que este usuário pode acessar.</small>
                    </div>
                    <?php endif; ?>

                    <div class="mb-3" id="userStatusField" style="display:none">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active" id="u_active">
                            <option value="1">Ativo</option>
                            <option value="0">Inativo</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function clearUserForm() {
    document.getElementById('userForm').action = '<?php echo url('admin/user_store'); ?>';
    document.getElementById('userModalTitle').textContent = 'Novo Usuário';
    document.getElementById('user_id').value = '';
    document.getElementById('u_name').value = '';
    document.getElementById('u_email').value = '';
    document.getElementById('u_password').value = '';
    document.getElementById('u_password').required = true;
    document.getElementById('passLabel').classList.add('required');
    document.getElementById('passHint').style.display = 'none';
    document.getElementById('u_role').value = '3';
    document.getElementById('userStatusField').style.display = 'none';
    document.querySelectorAll('.sector-check').forEach(function(c){ c.checked = false; });
}

function editUser(u, sectorIds) {
    document.getElementById('userForm').action = '<?php echo url('admin/user_update'); ?>';
    document.getElementById('userModalTitle').textContent = 'Editar Usuário';
    document.getElementById('user_id').value = u.id;
    document.getElementById('u_name').value = u.name || '';
    document.getElementById('u_email').value = u.email || '';
    document.getElementById('u_password').value = '';
    document.getElementById('u_password').required = false;
    document.getElementById('passLabel').classList.remove('required');
    document.getElementById('passHint').style.display = 'block';
    document.getElementById('u_role').value = u.role_id || '3';
    document.getElementById('u_active').value = u.is_active;
    document.getElementById('userStatusField').style.display = 'block';
    // Marca os setores do usuário
    document.querySelectorAll('.sector-check').forEach(function(c){
        c.checked = sectorIds.indexOf(parseInt(c.value)) !== -1;
    });
    new bootstrap.Modal(document.getElementById('modalUser')).show();
}
</script>
