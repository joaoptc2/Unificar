<div class="page-header">
    <h1><i class="bi bi-download me-2"></i>Exportar Dados</h1>
    <a href="index.php?m=chat&page=admin" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <h6 class="card-title mb-3">Configurar Exportação</h6>
                <form method="POST" action="index.php?m=chat&page=admin&action=generateExport">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label required">Tipo de dados</label>
                            <select name="type" class="form-select" id="exportType" required>
                                <option value="messages">Mensagens</option>
                                <option value="users">Usuários</option>
                                <option value="channels">Canais</option>
                                <option value="tasks">Tarefas</option>
                                <option value="audit_log">Log de auditoria</option>
                            </select>
                        </div>
                        <div class="col-md-6" id="channelFilterGroup">
                            <label class="form-label">Canal (opcional)</label>
                            <select name="channel_id" class="form-select">
                                <option value="">Todos os canais</option>
                                <?php foreach ($channels ?? [] as $ch): ?>
                                <option value="<?= (int)$ch['id'] ?>"><?= Sanitize::e($ch['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">De</label>
                            <input type="date" name="date_from" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Até</label>
                            <input type="date" name="date_to" class="form-control">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Formato</label>
                            <select name="format" class="form-select">
                                <option value="csv">CSV</option>
                            </select>
                        </div>
                        <div class="col-12 text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-file-earmark-arrow-down me-1"></i> Gerar Exportação
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <?php if (!empty($exports)): ?>
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent">
                <h6 class="mb-0">Exportações Recentes</h6>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Tipo</th>
                                <th>Status</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($exports as $export): ?>
                            <tr>
                                <td class="text-nowrap small"><?= Sanitize::formatDateTime($export['created_at'] ?? '') ?></td>
                                <td>
                                    <?php
                                    $typeLabels = [
                                        'messages' => 'Mensagens',
                                        'users' => 'Usuários',
                                        'channels' => 'Canais',
                                        'tasks' => 'Tarefas',
                                        'audit_log' => 'Log de auditoria',
                                    ];
                                    ?>
                                    <?= $typeLabels[$export['type'] ?? ''] ?? Sanitize::e($export['type'] ?? '') ?>
                                </td>
                                <td>
                                    <?php
                                    $statusBadge = match($export['status'] ?? '') {
                                        'completed' => 'success',
                                        'processing' => 'warning',
                                        'failed' => 'danger',
                                        default => 'secondary'
                                    };
                                    $statusLabel = match($export['status'] ?? '') {
                                        'completed' => 'Concluído',
                                        'processing' => 'Processando',
                                        'failed' => 'Falhou',
                                        'pending' => 'Pendente',
                                        default => $export['status'] ?? ''
                                    };
                                    ?>
                                    <span class="badge bg-<?= $statusBadge ?>-subtle text-<?= $statusBadge ?>">
                                        <?= Sanitize::e($statusLabel) ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <?php if (($export['status'] ?? '') === 'completed' && !empty($export['file_path'])): ?>
                                        <a href="<?= Sanitize::e($export['file_path']) ?>" class="btn btn-outline-primary btn-sm" download>
                                            <i class="bi bi-download me-1"></i> Baixar
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.getElementById('exportType').addEventListener('change', function() {
    const channelGroup = document.getElementById('channelFilterGroup');
    channelGroup.style.display = this.value === 'messages' ? '' : 'none';
});
</script>
