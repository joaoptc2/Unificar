<div class="page-header">
    <h1><i class="bi bi-person-check me-2"></i>Minhas Tarefas</h1>
    <a href="index.php?m=chat&page=tasks" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-kanban me-1"></i> Ver Quadro
    </a>
</div>

<?php if (empty($tasks)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-check2-all display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhuma tarefa atribuída a você.</p>
        </div>
    </div>
<?php else: ?>
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Tarefa</th>
                        <th>Status</th>
                        <th>Prioridade</th>
                        <th>Data Limite</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($tasks as $task): ?>
                    <tr>
                        <td>
                            <a href="index.php?m=chat&page=tasks&action=show&id=<?= $task['id'] ?>" class="fw-semibold text-decoration-none">
                                <?= Sanitize::e($task['title']) ?>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-<?= match($task['status']) {
                                'todo' => 'secondary', 'in_progress' => 'primary',
                                'review' => 'warning', 'done' => 'success', default => 'secondary'
                            } ?> badge-sm"><?= match($task['status']) {
                                'todo' => 'A Fazer', 'in_progress' => 'Em Progresso',
                                'review' => 'Em Revisão', 'done' => 'Concluído', default => $task['status']
                            } ?></span>
                        </td>
                        <td>
                            <span class="badge bg-<?= match($task['priority']) {
                                'urgent' => 'danger', 'high' => 'warning',
                                'medium' => 'info', 'low' => 'secondary', default => 'secondary'
                            } ?> badge-sm"><?= ucfirst($task['priority']) ?></span>
                        </td>
                        <td>
                            <?php if ($task['due_date']): ?>
                                <?php
                                $isOverdue = $task['status'] !== 'done' && $task['due_date'] < date('Y-m-d');
                                ?>
                                <span class="<?= $isOverdue ? 'text-danger fw-semibold' : '' ?>">
                                    <?= Sanitize::formatDate($task['due_date']) ?>
                                </span>
                            <?php else: ?>
                                <span class="text-muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end">
                            <a href="index.php?m=chat&page=tasks&action=edit&id=<?= $task['id'] ?>" class="btn btn-outline-warning btn-action">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>
