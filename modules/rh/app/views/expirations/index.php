<div class="page-header">
    <h1><i class="bi bi-clock-history me-2"></i>Vencimentos e Obrigações</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('expirations.export')): ?>
            <a href="index.php?m=rh&page=expirations&action=export" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Exportar
            </a>
        <?php endif; ?>
        <?php if (core_can('expirations.create')): ?>
            <a href="index.php?m=rh&page=expirations&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo Vencimento
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="expirations">
        <div class="col-md-3">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Funcionário ou título..." value="<?= Sanitize::e($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Tipo</label>
            <select name="type" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="exame_periodico" <?= $type === 'exame_periodico' ? 'selected' : '' ?>>Exame Periódico</option>
                <option value="aso_admissional" <?= $type === 'aso_admissional' ? 'selected' : '' ?>>ASO Admissional</option>
                <option value="aso_demissional" <?= $type === 'aso_demissional' ? 'selected' : '' ?>>ASO Demissional</option>
                <option value="aso_periodico" <?= $type === 'aso_periodico' ? 'selected' : '' ?>>ASO Periódico</option>
                <option value="certificacao" <?= $type === 'certificacao' ? 'selected' : '' ?>>Certificação</option>
                <option value="treinamento" <?= $type === 'treinamento' ? 'selected' : '' ?>>Treinamento</option>
                <option value="conselho_regional" <?= $type === 'conselho_regional' ? 'selected' : '' ?>>Conselho Regional</option>
                <option value="outro" <?= $type === 'outro' ? 'selected' : '' ?>>Outro</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="valido" <?= $status === 'valido' ? 'selected' : '' ?>>Válido</option>
                <option value="proximo" <?= $status === 'proximo' ? 'selected' : '' ?>>Próximo do Vencimento</option>
                <option value="vencido" <?= $status === 'vencido' ? 'selected' : '' ?>>Vencido</option>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            <a href="index.php?m=rh&page=expirations" class="btn btn-outline-secondary btn-sm">Limpar</a>
        </div>
    </form>
</div>

<!-- Tabela -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Funcionário</th>
                        <th>Tipo</th>
                        <th>Título</th>
                        <th>Vencimento</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expirations)): ?>
                        <tr><td colspan="6" class="text-center text-muted py-4">Nenhum vencimento encontrado.</td></tr>
                    <?php else: foreach ($expirations as $exp): ?>
                        <?php
                        $today = new DateTime();
                        $expDate = new DateTime($exp['expiry_date']);
                        $diff = (int)$today->diff($expDate)->format('%r%a');
                        if ($diff < 0) { $statusBadge = 'badge-vencido'; $statusText = 'Vencido'; }
                        elseif ($diff <= $exp['alert_days']) { $statusBadge = 'badge-proximo'; $statusText = "Vence em {$diff}d"; }
                        else { $statusBadge = 'badge-valido'; $statusText = 'Válido'; }
                        ?>
                        <tr>
                            <td>
                                <a href="index.php?m=rh&page=employees&action=show&id=<?= $exp['employee_id'] ?>" class="text-decoration-none">
                                    <?= Sanitize::e($exp['employee_name']) ?>
                                </a>
                            </td>
                            <td><small><?= Sanitize::e(ucfirst(str_replace('_', ' ', $exp['type']))) ?></small></td>
                            <td><?= Sanitize::e($exp['title']) ?></td>
                            <td><?= Sanitize::formatDate($exp['expiry_date']) ?></td>
                            <td><span class="badge <?= $statusBadge ?>"><?= $statusText ?></span></td>
                            <td class="text-end">
                                <?php if ($exp['file_path']): ?>
                                    <a href="<?= Sanitize::e(Upload::url($exp['file_path'], 'expiration', (int)$exp['id'])) ?>" target="_blank" class="btn btn-outline-secondary btn-action" title="Arquivo"><i class="bi bi-download"></i></a>
                                <?php endif; ?>
                                <?php if (core_can('expirations.edit')): ?>
                                    <a href="index.php?m=rh&page=expirations&action=edit&id=<?= $exp['id'] ?>" class="btn btn-outline-warning btn-action" title="Editar"><i class="bi bi-pencil"></i></a>
                                <?php endif; ?>
                                <?php if (core_can('expirations.delete')): ?>
                                    <form method="POST" action="index.php?m=rh&page=expirations&action=delete" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $exp['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Excluir este vencimento?"><i class="bi bi-trash"></i></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total > 0): ?>
        <div class="card-footer bg-white d-flex justify-content-between align-items-center">
            <small class="text-muted"><?= $total ?> vencimento(s)</small>
            <?= $pagination->render('index.php') ?>
        </div>
    <?php endif; ?>
</div>
