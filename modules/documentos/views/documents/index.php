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
    <h1><i class="bi bi-folder2-open me-2"></i>Documentos controlados</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('documents.export')): ?>
        <a href="<?php echo url('documents/export' . ($export_qs ? '?' . $export_qs : '')); ?>"
           class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
        <?php endif; ?>
        <?php if (core_can('documents.create')): ?>
        <a href="<?php echo url('documents/create'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo documento
        </a>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mb-3">
    <i class="bi bi-shield-check me-1"></i>Documentos com controle de validade, revisão periódica, aprovação e ciência.
    Os documentos apenas armazenados ficam em <a href="<?php echo url('documents/uncontrolled'); ?>">Documentos não controlados</a>.
</p>

<div class="filter-panel">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="documents">
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
                   placeholder="Título, código, responsável..." value="<?php echo e($search); ?>">
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
                <p class="text-muted mt-2">Nenhum documento controlado encontrado<?php echo get_sector_id() ? ' no setor em foco' : ''; ?>.</p>
                <?php if (core_can('documents.create')): ?>
                <a href="<?php echo url('documents/create'); ?>" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Cadastrar
                </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Documento</th>
                            <th>Categoria</th>
                            <th>Setor</th>
                            <th>Validade</th>
                            <th>Status</th>
                            <th>Situação</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documents as $doc):
                        $d_days = $doc['expiration_date'] ? days_until($doc['expiration_date']) : null;
                        $d_badge = $d_days === null ? 'bg-light text-dark' : ($d_days < 0 ? 'badge-vencido' : ($d_days <= 30 ? 'badge-proximo' : 'badge-valido'));
                        $d_st = $status_labels[$doc['status'] ?? 'approved'] ?? ['bg-secondary','?'];
                        $is_editor = document_is_editor($doc);
                    ?>
                        <tr>
                            <td>
                                <?php if ($is_editor): ?>
                                    <i class="bi bi-file-earmark-richtext text-primary me-1" title="Escrito no sistema"></i>
                                <?php elseif (!empty($doc['file_type'])): ?>
                                    <i class="bi <?php echo file_icon($doc['file_type']); ?> me-1"></i>
                                <?php endif; ?>
                                <a href="<?php echo url('documents/view?id=' . $doc['id']); ?>"
                                   class="fw-semibold text-decoration-none">
                                    <?php echo e($doc['title']); ?>
                                </a>
                                <?php if (!empty($doc['document_code'])): ?>
                                    <small class="text-muted ms-1"><?php echo e($doc['document_code']); ?></small>
                                <?php endif; ?>
                                <small class="text-muted ms-1">v<?php echo (int) $doc['current_version']; ?></small>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                            <td class="small text-muted"><?php echo e($doc['sector_name'] ?: '—'); ?></td>
                            <td class="small"><?php echo format_date($doc['expiration_date']); ?></td>
                            <td><span class="badge <?php echo $d_st[0]; ?>" style="font-size:.65rem"><?php echo $d_st[1]; ?></span></td>
                            <td><span class="badge <?php echo $d_badge; ?>"><?php echo $d_days === null ? 'Sem validade' : expiry_label($d_days); ?></span></td>
                            <td class="text-end text-nowrap">
                                <a href="<?php echo url('documents/view?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-primary btn-action" title="Ver"><i class="bi bi-eye"></i></a>
                                <?php if (core_can('documents.edit')): ?>
                                <a href="<?php echo url('documents/edit?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if ($is_editor): ?>
                                <a href="<?php echo url('documents/print/' . $doc['id']); ?>" target="_blank"
                                   class="btn btn-outline-success btn-action" title="Imprimir / PDF"><i class="bi bi-printer"></i></a>
                                <?php elseif (!empty($doc['file_path'])): ?>
                                <a href="<?php echo url('documents/download?id=' . $doc['id']); ?>"
                                   class="btn btn-outline-success btn-action" title="Baixar"><i class="bi bi-download"></i></a>
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
