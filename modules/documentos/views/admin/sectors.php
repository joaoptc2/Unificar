<div class="page-header">
    <h1><i class="bi bi-diagram-3 me-2"></i>Gerenciar Setores</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalSector" onclick="clearSectorForm()">
            <i class="bi bi-plus-lg me-1"></i>Novo Setor
        </button>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($sectors)): ?>
            <div class="text-center py-5">
                <i class="bi bi-diagram-3 display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhum setor cadastrado.</p>
                <p class="text-muted small">Crie setores como UTI, Centro Cirúrgico, PS, etc.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Setor</th>
                            <th>Sigla</th>
                            <th>Descrição</th>
                            <th class="text-center">Usuários</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($sectors as $s): ?>
                        <tr>
                            <td class="fw-semibold"><?php echo e($s['name']); ?></td>
                            <td><span class="badge bg-light text-dark font-monospace"><?php echo e($s['code'] ?: '—'); ?></span></td>
                            <td class="text-muted small"><?php echo e($s['description'] ?: '—'); ?></td>
                            <td class="text-center"><span class="badge bg-primary"><?php echo (int) $s['user_count']; ?></span></td>
                            <td>
                                <span class="badge <?php echo $s['is_active'] ? 'badge-ativo' : 'badge-desligado'; ?>">
                                    <?php echo $s['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-warning btn-action"
                                        onclick="editSector(<?php echo e(json_encode($s)); ?>)"
                                        data-bs-toggle="tooltip" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="<?php echo url('admin/sector_delete'); ?>" class="d-inline"
                                      data-confirm="Remover este setor?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $s['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal -->
<div class="modal fade" id="modalSector" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="sectorForm" method="POST" action="<?php echo url('admin/sector_store'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="sec_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold" id="secModalTitle">Novo Setor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" class="form-control" name="name" id="sec_name" required placeholder="UTI Adulto, Centro Cirúrgico...">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Sigla</label>
                            <input type="text" class="form-control" name="code" id="sec_code" placeholder="UTI, CC, PS...">
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Descrição</label>
                            <input type="text" class="form-control" name="description" id="sec_desc">
                        </div>
                    </div>
                    <div class="mt-3" id="secStatusField" style="display:none">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active" id="sec_active">
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
function clearSectorForm() {
    document.getElementById('sectorForm').action = '<?php echo url('admin/sector_store'); ?>';
    document.getElementById('secModalTitle').textContent = 'Novo Setor';
    ['sec_id','sec_name','sec_code','sec_desc'].forEach(function(id){ document.getElementById(id).value=''; });
    document.getElementById('secStatusField').style.display = 'none';
}
function editSector(s) {
    document.getElementById('sectorForm').action = '<?php echo url('admin/sector_update'); ?>';
    document.getElementById('secModalTitle').textContent = 'Editar Setor';
    document.getElementById('sec_id').value = s.id;
    document.getElementById('sec_name').value = s.name || '';
    document.getElementById('sec_code').value = s.code || '';
    document.getElementById('sec_desc').value = s.description || '';
    document.getElementById('sec_active').value = s.is_active;
    document.getElementById('secStatusField').style.display = 'block';
    new bootstrap.Modal(document.getElementById('modalSector')).show();
}
</script>
