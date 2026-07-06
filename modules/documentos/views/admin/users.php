<?php
/**
 * Usuários & Setores — usuários GLOBAIS com acesso a este módulo.
 * Aqui só se gerencia a associação de setores; criação/edição/senha/papel
 * ficam na administração central da plataforma.
 */
$central_url     = core_url('index.php?m=admin&a=users');
$is_global_admin = !empty(core_user()['is_admin']);

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
    <h1><i class="bi bi-people me-2"></i>Usuários &amp; Setores</h1>
    <div class="d-flex gap-2">
        <?php if ($is_global_admin): ?>
        <a href="<?php echo e($central_url); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-people-fill me-1"></i>Administração central de usuários
        </a>
        <?php endif; ?>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<div class="alert alert-info small">
    <i class="bi bi-info-circle me-1"></i>
    Usuários agora são <strong>globais da plataforma</strong>. Criação, edição, senha e
    permissões no módulo são gerenciados na administração central<?php if ($is_global_admin): ?>
    (<a href="<?php echo e($central_url); ?>">abrir</a>)<?php endif; ?>.
    Aqui você define apenas <strong>quais setores</strong> cada usuário acessa neste módulo.
</div>

<div class="filter-panel mb-3">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="admin/users">
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
                <p class="text-muted mt-2">Nenhum usuário com acesso a este módulo.</p>
                <p class="text-muted small">Conceda acesso na administração central da plataforma.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Setores</th>
                            <th>Último acesso</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u):
                        $u_sectors = $user_sector_map[$u['id']] ?? [];
                    ?>
                        <tr>
                            <td>
                                <div class="fw-semibold">
                                    <?php echo e($u['name']); ?>
                                    <?php if (!empty($u['is_admin'])): ?>
                                        <span class="badge bg-dark" title="Administrador global da plataforma">Global</span>
                                    <?php endif; ?>
                                </div>
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
                            <td class="small"><?php echo $u['last_login_at'] ? format_datetime($u['last_login_at']) : '—'; ?></td>
                            <td>
                                <span class="badge <?php echo $u['active'] ? 'badge-ativo' : 'badge-desligado'; ?>">
                                    <?php echo $u['active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-primary btn-action"
                                        onclick='editUserSectors(<?php echo (int) $u['id']; ?>, <?php echo json_encode($u['name'], JSON_HEX_APOS | JSON_HEX_QUOT); ?>, <?php echo json_encode(array_map('intval', array_column($u_sectors, 'id'))); ?>)'
                                        data-bs-toggle="tooltip" title="Gerenciar setores">
                                    <i class="bi bi-diagram-3 me-1"></i>Setores
                                </button>
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

<!-- Modal: setores do usuário -->
<div class="modal fade" id="modalUserSectors" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('admin/user_sectors'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="user_id" id="us_user_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold">Setores — <span id="us_user_name"></span></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($all_sectors)): ?>
                        <p class="text-muted small mb-0">
                            Nenhum setor ativo cadastrado.
                            <a href="<?php echo url('admin/sectors'); ?>">Criar setores</a>.
                        </p>
                    <?php else: ?>
                        <div class="border rounded p-2" style="max-height:220px; overflow-y:auto">
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
                    <?php endif; ?>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <?php if (!empty($all_sectors)): ?>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function editUserSectors(userId, userName, sectorIds) {
    document.getElementById('us_user_id').value = userId;
    document.getElementById('us_user_name').textContent = userName;
    document.querySelectorAll('.sector-check').forEach(function (c) {
        c.checked = sectorIds.indexOf(parseInt(c.value, 10)) !== -1;
    });
    new bootstrap.Modal(document.getElementById('modalUserSectors')).show();
}
</script>
