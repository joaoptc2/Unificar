<div class="page-header">
    <h1><i class="bi bi-journal-text me-2"></i>Log de Auditoria</h1>
    <a href="index.php?page=users" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Data/Hora</th>
                        <th>Usuário</th>
                        <th>Ação</th>
                        <th>Tabela</th>
                        <th>Registro</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhum registro de auditoria.</td></tr>
                    <?php else: foreach ($logs as $log): ?>
                        <tr>
                            <td><small><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></small></td>
                            <td><?= Sanitize::e($log['user_name'] ?? 'Sistema') ?></td>
                            <td>
                                <span class="badge <?= match($log['action']) {
                                    'create' => 'bg-success',
                                    'update' => 'bg-warning text-dark',
                                    'delete' => 'bg-danger',
                                    'login' => 'bg-info',
                                    'logout' => 'bg-secondary',
                                    default => 'bg-primary'
                                } ?>">
                                    <?= ucfirst($log['action']) ?>
                                </span>
                            </td>
                            <td><small><?= Sanitize::e($log['table_name']) ?></small></td>
                            <td><small>#<?= $log['record_id'] ?></small></td>
                            <td><small class="text-muted"><?= Sanitize::e($log['ip_address']) ?></small></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total > 0): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= $total ?> registro(s)</small>
            <?= $pagination->render('index.php') ?>
        </div>
    <?php endif; ?>
</div>
