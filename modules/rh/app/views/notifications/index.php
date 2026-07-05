<div class="page-header">
    <h1><i class="bi bi-bell me-2"></i>Notificações</h1>
    <form method="POST" action="index.php?page=notifications&action=mark_all_read">
        <?= Csrf::field() ?>
        <button type="submit" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-check-all me-1"></i> Marcar todas como lidas
        </button>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="text-center text-muted py-5">
                <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                Nenhuma notificação.
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
                <?php foreach ($notifications as $n): ?>
                    <div class="list-group-item d-flex align-items-start <?= !$n['is_read'] ? 'bg-light' : '' ?>">
                        <div class="me-3 mt-1">
                            <?php
                            $iconClass = match($n['type']) {
                                'warning' => 'bi-exclamation-triangle text-warning',
                                'danger'  => 'bi-x-circle text-danger',
                                'success' => 'bi-check-circle text-success',
                                default   => 'bi-info-circle text-info',
                            };
                            ?>
                            <i class="bi <?= $iconClass ?> fs-5"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <h6 class="mb-1 <?= !$n['is_read'] ? 'fw-bold' : '' ?>">
                                    <?php if ($n['link']): ?>
                                        <a href="index.php?page=notifications&action=read&id=<?= $n['id'] ?>" class="text-decoration-none text-dark">
                                            <?= Sanitize::e($n['title']) ?>
                                        </a>
                                    <?php else: ?>
                                        <?= Sanitize::e($n['title']) ?>
                                    <?php endif; ?>
                                </h6>
                                <small class="text-muted"><?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></small>
                            </div>
                            <p class="mb-1 small text-muted"><?= Sanitize::e($n['message']) ?></p>
                            <div class="d-flex gap-2">
                                <?php if (!$n['is_read']): ?>
                                    <a href="index.php?page=notifications&action=read&id=<?= $n['id'] ?>" class="small text-decoration-none">Marcar como lida</a>
                                <?php endif; ?>
                                <form method="POST" action="index.php?page=notifications&action=delete" class="d-inline">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="id" value="<?= $n['id'] ?>">
                                    <button type="submit" class="btn btn-link btn-sm text-danger p-0 small">Excluir</button>
                                </form>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php if ($total > 0): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= $total ?> notificação(ões)</small>
            <?= $pagination->render('index.php') ?>
        </div>
    <?php endif; ?>
</div>
