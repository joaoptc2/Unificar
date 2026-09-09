<?php
$export_qs = http_build_query(array_filter([
    'type' => 'uncontrolled', 'search' => $search, 'category' => $category,
]));
?>
<div class="page-header">
    <h1><i class="bi bi-archive me-2"></i>Documentos não controlados</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('documents.export')): ?>
        <a href="<?php echo url('documents/export?' . $export_qs); ?>"
           class="btn btn-outline-primary btn-sm"><i class="bi bi-download me-1"></i>CSV</a>
        <?php endif; ?>
        <?php if (core_can('documents.create')): ?>
        <a href="<?php echo url('documents/create?type=uncontrolled'); ?>" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>Novo documento
        </a>
        <?php endif; ?>
    </div>
</div>

<p class="text-muted small mb-3">
    <i class="bi bi-info-circle me-1"></i>Documentos apenas armazenados para acesso fácil (manuais, materiais de apoio,
    formulários, comunicados): sem validade, sem fluxo de aprovação e sem avisos de vencimento.
</p>

<div class="filter-panel">
    <form method="GET" action="<?php echo core_url('index.php'); ?>" class="row g-2 align-items-end">
        <input type="hidden" name="m" value="documentos">
        <input type="hidden" name="url" value="documents/uncontrolled">
        <div class="col-md-3">
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
        <div class="col-md-7">
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
                <i class="bi bi-archive display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhum documento não controlado encontrado<?php echo get_sector_id() ? ' no setor em foco' : ''; ?>.</p>
                <?php if (core_can('documents.create')): ?>
                <a href="<?php echo url('documents/create?type=uncontrolled'); ?>" class="btn btn-sm btn-primary">
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
                            <th>Responsável</th>
                            <th>Origem</th>
                            <th>Atualizado</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($documents as $doc): $is_editor = document_is_editor($doc); ?>
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
                                <?php if (!empty($doc['observations'])): ?>
                                    <div class="small text-muted text-truncate" style="max-width:420px"><?php echo e($doc['observations']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                            <td class="small text-muted"><?php echo e($doc['sector_name'] ?: '—'); ?></td>
                            <td class="small"><?php echo e($doc['responsible'] ?: '—'); ?></td>
                            <td class="small">
                                <?php if ($is_editor): ?>
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle">Editor · v<?php echo (int) $doc['current_version']; ?></span>
                                <?php else: ?>
                                    <span class="badge bg-light text-dark border"><?php echo e(strtoupper((string) $doc['file_type'] ?: 'arquivo')); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted"><?php echo format_datetime($doc['updated_at']); ?></td>
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
                                <?php if (core_can('documents.delete')): ?>
                                <form method="POST" action="<?php echo url('documents/delete'); ?>" class="d-inline"
                                      data-confirm="Remover este documento?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $doc['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action" title="Remover"><i class="bi bi-trash"></i></button>
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
    <?php if (!empty($documents)): ?>
    <div class="card-footer bg-transparent d-flex justify-content-between align-items-center">
        <small class="text-muted">Total: <strong><?php echo $pagination['total']; ?></strong></small>
        <?php echo pagination_html($pagination); ?>
    </div>
    <?php endif; ?>
</div>
