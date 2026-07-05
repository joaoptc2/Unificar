<?php
$export_qs = http_build_query(array_filter([
    'filter' => $filter, 'search' => $search, 'category' => $category, 'status' => $status,
]));
$status_labels = [
    'draft' => ['bg-secondary', 'Rascunho'],
    'pending_review' => ['bg-warning text-dark', 'Em revisão'],
    'approved' => ['bg-success', 'Aprovado'],
    'expired' => ['bg-danger', 'Vencido'],
    'archived' => ['bg-dark', 'Arquivado'],
];
?>
<div class="page-header">
    <h1><i class="bi bi-folder2-open me-2"></i>Documentos</h1>
    <div class="d-flex gap-2">
        <a href="<?php echo url('documents/export' . ($export_qs ? '?' . $export_qs : '')); ?>"
           class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
        <a href="<?php echo url('documents/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo Documento
        </a>
    </div>
</div>

<div class="filter-panel">
    <form method="GET" action="<?php echo url('documents'); ?>" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Validade</label>
            <select name="filter" class="form-select form-select-sm">
                <option value="all"      <?php echo $filter === 'all'      ? 'selected' : ''; ?>>Todos</option>
                <option value="valid"    <?php echo $filter === 'valid'    ? 'selected' : ''; ?>>Válidos</option>
                <option value="expiring" <?php echo $filter === 'expiring' ? 'selected' : ''; ?>>Vencendo</option>
                <option value="expired"  <?php echo $filter === 'expired'  ? 'selected' : ''; ?>>Vencidos</option>
                <option value="review_pending" <?php echo $filter === 'review_pending' ? 'selected' : ''; ?>>Revisão pendente</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value=""              <?php echo $status === ''              ? 'selected' : ''; ?>>Todos</option>
                <option value="draft"         <?php echo $status === 'draft'         ? 'selected' : ''; ?>>Rascunho</option>
                <option value="pending_review"<?php echo $status === 'pending_review'? 'selected' : ''; ?>>Em revisão</option>
                <option value="approved"      <?php echo $status === 'approved'      ? 'selected' : ''; ?>>Aprovado</option>
                <option value="archived"      <?php echo $status === 'archived'      ? 'selected' : ''; ?>>Arquivado</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Categoria</label>
            <select name="category" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo e($cat['category']); ?>"
                            <?php echo $category === $cat['category'] ? 'selected' : ''; ?>>
                        <?php echo e($cat['category']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Título, responsável..." value="<?php echo e($search); ?>">
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-outline-primary btn-sm w-100">
                <i class="bi bi-search me-1"></i>Filtrar
            </button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($documents)): ?>
            <div class="text-center py-5">
                <i class="bi bi-folder2-open display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhum documento encontrado.</p>
                <a href="<?php echo url('documents/create'); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Cadastrar
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Categoria</th>
                            <th>Validade</th>
                            <th>Status</th>
                            <th>Situação</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documents as $doc):
                        $d_days = days_until($doc['expiration_date']);
                        $d_badge = $d_days < 0 ? 'badge-vencido' : ($d_days <= 30 ? 'badge-proximo' : 'badge-valido');
                        $d_st = $status_labels[$doc['status'] ?? 'approved'] ?? ['bg-secondary','?'];
                    ?>
                        <tr>
                            <td>
                                <?php if (!empty($doc['file_type'])): ?>
                                    <i class="bi <?php echo file_icon($doc['file_type']); ?> me-1"></i>
                                <?php endif; ?>
                                <a href="<?php echo url('documents/view?id=' . $doc['id']); ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?php echo e($doc['title']); ?>
                                </a>
                                <?php if (!empty($doc['document_code'])): ?>
                                    <small class="text-muted ms-1"><?php echo e($doc['document_code']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                            <td class="small"><?php echo format_date($doc['expiration_date']); ?></td>
                            <td><span class="badge <?php echo $d_st[0]; ?>" style="font-size:.65rem"><?php echo $d_st[1]; ?></span></td>
                            <td><span class="badge <?php echo $d_badge; ?>"><?php echo expiry_label($d_days); ?></span></td>
                            <td class="text-end">
                                <a href="<?php echo url('documents/view?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-primary btn-action"><i class="bi bi-eye"></i></a>
                                <a href="<?php echo url('documents/edit?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-warning btn-action"><i class="bi bi-pencil"></i></a>
                                <?php if (!empty($doc['file_path'])): ?>
                                <a href="<?php echo url('documents/download?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-success btn-action"><i class="bi bi-download"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
    <?php if (!empty($documents)): ?>
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
        <small class="text-muted">Total: <strong><?php echo $pagination['total']; ?></strong></small>
        <?php echo pagination_html($pagination); ?>
    </div>
    <?php endif; ?>
</div>
