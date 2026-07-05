<div class="page-header">
    <h1><i class="bi bi-journal-text me-2"></i>Log de Atividades</h1>
    <a href="index.php?page=admin" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="filter-panel">
    <form class="row g-2 align-items-end">
        <input type="hidden" name="page" value="admin">
        <input type="hidden" name="action" value="audit">
        <div class="col-md-3">
            <label class="form-label small">Usuário</label>
            <input type="text" name="user" class="form-control" placeholder="Nome do usuário..."
                   value="<?= Sanitize::e($search['user'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Ação</label>
            <select name="action_type" class="form-select">
                <option value="">Todas</option>
                <?php
                $actionTypes = [
                    'create' => 'Criar',
                    'update' => 'Atualizar',
                    'delete' => 'Excluir',
                    'login' => 'Login',
                    'logout' => 'Logout',
                    'send_message' => 'Mensagem',
                    'upload' => 'Upload',
                    'join' => 'Entrar',
                    'leave' => 'Sair',
                ];
                foreach ($actionTypes as $val => $label):
                ?>
                <option value="<?= $val ?>" <?= ($search['action_type'] ?? '') === $val ? 'selected' : '' ?>><?= $label ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small">Entidade</label>
            <input type="text" name="entity_type" class="form-control" placeholder="Ex: channel, user..."
                   value="<?= Sanitize::e($search['entity_type'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">De</label>
            <input type="date" name="date_from" class="form-control"
                   value="<?= Sanitize::e($search['date_from'] ?? '') ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label small">Até</label>
            <input type="date" name="date_to" class="form-control"
                   value="<?= Sanitize::e($search['date_to'] ?? '') ?>">
        </div>
        <div class="col-md-1">
            <button type="submit" class="btn btn-primary w-100">
                <i class="bi bi-search"></i>
            </button>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead>
                    <tr>
                        <th>Data/Hora</th>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>Entidade</th>
                        <th>ID</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">Nenhum registro encontrado.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td class="text-nowrap small"><?= Sanitize::formatDateTime($log['created_at'] ?? '') ?></td>
                        <td>
                            <?php if (!empty($log['user_name'])): ?>
                                <?= Sanitize::e($log['user_name']) ?>
                            <?php else: ?>
                                <span class="text-muted">ID: <?= (int)($log['user_id'] ?? 0) ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php
                            $badgeClass = match($log['action'] ?? '') {
                                'create' => 'success',
                                'delete' => 'danger',
                                'update' => 'warning',
                                'login', 'logout' => 'info',
                                default => 'secondary'
                            };
                            ?>
                            <span class="badge bg-<?= $badgeClass ?>-subtle text-<?= $badgeClass ?>">
                                <?= Sanitize::e($log['action'] ?? '') ?>
                            </span>
                        </td>
                        <td><?= Sanitize::e($log['entity_type'] ?? '') ?></td>
                        <td><?= (int)($log['entity_id'] ?? 0) ?></td>
                        <td class="small text-muted"><?= Sanitize::e($log['ip_address'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($pagination->totalPages > 1): ?>
    <div class="card-footer bg-transparent">
        <?php
        $paginationParams = '&page=admin&action=audit';
        if (!empty($search['user'])) $paginationParams .= '&user=' . urlencode($search['user']);
        if (!empty($search['action_type'])) $paginationParams .= '&action_type=' . urlencode($search['action_type']);
        if (!empty($search['entity_type'])) $paginationParams .= '&entity_type=' . urlencode($search['entity_type']);
        if (!empty($search['date_from'])) $paginationParams .= '&date_from=' . urlencode($search['date_from']);
        if (!empty($search['date_to'])) $paginationParams .= '&date_to=' . urlencode($search['date_to']);
        ?>
        <?= $pagination->render($paginationParams) ?>
    </div>
    <?php endif; ?>
</div>
