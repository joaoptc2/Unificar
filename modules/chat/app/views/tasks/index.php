<div class="page-header">
    <h1><i class="bi bi-kanban me-2"></i>Tarefas</h1>
    <div class="d-flex gap-2">
        <a href="index.php?page=tasks&action=my" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-person me-1"></i> Minhas Tarefas
        </a>
        <a href="index.php?page=tasks&action=create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Nova Tarefa
        </a>
    </div>
</div>

<!-- Stats Cards -->
<div class="row g-3 mb-4">
    <?php
    $statCards = [
        ['label' => 'A Fazer', 'key' => 'todo', 'icon' => 'circle', 'color' => 'secondary'],
        ['label' => 'Em Progresso', 'key' => 'in_progress', 'icon' => 'arrow-repeat', 'color' => 'primary'],
        ['label' => 'Em Revisão', 'key' => 'review', 'icon' => 'eye', 'color' => 'warning'],
        ['label' => 'Concluídas', 'key' => 'done', 'icon' => 'check-circle', 'color' => 'success'],
    ];
    foreach ($statCards as $sc):
    ?>
    <div class="col-6 col-md-3">
        <div class="stat-card">
            <div class="stat-icon bg-<?= $sc['color'] ?>-subtle text-<?= $sc['color'] ?>">
                <i class="bi bi-<?= $sc['icon'] ?>"></i>
            </div>
            <div class="stat-value"><?= $stats[$sc['key']] ?? 0 ?></div>
            <div class="stat-label"><?= $sc['label'] ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Kanban Board -->
<div class="kanban-board">
    <?php
    $columns = [
        'todo'        => ['label' => 'A Fazer',       'icon' => 'circle',       'color' => 'secondary'],
        'in_progress' => ['label' => 'Em Progresso',   'icon' => 'arrow-repeat', 'color' => 'primary'],
        'review'      => ['label' => 'Em Revisão',     'icon' => 'eye',          'color' => 'warning'],
        'done'        => ['label' => 'Concluído',      'icon' => 'check-circle', 'color' => 'success'],
    ];
    foreach ($columns as $status => $col):
    ?>
    <div class="kanban-column" data-status="<?= $status ?>">
        <div class="kanban-column-header">
            <span class="badge bg-<?= $col['color'] ?>-subtle text-<?= $col['color'] ?>">
                <i class="bi bi-<?= $col['icon'] ?> me-1"></i><?= $col['label'] ?>
            </span>
            <span class="text-muted small"><?= count($tasksByStatus[$status] ?? []) ?></span>
        </div>
        <div class="kanban-column-body" data-status="<?= $status ?>">
            <?php foreach (($tasksByStatus[$status] ?? []) as $task): ?>
            <div class="kanban-card" data-task-id="<?= $task['id'] ?>">
                <div class="kanban-card-header">
                    <span class="badge bg-<?= match($task['priority']) {
                        'urgent' => 'danger',
                        'high' => 'warning',
                        'medium' => 'info',
                        'low' => 'secondary',
                        default => 'secondary'
                    } ?> badge-sm"><?= ucfirst($task['priority']) ?></span>
                    <?php if ($task['due_date']): ?>
                    <span class="text-muted small">
                        <i class="bi bi-clock me-1"></i><?= Sanitize::formatDate($task['due_date']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                <a href="index.php?page=tasks&action=show&id=<?= $task['id'] ?>" class="kanban-card-title">
                    <?= Sanitize::e($task['title']) ?>
                </a>
                <?php if (!empty($task['assignee_names'])): ?>
                <div class="kanban-card-footer">
                    <small class="text-muted">
                        <i class="bi bi-person me-1"></i><?= Sanitize::e($task['assignee_names']) ?>
                    </small>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
