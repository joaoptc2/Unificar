<?php
$type_labels  = ['corrective'=>'Corretiva','preventive'=>'Preventiva','improvement'=>'Melhoria'];
$type_colors  = ['corrective'=>'danger','preventive'=>'warning','improvement'=>'info'];
$st_labels    = ['pending'=>'Pendente','in_progress'=>'Em andamento','done'=>'Concluída','cancelled'=>'Cancelada'];
$st_colors    = ['pending'=>'bg-secondary','in_progress'=>'bg-primary','done'=>'bg-success','cancelled'=>'bg-dark'];
?>
<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Planos de Ação</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('actions.create') && ($indicator || !empty($indicators_all))): ?>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalAction">
            <i class="bi bi-plus-lg me-1"></i>Nova Ação
        </button>
        <?php endif; ?>
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
                            <?php if (!$indicator): ?><th>Indicador</th><?php endif; ?>
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
                            <?php if (!$indicator): ?>
                            <td class="small">
                                <?php if (!empty($a['indicator_name'])): ?>
                                    <a href="<?php echo url('indicators/view?id=' . (int) $a['indicator_id']); ?>"
                                       class="text-decoration-none">
                                        <i class="bi bi-graph-up me-1"></i><?php echo e($a['indicator_name']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <td><span class="badge bg-<?php echo $ac; ?>"><?php echo $at; ?></span></td>
                            <td class="small"><?php echo e($a['responsible'] ?: '—'); ?></td>
                            <td class="small">
                                <?php echo $a['due_date'] ? format_date($a['due_date']) : '—'; ?>
                                <?php if ($is_overdue): ?><br><small class="text-danger fw-bold">ATRASADA</small><?php endif; ?>
                            </td>
                            <td><span class="badge <?php echo $st_colors[$a['status']] ?? 'bg-secondary'; ?>"><?php echo $st_labels[$a['status']] ?? $a['status']; ?></span></td>
                            <td class="text-end">
                                <?php if (core_can('actions.edit') && !empty($indicators_all)): ?>
                                <button class="btn btn-outline-secondary btn-action" type="button"
                                        data-bs-toggle="tooltip" title="Vincular a outro indicador"
                                        onclick="relinkAction(<?php echo (int) $a['id']; ?>, <?php echo (int) $a['indicator_id']; ?>, <?php echo e(json_encode($a['title'])); ?>)">
                                    <i class="bi bi-link-45deg"></i>
                                </button>
                                <?php endif; ?>
                                <?php if (!core_can('actions.edit')): ?>
                                <?php elseif ($a['status'] === 'pending'): ?>
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
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold">Nova Ação</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if ($indicator): ?>
                        <input type="hidden" name="indicator_id" value="<?php echo (int) $indicator['id']; ?>">
                        <div class="mb-3">
                            <label class="form-label">Indicador vinculado</label>
                            <input type="text" class="form-control" value="<?php echo e($indicator['name']); ?>" disabled>
                            <div class="form-text">
                                Para vincular a ação a outro indicador, abra
                                <a href="<?php echo url('indicators/actions'); ?>">todos os planos de ação</a>.
                            </div>
                        </div>
                    <?php elseif (empty($indicators_all)): ?>
                        <div class="alert alert-warning py-2 small">
                            <i class="bi bi-exclamation-triangle me-1"></i>
                            Nenhum indicador disponível nos seus setores. Cadastre um indicador antes de criar o plano de ação.
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label required" for="acIndicador">Indicador</label>
                            <select name="indicator_id" id="acIndicador" class="form-select" required>
                                <option value="">— Selecione o indicador —</option>
                                <?php
                                // Agrupa por setor: numa lista longa é o que
                                // permite achar o indicador certo de relance.
                                $porSetor = [];
                                foreach ($indicators_all as $ind) {
                                    $porSetor[$ind['sector_name'] ?: 'Sem setor'][] = $ind;
                                }
                                ksort($porSetor);
                                ?>
                                <?php foreach ($porSetor as $setor => $lista): ?>
                                <optgroup label="<?php echo e($setor); ?>">
                                    <?php foreach ($lista as $ind): ?>
                                        <option value="<?php echo (int) $ind['id']; ?>"><?php echo e($ind['name']); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">O plano de ação sempre pertence a um indicador — é dele que sai a meta que a ação persegue.</div>
                        </div>
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
                    <button type="submit" class="btn btn-primary" <?php echo (!$indicator && empty($indicators_all)) ? 'disabled' : ''; ?>>
                        <i class="bi bi-check-lg me-1"></i>Registrar
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (core_can('actions.edit') && !empty($indicators_all)): ?>
<!-- Modal: vincular o plano de ação a outro indicador -->
<div class="modal fade" id="modalRelink" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?php echo url('indicators/action-relink'); ?>">
                <?php echo csrf_field(); ?>
                <input type="hidden" name="action_id" id="rl_action_id">
                <div class="modal-header py-2">
                    <h5 class="modal-title fw-semibold">Vincular indicador</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted">Ação: <strong id="rl_titulo"></strong></p>
                    <label class="form-label required" for="rl_indicator">Indicador</label>
                    <select name="indicator_id" id="rl_indicator" class="form-select" required>
                        <?php
                        $porSetorRl = [];
                        foreach ($indicators_all as $ind) {
                            $porSetorRl[$ind['sector_name'] ?: 'Sem setor'][] = $ind;
                        }
                        ksort($porSetorRl);
                        ?>
                        <?php foreach ($porSetorRl as $setor => $lista): ?>
                        <optgroup label="<?php echo e($setor); ?>">
                            <?php foreach ($lista as $ind): ?>
                                <option value="<?php echo (int) $ind['id']; ?>"><?php echo e($ind['name']); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-link-45deg me-1"></i>Vincular</button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function relinkAction(actionId, indicatorId, titulo) {
    document.getElementById('rl_action_id').value = actionId;
    document.getElementById('rl_titulo').textContent = titulo;
    document.getElementById('rl_indicator').value = indicatorId;
    new bootstrap.Modal(document.getElementById('modalRelink')).show();
}
</script>
<?php endif; ?>
