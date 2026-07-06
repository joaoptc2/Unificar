<div class="page-header">
    <h1><i class="bi bi-diagram-3 me-2"></i><?= Sanitize::e($process['title']) ?></h1>
    <div class="d-flex gap-2">
        <?php if (core_can('processes.edit')): ?>
        <a href="index.php?m=chat&page=processes&action=edit&id=<?= $process['id'] ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i> Editar
        </a>
        <?php endif; ?>
        <a href="index.php?m=chat&page=processes" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold d-flex justify-content-between align-items-center">
                <span><i class="bi bi-list-check me-2"></i>Etapas</span>
                <span class="badge bg-primary"><?= (int)$process['progress'] ?>%</span>
            </div>
            <div class="card-body">
                <div class="progress mb-4" style="height: 10px">
                    <div class="progress-bar bg-<?= $process['progress'] >= 100 ? 'success' : 'primary' ?>"
                         id="processProgress" style="width: <?= (int)$process['progress'] ?>%"></div>
                </div>

                <?php if (empty($process['steps'])): ?>
                    <p class="text-muted text-center">Nenhuma etapa definida.</p>
                <?php else: ?>
                    <div class="process-steps">
                        <?php foreach ($process['steps'] as $step): ?>
                        <div class="process-step <?= $step['status'] ?>" data-step-id="<?= $step['id'] ?>">
                            <div class="step-indicator">
                                <?php if ($step['status'] === 'completed'): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                <?php elseif ($step['status'] === 'in_progress'): ?>
                                    <i class="bi bi-arrow-repeat text-primary"></i>
                                <?php elseif ($step['status'] === 'skipped'): ?>
                                    <i class="bi bi-skip-forward-fill text-secondary"></i>
                                <?php else: ?>
                                    <i class="bi bi-circle text-muted"></i>
                                <?php endif; ?>
                            </div>
                            <div class="step-content">
                                <div class="step-header">
                                    <span class="step-title"><?= Sanitize::e($step['title']) ?></span>
                                    <?php if ($step['assigned_name']): ?>
                                    <span class="text-muted small"><i class="bi bi-person me-1"></i><?= Sanitize::e($step['assigned_name']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($step['description']): ?>
                                <p class="step-desc text-muted small"><?= Sanitize::e($step['description']) ?></p>
                                <?php endif; ?>
                                <div class="step-meta">
                                    <?php if ($step['due_date']): ?>
                                    <span class="small"><i class="bi bi-calendar me-1"></i><?= Sanitize::formatDate($step['due_date']) ?></span>
                                    <?php endif; ?>
                                    <?php if ($step['completed_at']): ?>
                                    <span class="small text-success"><i class="bi bi-check me-1"></i>Concluída em <?= Sanitize::formatDateTime($step['completed_at']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($process['status'] === 'active' && $step['status'] !== 'completed' && $step['status'] !== 'skipped'): ?>
                                <div class="step-actions mt-2">
                                    <button class="btn btn-success btn-sm step-action-btn"
                                            data-step-id="<?= $step['id'] ?>" data-status="completed" data-process-id="<?= $process['id'] ?>">
                                        <i class="bi bi-check-lg me-1"></i>Concluir
                                    </button>
                                    <button class="btn btn-outline-primary btn-sm step-action-btn"
                                            data-step-id="<?= $step['id'] ?>" data-status="in_progress" data-process-id="<?= $process['id'] ?>">
                                        <i class="bi bi-play me-1"></i>Iniciar
                                    </button>
                                    <button class="btn btn-outline-secondary btn-sm step-action-btn"
                                            data-step-id="<?= $step['id'] ?>" data-status="skipped" data-process-id="<?= $process['id'] ?>">
                                        Pular
                                    </button>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="detail-item">
                    <label>Status</label>
                    <span class="badge bg-<?= match($process['status']) {
                        'active' => 'success', 'paused' => 'warning', 'completed' => 'primary', default => 'secondary'
                    } ?>"><?= match($process['status']) {
                        'active' => 'Ativo', 'paused' => 'Pausado', 'completed' => 'Concluído', 'cancelled' => 'Cancelado', default => $process['status']
                    } ?></span>
                </div>
                <?php if ($process['description']): ?>
                <div class="detail-item">
                    <label>Descrição</label>
                    <p class="small"><?= nl2br(Sanitize::e($process['description'])) ?></p>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <label>Criado por</label>
                    <span><?= Sanitize::e($process['creator_name'] ?? 'Desconhecido') ?></span>
                </div>
                <div class="detail-item">
                    <label>Criado em</label>
                    <span><?= Sanitize::formatDateTime($process['created_at']) ?></span>
                </div>
            </div>
        </div>

        <?php if ($process['status'] === 'active' && core_can('processes.edit')): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body d-grid gap-2">
                <form method="POST" action="index.php?m=chat&page=processes&action=pause">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $process['id'] ?>">
                    <button class="btn btn-warning btn-sm w-100"><i class="bi bi-pause me-1"></i>Pausar</button>
                </form>
                <form method="POST" action="index.php?m=chat&page=processes&action=complete">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $process['id'] ?>">
                    <button class="btn btn-success btn-sm w-100"><i class="bi bi-check-all me-1"></i>Concluir</button>
                </form>
            </div>
        </div>
        <?php elseif ($process['status'] === 'paused' && core_can('processes.edit')): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=processes&action=resume">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= $process['id'] ?>">
                    <button class="btn btn-primary btn-sm w-100"><i class="bi bi-play me-1"></i>Retomar</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
