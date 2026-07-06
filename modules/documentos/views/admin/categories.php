<div class="page-header">
    <h1><i class="bi bi-tags me-2"></i>Categorias de Documentos</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('categories.create')): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCategory" onclick="clearCategoryForm()">
            <i class="bi bi-plus-lg me-1"></i>Nova Categoria
        </button>
        <?php endif; ?>
        <a href="<?php echo url('admin'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($categories)): ?>
            <div class="text-center py-5">
                <i class="bi bi-tags display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhuma categoria cadastrada.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:60px">Ícone</th>
                            <th>Categoria</th>
                            <th>Descrição</th>
                            <th class="text-center">Ordem</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($categories as $c): ?>
                        <tr>
                            <td><i class="bi <?php echo e($c['icon'] ?: 'bi-folder'); ?> fs-5 text-primary"></i></td>
                            <td class="fw-semibold"><?php echo e($c['name']); ?></td>
                            <td class="text-muted small"><?php echo e($c['description'] ?: '—'); ?></td>
                            <td class="text-center"><span class="badge bg-light text-dark"><?php echo (int) $c['sort_order']; ?></span></td>
                            <td>
                                <span class="badge <?php echo $c['is_active'] ? 'badge-ativo' : 'badge-desligado'; ?>">
                                    <?php echo $c['is_active'] ? 'Ativa' : 'Inativa'; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <?php if (core_can('categories.edit')): ?>
                                <button class="btn btn-outline-warning btn-action"
                                        onclick="editCategory(<?php echo e(json_encode($c)); ?>)"
                                        data-bs-toggle="tooltip" title="Editar">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (core_can('categories.delete')): ?>
                                <form method="POST" action="<?php echo url('admin/category_delete'); ?>" class="d-inline"
                                      data-confirm="Remover esta categoria? Documentos existentes mantêm o nome da categoria.">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $c['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action"><i class="bi bi-trash"></i></button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Modal Categoria -->
<div class="modal fade" id="modalCategory" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="categoryForm" method="POST" action="<?php echo url('admin/category_store'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="id" id="cat_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold" id="catModalTitle">Nova Categoria</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label required">Nome</label>
                        <input type="text" class="form-control" name="name" id="cat_name" required
                               placeholder="POPs, Protocolos Clínicos...">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição</label>
                        <input type="text" class="form-control" name="description" id="cat_desc">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label">Ícone (Bootstrap Icons)</label>
                            <input type="text" class="form-control" name="icon" id="cat_icon"
                                   placeholder="bi-list-check, bi-shield-check...">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ordem</label>
                            <input type="number" class="form-control" name="sort_order" id="cat_order" value="0" min="0">
                        </div>
                    </div>
                    <div class="mt-3" id="catStatusField" style="display:none">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="is_active" id="cat_active">
                            <option value="1">Ativa</option>
                            <option value="0">Inativa</option>
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
function clearCategoryForm() {
    document.getElementById('categoryForm').action = '<?php echo url('admin/category_store'); ?>';
    document.getElementById('catModalTitle').textContent = 'Nova Categoria';
    ['cat_id','cat_name','cat_desc','cat_icon'].forEach(function(id){ document.getElementById(id).value=''; });
    document.getElementById('cat_order').value = '0';
    document.getElementById('catStatusField').style.display = 'none';
}
function editCategory(c) {
    document.getElementById('categoryForm').action = '<?php echo url('admin/category_update'); ?>';
    document.getElementById('catModalTitle').textContent = 'Editar Categoria';
    document.getElementById('cat_id').value = c.id;
    document.getElementById('cat_name').value = c.name || '';
    document.getElementById('cat_desc').value = c.description || '';
    document.getElementById('cat_icon').value = c.icon || '';
    document.getElementById('cat_order').value = c.sort_order || 0;
    document.getElementById('cat_active').value = c.is_active;
    document.getElementById('catStatusField').style.display = 'block';
    new bootstrap.Modal(document.getElementById('modalCategory')).show();
}
</script>
