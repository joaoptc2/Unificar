<?php $isEdit = !empty($task['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-kanban me-2"></i><?= $isEdit ? 'Editar Tarefa' : 'Nova Tarefa' ?></h1>
    <a href="index.php?page=tasks" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=tasks&action=<?= $isEdit ? 'update' : 'store' ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $task['id'] ?>">
                    <?php endif; ?>
                    <?php if (!empty($channelId)): ?>
                        <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= Sanitize::e($task['title'] ?? '') ?>" autofocus>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="4"><?= Sanitize::e($task['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <?php
                                $statuses = ['todo' => 'A Fazer', 'in_progress' => 'Em Progresso', 'review' => 'Em Revisão', 'done' => 'Concluído'];
                                foreach ($statuses as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= ($task['status'] ?? 'todo') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Prioridade</label>
                            <select name="priority" class="form-select">
                                <?php
                                $priorities = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'urgent' => 'Urgente'];
                                foreach ($priorities as $val => $label):
                                ?>
                                <option value="<?= $val ?>" <?= ($task['priority'] ?? 'medium') === $val ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Data limite</label>
                            <input type="date" name="due_date" class="form-control"
                                   value="<?= Sanitize::e($task['due_date'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Responsáveis</label>
                            <select name="assignees[]" class="form-select" multiple size="5">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"
                                    <?= in_array($u['id'], $assigneeIds ?? []) ? 'selected' : '' ?>>
                                    <?= Sanitize::e($u['name']) ?> (<?= Sanitize::e($u['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar múltiplos</div>
                        </div>

                        <?php if (!$isEdit && empty($channelId)): ?>
                        <div class="col-12">
                            <label class="form-label">Vincular a canal (opcional)</label>
                            <select name="channel_id" class="form-select">
                                <option value="">Nenhum</option>
                                <?php foreach ($channels ?? [] as $ch): ?>
                                    <?php if (($ch['type'] ?? '') !== 'direct' && ($ch['type'] ?? '') !== 'private'): ?>
                                    <option value="<?= $ch['id'] ?>"><?= Sanitize::e($ch['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 text-end">
                            <a href="index.php?page=tasks" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Criar Tarefa' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
