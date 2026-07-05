<div class="page-header">
    <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
    <div class="d-flex align-items-center gap-3">
        <?php
            // Seletor de setor em foco (movido da navbar legada para o conteúdo)
            $nav_sectors = get_user_sectors();
            if (count($nav_sectors) > 1):
        ?>
        <form method="POST" action="<?php echo url('profile/switch-sector'); ?>" class="d-flex align-items-center gap-2">
            <?php echo csrf_field(); ?>
            <label class="form-label mb-0 small text-muted" for="dash-sector">
                <i class="bi bi-diagram-3 me-1"></i>Setor:
            </label>
            <select name="sector_id" id="dash-sector" class="form-select form-select-sm"
                    onchange="this.form.submit()" style="min-width:160px">
                <?php foreach ($nav_sectors as $ns): ?>
                    <option value="<?php echo (int) $ns['id']; ?>"
                            <?php echo (int) $ns['id'] === get_sector_id() ? 'selected' : ''; ?>>
                        <?php echo e($ns['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php elseif (get_sector_name()): ?>
        <span class="text-muted small">
            <i class="bi bi-diagram-3 me-1"></i><?php echo e(get_sector_name()); ?>
        </span>
        <?php endif; ?>
        <div class="text-muted small">
            <i class="bi bi-calendar me-1"></i><?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>
</div>

<p class="text-muted small mb-4">Bem-vindo, <?php echo e(get_user_name()); ?>.</p>

<!-- ── KPIs ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3">
                    <i class="bi bi-folder2-open"></i>
                </div>
                <div>
                    <div class="stat-value text-primary"><?php echo (int) $stats['total_documents']; ?></div>
                    <div class="stat-label">Documentos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3">
                    <i class="bi bi-x-circle"></i>
                </div>
                <div>
                    <div class="stat-value text-danger"><?php echo (int) $stats['expired_documents']; ?></div>
                    <div class="stat-label">Vencidos</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3">
                    <i class="bi bi-exclamation-triangle"></i>
                </div>
                <div>
                    <div class="stat-value text-warning"><?php echo (int) $stats['expiring_documents']; ?></div>
                    <div class="stat-label">Vencendo</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card stat-card shadow-sm">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success-bg-opacity-10 text-success me-3">
                    <i class="bi bi-graph-up"></i>
                </div>
                <div>
                    <div class="stat-value text-success"><?php echo (int) $stats['total_indicators']; ?></div>
                    <div class="stat-label">Indicadores</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- ── Alertas ───────────────────────────────────────────────────────────── -->
<div class="row g-3">
    <!-- Documentos Vencidos -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Documentos Vencidos</span>
                <span class="badge bg-danger"><?php echo count($expired_docs); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($expired_docs)): ?>
                    <p class="text-muted text-center py-4 mb-0 small">Nenhum documento vencido</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Documento</th><th>Categoria</th><th>Vencimento</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($expired_docs as $doc): ?>
                                <tr>
                                    <td>
                                        <a class="text-decoration-none" href="<?php echo url('documents/view?id=' . $doc['id']); ?>">
                                            <?php echo e($doc['title']); ?>
                                        </a>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                                    <td><span class="badge badge-vencido"><?php echo format_date($doc['expiration_date']); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Documentos Vencendo -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Documentos Vencendo</span>
                <span class="badge badge-proximo"><?php echo count($expiring_docs); ?></span>
            </div>
            <div class="card-body p-0">
                <?php if (empty($expiring_docs)): ?>
                    <p class="text-muted text-center py-4 mb-0 small">Nenhum documento próximo do vencimento</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr><th>Documento</th><th>Categoria</th><th>Vencimento</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($expiring_docs as $doc):
                                $days = days_until($doc['expiration_date']);
                            ?>
                                <tr>
                                    <td>
                                        <a class="text-decoration-none" href="<?php echo url('documents/view?id=' . $doc['id']); ?>">
                                            <?php echo e($doc['title']); ?>
                                        </a>
                                    </td>
                                    <td><span class="badge bg-secondary"><?php echo e($doc['category']); ?></span></td>
                                    <td><span class="badge badge-proximo"><?php echo expiry_label($days); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Notificações ──────────────────────────────────────────────────────── -->
<div class="row g-3 mt-1">
    <div class="col-12">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-bell me-1"></i>Notificações Recentes</span>
                <a href="<?php echo url('notifications'); ?>" class="btn btn-sm btn-outline-primary">Ver todas</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                    <p class="text-muted text-center py-4 mb-0 small">Nenhuma notificação recente</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $n): ?>
                        <div class="list-group-item <?php echo $n['is_read'] ? '' : 'bg-light'; ?>">
                            <div class="d-flex justify-content-between">
                                <strong class="small"><?php echo e($n['title']); ?></strong>
                                <small class="text-muted"><?php echo format_datetime($n['created_at']); ?></small>
                            </div>
                            <small class="text-muted"><?php echo e($n['message']); ?></small>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
