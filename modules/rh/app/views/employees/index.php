<!-- Cabeçalho da página -->
<div class="page-header">
    <h1><i class="bi bi-people me-2"></i>Funcionários</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('employees.export')): ?>
            <a href="index.php?m=rh&page=employees&action=export&<?= http_build_query(array_filter(['status' => $status, 'department' => $department])) ?>"
               class="btn btn-outline-success btn-sm">
                <i class="bi bi-file-earmark-excel me-1"></i> Exportar CSV
            </a>
        <?php endif; ?>
        <?php if (core_can('employees.create')): ?>
            <a href="index.php?m=rh&page=employees&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Novo Funcionário
            </a>
        <?php endif; ?>
    </div>
</div>

<!-- Filtros -->
<div class="filter-panel">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="page" value="employees">
        <div class="col-md-3">
            <label class="form-label">Buscar</label>
            <input type="text" name="search" class="form-control form-control-sm"
                   placeholder="Nome, CPF ou e-mail..." value="<?= Sanitize::e($search) ?>">
        </div>
        <div class="col-md-2">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="ativo" <?= $status === 'ativo' ? 'selected' : '' ?>>Ativo</option>
                <option value="afastado" <?= $status === 'afastado' ? 'selected' : '' ?>>Afastado</option>
                <option value="desligado" <?= $status === 'desligado' ? 'selected' : '' ?>>Desligado</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Departamento</label>
            <select name="department" class="form-select form-select-sm">
                <option value="">Todos</option>
                <?php foreach ($departments as $d): ?>
                    <option value="<?= $d['id'] ?>" <?= $department == $d['id'] ? 'selected' : '' ?>>
                        <?= Sanitize::e($d['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Contrato</label>
            <select name="contract" class="form-select form-select-sm">
                <option value="">Todos</option>
                <option value="CLT" <?= $contract === 'CLT' ? 'selected' : '' ?>>CLT</option>
                <option value="PJ" <?= $contract === 'PJ' ? 'selected' : '' ?>>PJ</option>
                <option value="Temporario" <?= $contract === 'Temporario' ? 'selected' : '' ?>>Temporário</option>
                <option value="Estagio" <?= $contract === 'Estagio' ? 'selected' : '' ?>>Estágio</option>
                <option value="Terceirizado" <?= $contract === 'Terceirizado' ? 'selected' : '' ?>>Terceirizado</option>
            </select>
        </div>
        <div class="col-md-3 d-flex gap-1">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-search me-1"></i>Filtrar</button>
            <a href="index.php?m=rh&page=employees" class="btn btn-outline-secondary btn-sm">Limpar</a>
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
                        <th>CPF</th>
                        <th>Departamento</th>
                        <th>Cargo</th>
                        <th>Admissão</th>
                        <th>Status</th>
                        <th class="text-end">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($employees)): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Nenhum funcionário encontrado.</td></tr>
                    <?php else: foreach ($employees as $emp): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <?php if ($emp['photo']): ?>
                                        <img src="<?= Sanitize::e(Upload::publicUrl($emp['photo'])) ?>" class="employee-photo me-2" alt="">
                                    <?php else: ?>
                                        <div class="employee-photo me-2 bg-light d-flex align-items-center justify-content-center">
                                            <i class="bi bi-person text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    <div>
                                        <a href="index.php?m=rh&page=employees&action=show&id=<?= $emp['id'] ?>" class="fw-semibold text-decoration-none">
                                            <?= Sanitize::e($emp['full_name']) ?>
                                        </a>
                                        <?php if ($emp['email']): ?>
                                            <div class="text-muted small"><?= Sanitize::e($emp['email']) ?></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td><?= Sanitize::formatCpf($emp['cpf']) ?></td>
                            <td><?= Sanitize::e($emp['department_name'] ?? '-') ?></td>
                            <td><?= Sanitize::e($emp['position_title'] ?? '-') ?></td>
                            <td><?= Sanitize::formatDate($emp['admission_date']) ?></td>
                            <td>
                                <?php
                                $badgeClass = match($emp['status']) {
                                    'ativo' => 'badge-ativo',
                                    'afastado' => 'badge-afastado',
                                    'desligado' => 'badge-desligado',
                                    default => 'bg-secondary'
                                };
                                ?>
                                <span class="badge <?= $badgeClass ?>"><?= ucfirst($emp['status']) ?></span>
                            </td>
                            <td class="text-end">
                                <a href="index.php?m=rh&page=employees&action=show&id=<?= $emp['id'] ?>"
                                   class="btn btn-outline-primary btn-action" title="Ver">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <?php if (core_can('employees.edit')): ?>
                                    <a href="index.php?m=rh&page=employees&action=edit&id=<?= $emp['id'] ?>"
                                       class="btn btn-outline-warning btn-action" title="Editar">
                                        <i class="bi bi-pencil"></i>
                                    </a>
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
            <small class="text-muted"><?= $total ?> funcionário(s) encontrado(s)</small>
            <?= $pagination->render('index.php') ?>
        </div>
    <?php endif; ?>
</div>
