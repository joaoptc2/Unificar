<?php
$type_labels  = ['corrective'=>'Corretiva','preventive'=>'Preventiva','improvement'=>'Melhoria'];
$type_colors  = ['corrective'=>'danger','preventive'=>'warning','improvement'=>'info'];
$st_labels    = ['pending'=>'Pendente','in_progress'=>'Em andamento','done'=>'Concluída','cancelled'=>'Cancelada'];
$st_colors    = ['pending'=>'bg-secondary','in_progress'=>'bg-primary','done'=>'bg-success','cancelled'=>'bg-dark'];
?>
<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Planos de Ação</h1>
    <div class="d-flex gap-2">
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAction">
            <i class="bi bi-plus-lg me-1"></i>Nova Ação
        </button>
        <?php if ($indicator): ?>
        <a href="<?php echo url('indicators/view?id=' . $indicator['id']); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar ao indicador
        </a>
        <?php else: ?>
        <a href="<?php echo url('indicators'); ?>" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Voltar
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if ($indicator): ?>
<p class="text-muted small mb-3">Indicador: <strong><?php echo e($indicator['name']); ?></strong></p>
<?php endif; ?>

<!-- KPIs -->
<?php
$total = count($actions);
$pending = count(array_filter($actions, fn($a) => $a['status'] === 'pending'));
$in_prog = count(array_filter($actions, fn($a) => $a['status'] === 'in_progress'));
$done    = count(array_filter($actions, fn($a) => $a['status'] === 'done'));
$overdue = count(array_filter($actions, fn($a) => $a['due_date'] && $a['status'] !== 'done' && $a['status'] !== 'cancelled' && strtotime($a['due_date']) < time()));
?>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-secondary-bg-opacity-10 text-secondary me-3"><i class="bi bi-hourglass"></i></div>
        <div><div class="stat-value"><?php echo $pending; ?></div><div class="stat-label">Pendentes</div></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-primary-bg-opacity-10 text-primary me-3"><i class="bi bi-gear"></i></div>
        <div><div class="stat-value"><?php echo $in_prog; ?></div><div class="stat-label">Em andamento</div></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-success-bg-opacity-10 text-success me-3"><i class="bi bi-check-circle"></i></div>
        <div><div class="stat-value"><?php echo $done; ?></div><div class="stat-label">Concluídas</div></div>
    </div></div></div>
    <div class="col-6 col-md-3"><div class="card stat-card shadow-sm"><div class="card-body d-flex align-items-center">
        <div class="stat-icon bg-danger-bg-opacity-10 text-danger me-3"><i class="bi bi-clock"></i></div>
        <div><div class="stat-value text-danger"><?php echo $overdue; ?></div><div class="stat-label">Atrasadas</div></div>
    </div></div></div>
</div>

<!-- Lista -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($actions)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-list-check display-1"></i>
                <p class="mt-2">Nenhuma ação registrada.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Ação</th>
                            <th>Tipo</th>
                            <th>Responsável</th>
                            <th>Prazo</th>
                            <th>Status</th>
                            <th class="text-end">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($actions as $a):
                        $is_overdue = $a['due_date'] && $a['status'] !== 'done' && $a['status'] !== 'cancelled' && strtotime($a['due_date']) < time();
                        $at = $type_labels[$a['action_type'] ?? 'corrective'] ?? '?';
                        $ac = $type_colors[$a['action_type'] ?? 'corrective'] ?? 'secondary';
                    ?>
                        <tr class="<?php echo $is_overdue ? 'table-danger' : ''; ?>">
                            <td>
                                <div class="fw-semibold"><?php echo e($a['title']); ?></div>
                                <?php if (!empty($a['description'])): ?>
                                    <small class="text-muted"><?php echo e(substr($a['description'], 0, 80)); ?></small>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-<?php echo $ac; ?>"><?php echo $at; ?></span></td>
                            <td class="small"><?php echo e($a['responsible'] ?: '—'); ?></td>
                            <td class="small">
                                <?php echo $a['due_date'] ? format_date($a['due_date']) : '—'; ?>
                                <?php if ($is_overdue): ?><br><small class="text-danger fw-bold">ATRASADA</small><?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $st_colors[$a['status']] ?? 'bg-secondary'; ?>"><?php echo $st_labels[$a['status']] ?? $a['status']; ?></span></td>
                            <td class="text-end">
                                <?php if ($a['status'] === 'pending'): ?>
                                <form method="POST" action="<?php echo url('indicators/action_update_status'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action_id" value="<?php echo (int) $a['id']; ?>">
                                    <input type="hidden" name="indicator_id" value="<?php echo (int) $a['indicator_id']; ?>">
                                    <input type="hidden" name="new_status" value="in_progress">
                                    <button class="btn btn-outline-primary btn-action" data-bs-toggle="tooltip" title="Iniciar">
                                        <i class="bi bi-play"></i>
                                    </button>
                                </form>
                                <?php elseif ($a['status'] === 'in_progress'): ?>
                                <form method="POST" action="<?php echo url('indicators/action_update_status'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="action_id" value="<?php echo (int) $a['id']; ?>">
                                    <input type="hidden" name="indicator_id" value="<?php echo (int) $a['indicator_id']; ?>">
                                    <input type="hidden" name="new_status" value="done">
                                    <button class="btn btn-outline-success btn-action" data-bs-toggle="tooltip" title="Concluir">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
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

<!-- Modal Nova Ação -->
<div class="modal fade" id="modalAction" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('indicators/action_store'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="indicator_id" value="<?php echo (int) ($indicator['id'] ?? 0); ?>">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold">Nova Ação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (!$indicator): ?>
                    <div class="alert alert-warning py-2 small">Selecione um indicador antes de criar ações.</div>
                    <?php endif; ?>
                    <div class="mb-3">
                        <label class="form-label required">Título da ação</label>
                        <input type="text" name="title" class="form-control" required placeholder="O que será feito?">
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Tipo</label>
                            <select name="action_type" class="form-select">
                                <option value="corrective">Corretiva</option>
                                <option value="preventive">Preventiva</option>
                                <option value="improvement">Melhoria</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Prazo</label>
                            <input type="date" name="due_date" class="form-control">
                        </div>
                    </div>
                    <div class="mb-3 mt-3">
                        <label class="form-label">Responsável</label>
                        <input type="text" name="responsible" class="form-control" placeholder="Quem vai executar?">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Causa raiz</label>
                        <textarea name="root_cause" class="form-control" rows="2" placeholder="Por que o indicador saiu da meta?"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Descrição da ação</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Detalhes do que será feito"></textarea>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary" <?php echo !$indicator ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-lg me-1"></i>Registrar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
