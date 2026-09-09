<?php
/**
 * Dashboard: KPIs, alertas de documentos controlados, notificações e a
 * seção "Conformidade" (antigo Relatório de Conformidade — reports).
 * O filtro de setor é o seletor global (topo da página).
 */
$sector_label = get_sector_id() > 0 ? get_sector_name() : 'Todos os setores';
$pct_color = function ($pct) { return $pct >= 80 ? 'success' : ($pct >= 50 ? 'warning' : 'danger'); };
?>
<div class="page-header">
    <h1><i class="bi bi-speedometer2 me-2"></i>Dashboard</h1>
    <div class="d-flex align-items-center gap-3">
        <a href="#conformidade" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-clipboard-check me-1"></i>Conformidade
        </a>
        <div class="text-muted small">
            <i class="bi bi-calendar me-1"></i><?php echo date('d/m/Y H:i'); ?>
        </div>
    </div>
</div>

<p class="text-muted small mb-4">
    Bem-vindo, <?php echo e(get_user_name()); ?>.
    <span class="ms-2"><i class="bi bi-diagram-3 me-1"></i><?php echo e($sector_label); ?></span>
</p>

<!-- ── KPIs ──────────────────────────────────────────────────────────────── -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('documents'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3"><i class="bi bi-folder2-open"></i></div>
                <div>
                    <div class="stat-value text-primary"><?php echo (int) $stats['total_documents']; ?></div>
                    <div class="stat-label">Controlados</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('documents/uncontrolled'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-secondary-bg-opacity-10 text-secondary me-3"><i class="bi bi-archive"></i></div>
                <div>
                    <div class="stat-value text-secondary"><?php echo (int) $stats['uncontrolled_documents']; ?></div>
                    <div class="stat-label">Não controlados</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('documents?filter=expired'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-x-circle"></i></div>
                <div>
                    <div class="stat-value text-danger"><?php echo (int) $stats['expired_documents']; ?></div>
                    <div class="stat-label">Vencidos</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('documents?filter=expiring'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3"><i class="bi bi-exclamation-triangle"></i></div>
                <div>
                    <div class="stat-value text-warning"><?php echo (int) $stats['expiring_documents']; ?></div>
                    <div class="stat-label">Vencendo</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('indicators'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-success-bg-opacity-10 text-success me-3"><i class="bi bi-graph-up"></i></div>
                <div>
                    <div class="stat-value text-success"><?php echo (int) $stats['total_indicators']; ?></div>
                    <div class="stat-label">Indicadores</div>
                </div>
            </div>
        </div>
        </a>
    </div>
    <div class="col-6 col-md-4 col-xl-2">
        <a class="kpi-link" href="<?php echo url('indicators/actions'); ?>">
        <div class="card stat-card shadow-sm h-100">
            <div class="card-body d-flex align-items-center">
                <div class="stat-icon bg-info-bg-opacity-10 text-info me-3"><i class="bi bi-list-check"></i></div>
                <div>
                    <div class="stat-value text-info"><?php echo (int) $actions_open; ?></div>
                    <div class="stat-label">Ações abertas<?php echo $actions_overdue > 0 ? ' <span class="text-danger">(' . (int) $actions_overdue . ' atrasadas)</span>' : ''; ?></div>
                </div>
            </div>
        </div>
        </a>
    </div>
</div>

<!-- ── Alertas ───────────────────────────────────────────────────────────── -->
<div class="row g-3">
    <!-- Documentos Vencidos -->
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span class="text-danger"><i class="bi bi-x-circle me-1"></i>Documentos vencidos</span>
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
                <span class="text-warning"><i class="bi bi-exclamation-triangle me-1"></i>Documentos vencendo (próximos <?php echo NOTIFY_DAYS_BEFORE; ?> dias)</span>
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
                <span><i class="bi bi-bell me-1"></i>Notificações recentes
                    <?php if ((int) $stats['unread_notifications'] > 0): ?>
                        <span class="badge bg-danger ms-1"><?php echo (int) $stats['unread_notifications']; ?> não lidas</span>
                    <?php endif; ?>
                </span>
                <a href="<?php echo url('notifications'); ?>" class="btn btn-sm btn-outline-primary">Ver todas</a>
            </div>
            <div class="card-body p-0">
                <?php if (empty($notifications)): ?>
                    <p class="text-muted text-center py-4 mb-0 small">Nenhuma notificação recente</p>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                    <?php foreach ($notifications as $n): ?>
                        <div class="list-group-item <?php echo !empty($n['is_read']) ? '' : 'bg-light'; ?>">
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

<!-- ══ Conformidade (antigo Relatório de Conformidade) ═════════════════════ -->
<div id="conformidade" class="mt-4 pt-2">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h2 class="h5 mb-0"><i class="bi bi-clipboard-check me-2"></i>Conformidade
            <small class="text-muted fw-normal">— documentos controlados e indicadores · <?php echo e($sector_label); ?></small>
        </h2>
        <span class="text-muted small"><i class="bi bi-info-circle me-1"></i>Documentos não controlados não entram no cálculo.</span>
    </div>

    <!-- KPIs de conformidade -->
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-<?php echo $pct_color($doc_pct_ok); ?>-bg-opacity-10 text-<?php echo $pct_color($doc_pct_ok); ?> me-3">
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
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-<?php echo $pct_color($ind_pct_ok); ?>-bg-opacity-10 text-<?php echo $pct_color($ind_pct_ok); ?> me-3">
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
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-x-circle"></i></div>
                    <div>
                        <div class="stat-value text-danger"><?php echo (int) $doc_stats['expired']; ?></div>
                        <div class="stat-label">Docs vencidos</div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body d-flex align-items-center">
                    <div class="stat-icon bg-warning-bg-opacity-10 text-warning me-3"><i class="bi bi-arrow-repeat"></i></div>
                    <div>
                        <div class="stat-value text-warning"><?php echo (int) $doc_stats['review_overdue']; ?></div>
                        <div class="stat-label">Revisões pendentes</div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <!-- Documentos -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3 h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-folder2-open me-1"></i>Documentos controlados
                    <span class="badge bg-primary ms-1"><?php echo (int) $doc_stats['total']; ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center small">
                        <div class="col-4">
                            <div class="fw-bold text-success fs-5"><?php echo (int) $doc_stats['valid']; ?></div>
                            <div class="text-muted">Válidos</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-warning fs-5"><?php echo (int) $doc_stats['expiring']; ?></div>
                            <div class="text-muted">Vencendo</div>
                        </div>
                        <div class="col-4">
                            <div class="fw-bold text-danger fs-5"><?php echo (int) $doc_stats['expired']; ?></div>
                            <div class="text-muted">Vencidos</div>
                        </div>
                    </div>
                    <?php if ($doc_stats['draft'] > 0 || $doc_stats['pending_review'] > 0): ?>
                    <hr>
                    <div class="row g-2 text-center small">
                        <div class="col-6">
                            <div class="fw-bold fs-5"><?php echo (int) $doc_stats['draft']; ?></div>
                            <div class="text-muted">Rascunhos</div>
                        </div>
                        <div class="col-6">
                            <div class="fw-bold fs-5"><?php echo (int) $doc_stats['pending_review']; ?></div>
                            <div class="text-muted">Aguardando revisão</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Indicadores -->
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm mb-3 h-100">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-graph-up me-1"></i>Indicadores
                    <span class="badge bg-primary ms-1"><?php echo (int) $ind_total; ?></span>
                </div>
                <div class="card-body">
                    <div class="row g-2 text-center small">
                        <div class="col-3">
                            <div class="fw-bold text-success fs-5"><?php echo (int) $ind_met; ?></div>
                            <div class="text-muted">Na meta</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-warning fs-5"><?php echo (int) $ind_tolerance; ?></div>
                            <div class="text-muted">Tolerância</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-danger fs-5"><?php echo (int) $ind_missed; ?></div>
                            <div class="text-muted">Fora da meta</div>
                        </div>
                        <div class="col-3">
                            <div class="fw-bold text-secondary fs-5"><?php echo (int) $ind_no_data; ?></div>
                            <div class="text-muted">Sem dados</div>
                        </div>
                    </div>
                    <?php if (($actions_open + $actions_overdue) > 0): ?>
                    <hr>
                    <div class="small text-center text-muted">
                        <a href="<?php echo url('indicators/actions'); ?>" class="text-decoration-none">
                            <?php echo (int) $actions_open; ?> plano(s) de ação em aberto
                            <?php if ($actions_overdue > 0): ?> · <span class="text-danger"><?php echo (int) $actions_overdue; ?> atrasado(s)</span><?php endif; ?>
                        </a>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Indicadores fora da meta -->
    <?php if (!empty($ind_missed_list)): ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-x-circle text-danger me-1"></i>Indicadores fora da meta (último lançamento)
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Indicador</th><th>Categoria</th><th class="text-end">Último valor</th><th class="text-end">Meta</th><th class="text-end">Ações</th></tr></thead>
                    <tbody>
                    <?php foreach ($ind_missed_list as $mi): ?>
                        <tr>
                            <td><a href="<?php echo url('indicators/view?id=' . $mi['id']); ?>" class="text-decoration-none"><?php echo e($mi['name']); ?></a></td>
                            <td><span class="badge bg-secondary"><?php echo e($mi['category'] ?: 'Sem categoria'); ?></span></td>
                            <td class="text-end fw-bold text-danger"><?php echo format_number($mi['last'], $mi['decimal_places']); ?> <small class="text-muted"><?php echo e($mi['unit']); ?></small></td>
                            <td class="text-end text-muted"><?php echo format_number($mi['goal'], $mi['decimal_places']); ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (core_can('actions.view')): ?>
                                <a href="<?php echo url('indicators/actions?indicator_id=' . $mi['id']); ?>" class="btn btn-outline-primary btn-action" title="Planos de ação"><i class="bi bi-list-check"></i></a>
                                <?php endif; ?>
                            </td>
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
</div>
