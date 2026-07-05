<div class="page-header">
    <h1><i class="bi bi-kanban me-2"></i>Tarefa #<?= $task['id'] ?></h1>
    <div class="d-flex gap-2">
        <a href="index.php?page=tasks&action=edit&id=<?= $task['id'] ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i> Editar
        </a>
        <a href="index.php?page=tasks" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h2 class="h4 mb-3"><?= Sanitize::e($task['title']) ?></h2>

                <?php if ($task['description']): ?>
                <div class="task-description mb-4">
                    <?= nl2br(Sanitize::e($task['description'])) ?>
                </div>
                <?php endif; ?>

                <!-- Comments -->
                <h5 class="mt-4 mb-3"><i class="bi bi-chat-left-text me-2"></i>Comentários</h5>
                <?php if (empty($task['comments'])): ?>
                    <p class="text-muted">Nenhum comentário ainda.</p>
                <?php else: ?>
                    <?php foreach ($task['comments'] as $comment): ?>
                    <div class="comment-item">
                        <div class="comment-header">
                            <strong><?= Sanitize::e($comment['user_name'] ?? 'Removido') ?></strong>
                            <span class="text-muted small"><?= Sanitize::timeAgo($comment['created_at']) ?></span>
                        </div>
                        <div class="comment-body"><?= nl2br(Sanitize::e($comment['content'])) ?></div>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <form method="POST" action="index.php?page=tasks&action=comment" class="mt-3">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="task_id" value="<?= $task['id'] ?>">
                    <div class="input-group">
                        <textarea name="content" class="form-control" rows="2" placeholder="Adicionar comentário..." required></textarea>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send"></i></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <div class="detail-item">
                    <label>Status</label>
                    <span class="badge bg-<?= match($task['status']) {
                        'todo' => 'secondary', 'in_progress' => 'primary',
                        'review' => 'warning', 'done' => 'success', default => 'secondary'
                    } ?>"><?= match($task['status']) {
                        'todo' => 'A Fazer', 'in_progress' => 'Em Progresso',
                        'review' => 'Em Revisão', 'done' => 'Concluído', default => $task['status']
                    } ?></span>
                </div>
                <div class="detail-item">
                    <label>Prioridade</label>
                    <span class="badge bg-<?= match($task['priority']) {
                        'urgent' => 'danger', 'high' => 'warning',
                        'medium' => 'info', 'low' => 'secondary', default => 'secondary'
                    } ?>"><?= ucfirst($task['priority']) ?></span>
                </div>
                <?php if ($task['due_date']): ?>
                <div class="detail-item">
                    <label>Data Limite</label>
                    <span><?= Sanitize::formatDate($task['due_date']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <label>Criado por</label>
                    <span><?= Sanitize::e($task['creator_name'] ?? 'Desconhecido') ?></span>
                </div>
                <div class="detail-item">
                    <label>Criado em</label>
                    <span><?= Sanitize::formatDateTime($task['created_at']) ?></span>
                </div>
                <?php if (!empty($task['assignees'])): ?>
                <div class="detail-item">
                    <label>Responsáveis</label>
                    <div class="assignee-list">
                        <?php foreach ($task['assignees'] as $a): ?>
                        <div class="assignee-chip">
                            <span class="avatar-initials-sm"><?= Sanitize::e(User::initials($a['name'])) ?></span>
                            <?= Sanitize::e($a['name']) ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
