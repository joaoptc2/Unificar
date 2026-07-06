<div class="page-header">
    <h1><i class="bi bi-person-lines-fill me-2"></i>Banco de Talentos</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('talent_pool.export')): ?>
            <a href="index.php?m=rh&page=talent_pool&action=export" class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Exportar
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="talent_pool">
        <div class="col-md-4">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Nome, e-mail ou área..." value="<?= Sanitize::e($search) ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label">Área</label>
            <select name="area" class="form-select form-select-sm">
                <option value="">Todas</option>
                <?php foreach ($areas as $a): ?>
                    <option value="<?= Sanitize::e($a) ?>" <?= $area === $a ? 'selected' : '' ?>><?= Sanitize::e($a) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            <a href="index.php?m=rh&page=talent_pool" class="btn btn-outline-secondary btn-sm">Limpar</a>
        </div>
    </form>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Nome</th>
                        <th>E-mail</th>
                        <th>Telefone</th>
                        <th>Área</th>
                        <th>Vaga Original</th>
                        <th>Currículo</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($candidates)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhum candidato no banco de talentos.</td></tr>
                    <?php else: foreach ($candidates as $c): ?>
                        <tr>
                            <td class="fw-semibold"><?= Sanitize::e($c['full_name']) ?></td>
                            <td><?= Sanitize::e($c['email']) ?></td>
                            <td><?= Sanitize::e($c['phone'] ?: '-') ?></td>
                            <td><?= Sanitize::e($c['area'] ?: '-') ?></td>
                            <td><small><?= Sanitize::e($c['job_title'] ?? '-') ?></small></td>
                            <td>
                                <?php if ($c['resume_path']): ?>
                                    <a href="<?= Sanitize::e(Upload::url($c['resume_path'], 'resume', (int)$c['id'])) ?>" target="_blank" class="btn btn-outline-primary btn-action"><i class="bi bi-download"></i></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <a href="index.php?m=rh&page=talent_pool&action=show&id=<?= $c['id'] ?>" class="btn btn-outline-primary btn-action" title="Ver"><i class="bi bi-eye"></i></a>
                                <?php if (core_can('talent_pool.delete')): ?>
                                    <form method="POST" action="index.php?m=rh&page=talent_pool&action=remove" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-action" data-confirm="Remover do banco de talentos?"><i class="bi bi-trash"></i></button>
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
            <small class="text-muted"><?= $total ?> candidato(s)</small>
            <?= $pagination->render('index.php') ?>
        </div>
    <?php endif; ?>
</div>
