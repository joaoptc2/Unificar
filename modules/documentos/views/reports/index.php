<div class="page-header">
    <h1><i class="bi bi-clipboard-data me-2"></i>Relatório de Conformidade</h1>
</div>

<!-- KPIs gerais -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?php echo $doc_pct_ok >= 80 ? 'success' : ($doc_pct_ok >= 50 ? 'warning' : 'danger'); ?>-bg-opacity-10
                            text-<?php echo $doc_pct_ok >= 80 ? 'success' : ($doc_pct_ok >= 50 ? 'warning' : 'danger'); ?> me-3">
                    <i class="bi bi-folder-check"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $doc_pct_ok; ?>%</div>
                    <div class="stat-label">Docs em dia</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-<?php echo $ind_pct_ok >= 80 ? 'success' : ($ind_pct_ok >= 50 ? 'warning' : 'danger'); ?>-bg-opacity-10
                            text-<?php echo $ind_pct_ok >= 80 ? 'success' : ($ind_pct_ok >= 50 ? 'warning' : 'danger'); ?> me-3">
                    <i class="bi bi-bullseye"></i>
                </div>
                <div>
                    <div class="stat-value"><?php echo $ind_pct_ok; ?>%</div>
                    <div class="stat-label">Indicadores na meta</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-value text-danger"><?php echo $doc_stats['expired']; ?></div>
                    <div class="stat-label">Docs vencidos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3"><i class="bi bi-arrow-repeat"></i></div>
                <div>
                    <div class="stat-value text-warning"><?php echo $doc_stats['review_overdue']; ?></div>
                    <div class="stat-label">Revisões pendentes</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <!-- Documentos -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-folder2-open me-1"></i>Documentos
                <span class="badge bg-primary ms-1"><?php echo $doc_stats['total']; ?></span>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center small">
                    <div class="col-4">
                        <div class="fw-bold text-success fs-5"><?php echo $doc_stats['valid']; ?></div>
                        <div class="text-muted">Válidos</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold text-warning fs-5"><?php echo $doc_stats['expiring']; ?></div>
                        <div class="text-muted">Vencendo</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold text-danger fs-5"><?php echo $doc_stats['expired']; ?></div>
                        <div class="text-muted">Vencidos</div>
                    </div>
                </div>
                <?php if ($doc_stats['draft'] > 0 || $doc_stats['pending_review'] > 0): ?>
                <hr>
                <div class="row g-2 text-center small">
                    <div class="col-6">
                        <div class="fw-bold fs-5"><?php echo $doc_stats['draft']; ?></div>
                        <div class="text-muted">Rascunhos</div>
                    </div>
                    <div class="col-6">
                        <div class="fw-bold fs-5"><?php echo $doc_stats['pending_review']; ?></div>
                        <div class="text-muted">Aguardando revisão</div>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Indicadores -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-graph-up me-1"></i>Indicadores
                <span class="badge bg-primary ms-1"><?php echo $ind_total; ?></span>
            </div>
            <div class="card-body">
                <div class="row g-2 text-center small">
                    <div class="col-4">
                        <div class="fw-bold text-success fs-5"><?php echo $ind_met; ?></div>
                        <div class="text-muted">Na meta</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold text-warning fs-5"><?php echo $ind_tolerance; ?></div>
                        <div class="text-muted">Tolerância</div>
                    </div>
                    <div class="col-4">
                        <div class="fw-bold text-danger fs-5"><?php echo $ind_missed; ?></div>
                        <div class="text-muted">Fora da meta</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Docs vencendo -->
<?php if (!empty($expiring_docs)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-exclamation-triangle text-warning me-1"></i>Documentos vencendo nos próximos <?php echo NOTIFY_DAYS_BEFORE; ?> dias
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Documento</th><th>Categoria</th><th>Vencimento</th><th>Dias</th></tr></thead>
                <tbody>
                <?php foreach ($expiring_docs as $doc):
                    $dd = days_until($doc['expiration_date']); ?>
                    <tr>
                        <td><a href="<?php echo url('documents/view?id=' . $doc['id']); ?>" class="text-decoration-none"><?php echo e($doc['title']); ?></a></td>
                        <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                        <td class="small"><?php echo format_date($doc['expiration_date']); ?></td>
                        <td><span class="badge badge-proximo"><?php echo $dd; ?> dias</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Revisões pendentes -->
<?php if (!empty($review_pending)): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">
        <i class="bi bi-arrow-repeat text-info me-1"></i>Documentos com revisão periódica pendente
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Documento</th><th>Categoria</th><th>Revisão prevista</th><th>Atraso</th></tr></thead>
                <tbody>
                <?php foreach ($review_pending as $doc):
                    $dd = abs(days_until($doc['next_review_date'])); ?>
                    <tr>
                        <td><a href="<?php echo url('documents/view?id=' . $doc['id']); ?>" class="text-decoration-none"><?php echo e($doc['title']); ?></a></td>
                        <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                        <td class="small"><?php echo format_date($doc['next_review_date']); ?></td>
                        <td><span class="badge bg-warning text-dark"><?php echo $dd; ?> dias</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
