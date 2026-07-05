<?php
// Resultados vindos do SearchController (mantém as chaves usadas abaixo)
$messages       = $results['messages'] ?? [];
$users          = $results['users'] ?? [];
$channelResults = $results['channels'] ?? [];
$tasks          = $results['tasks'] ?? [];
?>
<div class="page-header">
    <h1><i class="bi bi-search me-2"></i>Busca</h1>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <form class="mb-4">
            <input type="hidden" name="m" value="chat">
            <input type="hidden" name="page" value="search">
            <div class="input-group input-group-lg">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" name="query" class="form-control" placeholder="Buscar mensagens, pessoas, canais, tarefas..."
                       value="<?= Sanitize::e($query ?? '') ?>" autofocus>
                <button type="submit" class="btn btn-primary">Buscar</button>
            </div>
        </form>

        <?php if (!empty($query)): ?>
            <!-- Messages -->
            <?php if (!empty($messages)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-chat me-2"></i>Mensagens (<?= count($messages) ?>)
                </div>
                <div class="card-body p-0">
                    <?php foreach ($messages as $msg): ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= $msg['channel_id'] ?>" class="search-result-item">
                        <div class="d-flex justify-content-between">
                            <strong><?= Sanitize::e($msg['user_name'] ?? 'Removido') ?></strong>
                            <span class="text-muted small">#<?= Sanitize::e($msg['channel_name'] ?? '') ?> · <?= Sanitize::timeAgo($msg['created_at']) ?></span>
                        </div>
                        <p class="mb-0 text-muted"><?= Sanitize::e(mb_substr($msg['content'], 0, 150)) ?></p>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Users -->
            <?php if (!empty($users)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-people me-2"></i>Pessoas (<?= count($users) ?>)
                </div>
                <div class="card-body p-0">
                    <?php foreach ($users as $u): ?>
                    <div class="search-result-item d-flex align-items-center">
                        <span class="dm-status <?= Sanitize::e($u['status']) ?> me-2"></span>
                        <div>
                            <strong><?= Sanitize::e($u['name']) ?></strong>
                            <span class="text-muted ms-2"><?= Sanitize::e($u['email']) ?></span>
                        </div>
                        <a href="index.php?m=chat&page=channels&action=direct&user_id=<?= $u['id'] ?>" class="btn btn-outline-primary btn-sm ms-auto">
                            <i class="bi bi-chat"></i>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Channels -->
            <?php if (!empty($channelResults)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-hash me-2"></i>Canais (<?= count($channelResults) ?>)
                </div>
                <div class="card-body p-0">
                    <?php foreach ($channelResults as $ch): ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= $ch['id'] ?>" class="search-result-item">
                        <strong>#<?= Sanitize::e($ch['name']) ?></strong>
                        <?php if ($ch['description']): ?>
                        <p class="mb-0 text-muted small"><?= Sanitize::e(mb_substr($ch['description'], 0, 100)) ?></p>
                        <?php endif; ?>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Tasks -->
            <?php if (!empty($tasks)): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">
                    <i class="bi bi-kanban me-2"></i>Tarefas (<?= count($tasks) ?>)
                </div>
                <div class="card-body p-0">
                    <?php foreach ($tasks as $task): ?>
                    <a href="index.php?m=chat&page=tasks&action=show&id=<?= $task['id'] ?>" class="search-result-item">
                        <strong><?= Sanitize::e($task['title']) ?></strong>
                        <span class="badge bg-<?= match($task['status']) {
                            'todo' => 'secondary', 'in_progress' => 'primary', 'review' => 'warning', 'done' => 'success', default => 'secondary'
                        } ?> ms-2 badge-sm"><?= $task['status'] ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if (empty($messages) && empty($users) && empty($channelResults) && empty($tasks)): ?>
            <div class="text-center py-5 text-muted">
                <i class="bi bi-search display-4"></i>
                <p class="mt-2">Nenhum resultado para "<?= Sanitize::e($query) ?>"</p>
            </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
