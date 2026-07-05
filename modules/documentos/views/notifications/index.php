<div class="page-header">
    <h1><i class="bi bi-bell me-2"></i>Notificações</h1>
    <div class="d-flex gap-2">
        <form method="POST" action="<?php echo url('notifications/readall'); ?>" class="d-inline">
            <?php echo csrf_field(); ?>
            <button type="submit" class="btn btn-outline-primary btn-sm">
                <i class="bi bi-check2-all me-1"></i>Marcar todas como lidas
            </button>
        </form>
    </div>
</div>

<div class="mb-3">
    <div class="btn-group btn-group-sm">
        <a href="<?php echo url('notifications?filter=all'); ?>"
           class="btn <?php echo $filter === 'all' ? 'btn-primary' : 'btn-outline-primary'; ?>">Todas</a>
        <a href="<?php echo url('notifications?filter=unread'); ?>"
           class="btn <?php echo $filter === 'unread' ? 'btn-primary' : 'btn-outline-primary'; ?>">Não lidas</a>
        <a href="<?php echo url('notifications?filter=read'); ?>"
           class="btn <?php echo $filter === 'read' ? 'btn-primary' : 'btn-outline-primary'; ?>">Lidas</a>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <?php if (empty($notifications)): ?>
            <div class="text-center py-5">
                <i class="bi bi-bell-slash display-1 text-muted"></i>
                <p class="text-muted mt-2">Nenhuma notificação encontrada.</p>
            </div>
        <?php else: ?>
            <div class="list-group list-group-flush">
            <?php foreach ($notifications as $n):
                $type_icons = [
                    'document_expiring' => 'bi-exclamation-triangle text-warning',
                    'document_expired'  => 'bi-x-circle text-danger',
                    'info'              => 'bi-info-circle text-info',
                    'success'           => 'bi-check-circle text-success',
                    'warning'           => 'bi-exclamation-triangle text-warning',
                    'danger'            => 'bi-x-circle text-danger',
                ];
                $icon = $type_icons[$n['type']] ?? 'bi-bell text-primary';
            ?>
                <div class="list-group-item <?php echo $n['is_read'] ? '' : 'bg-light'; ?>">
                    <div class="d-flex align-items-start">
                        <i class="bi <?php echo $icon; ?> fs-5 me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <div class="d-flex justify-content-between">
                                <h6 class="mb-1 small <?php echo $n['is_read'] ? '' : 'fw-bold'; ?>">
                                    <?php echo e($n['title']); ?>
                                </h6>
                                <small class="text-muted"><?php echo format_datetime($n['created_at']); ?></small>
                            </div>
                            <p class="mb-1 small text-muted"><?php echo e($n['message']); ?></p>
                            <div class="d-flex gap-2">
                                <?php if (!$n['is_read']): ?>
                                <form method="POST" action="<?php echo url('notifications/read'); ?>" class="d-inline">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                    <button type="submit" class="btn btn-outline-primary btn-action">
                                        <i class="bi bi-check me-1"></i>Marcar como lida
                                    </button>
                                </form>
                                <?php endif; ?>
                                <form method="POST" action="<?php echo url('notifications/delete'); ?>" class="d-inline"
                                      data-confirm="Remover esta notificação?">
                                    <?php echo csrf_field(); ?>
                                    <input type="hidden" name="id" value="<?php echo (int) $n['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-action">
                                        <i class="bi bi-trash me-1"></i>Remover
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            </div>
            <?php if (!empty($pagination) && $pagination['pages'] > 1): ?>
            <div class="card-footer bg-transparent"><?php echo pagination_html($pagination); ?></div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
