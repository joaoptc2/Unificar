<div class="page-header">
    <h1><i class="bi bi-hospital me-2"></i>Gerenciar Hospitais</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalHospital" onclick="clearForm()">
            <i class="bi bi-plus-lg me-1"></i>Novo Hospital
        </button>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($hospitals)): ?>
            <div class="text-center py-5">
                <i class="bi bi-hospital display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhum hospital cadastrado.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Hospital</th>
                            <th>CNPJ</th>
                            <th>Telefone</th>
                            <th>Setores</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($hospitals as $h): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?php echo e($h['name']); ?></div>
                                <small class="text-muted"><?php echo e($h['email']); ?></small>
                            </td>
                            <td><?php echo e($h['cnpj'] ?: '—'); ?></td>
                            <td><?php echo e($h['phone'] ?: '—'); ?></td>
                            <td><span class="badge bg-primary"><?php echo (int) $h['sector_count']; ?></span></td>
                            <td>
                                <span class="badge <?php echo $h['is_active'] ? 'badge-ativo' : 'badge-desligado'; ?>">
                                    <?php echo $h['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-outline-warning btn-action"
                                        onclick="editHospital(<?php echo e(json_encode($h)); ?>)"
                                        data-bs-toggle="tooltip" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" action="<?php echo url('admin/hospital_delete'); ?>" class="d-inline"
                                      data-confirm="Remover este hospital?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $h['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action"
                                            data-bs-toggle="tooltip" title="Remover">
                                        <i class="bi bi-trash"></i>
                                    </button>
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
<div class="modal fade" id="modalHospital" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="hospitalForm" method="POST" action="<?php echo url('admin/hospital_store'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="hospital_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold" id="modalTitle">Novo Hospital</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" class="form-control" name="name" id="h_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">CNPJ</label>
                        <input type="text" class="form-control" name="cnpj" id="h_cnpj">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Endereço</label>
                        <input type="text" class="form-control" name="address" id="h_address">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Telefone</label>
                            <input type="text" class="form-control" name="phone" id="h_phone" data-mask="phone">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">E-mail</label>
                            <input type="email" class="form-control" name="email" id="h_email">
                        </div>
                    </div>
                    <div class="mb-3 mt-3" id="statusField" style="display:none">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active" id="h_active">
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
function clearForm() {
    document.getElementById('hospitalForm').action = '<?php echo url('admin/hospital_store'); ?>';
    document.getElementById('modalTitle').textContent = 'Novo Hospital';
    ['hospital_id','h_name','h_cnpj','h_address','h_phone','h_email'].forEach(function(id){
        document.getElementById(id).value = '';
    });
    document.getElementById('statusField').style.display = 'none';
}
function editHospital(h) {
    document.getElementById('hospitalForm').action = '<?php echo url('admin/hospital_update'); ?>';
    document.getElementById('modalTitle').textContent = 'Editar Hospital';
    document.getElementById('hospital_id').value = h.id;
    document.getElementById('h_name').value = h.name || '';
    document.getElementById('h_cnpj').value = h.cnpj || '';
    document.getElementById('h_address').value = h.address || '';
    document.getElementById('h_phone').value = h.phone || '';
    document.getElementById('h_email').value = h.email || '';
    document.getElementById('h_active').value = h.is_active;
    document.getElementById('statusField').style.display = 'block';
    new bootstrap.Modal(document.getElementById('modalHospital')).show();
}
</script>
