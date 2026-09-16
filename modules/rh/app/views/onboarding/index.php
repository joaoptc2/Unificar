<div class="page-header">
    <h1><i class="bi bi-list-check me-2"></i>Onboarding / Offboarding</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('onboarding.manage_templates')): ?>
            <a href="index.php?m=rh&page=onboarding&action=create_template" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo Template</a>
            <a href="index.php?m=rh&page=onboarding&action=assign" class="btn btn-outline-primary btn-sm"><i class="bi bi-person-plus me-1"></i> Atribuir Checklist</a>
        <?php endif; ?>
    </div>
</div>

<?php
// Em andamento primeiro: é o que o RH abre a tela para ver. Os templates
// são configuração, consultada bem menos vezes.
$abertos = array_filter($andamento, fn($a) => (int) $a['feitos'] < (int) $a['total']);
?>
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-person-check me-2"></i>Checklists em andamento</span>
        <?php if ($andamento): ?>
            <span class="badge text-bg-secondary"><?= count($abertos) ?> em aberto de <?= count($andamento) ?></span>
        <?php endif; ?>
    </div>
    <?php if (empty($andamento)): ?>
        <div class="card-body text-center text-muted py-4">
            Nenhum checklist atribuído ainda.
            <?php if (core_can('onboarding.manage_templates')): ?>
                <a href="index.php?m=rh&page=onboarding&action=assign">Atribuir a um funcionário</a>.
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr>
                <th>Funcionário</th><th style="width:130px">Tipo</th>
                <th style="width:220px">Progresso</th><th style="width:150px">Última baixa</th><th style="width:110px"></th>
            </tr></thead>
            <tbody>
            <?php foreach ($andamento as $a):
                $total = (int) $a['total']; $feitos = (int) $a['feitos'];
                $pct   = $total > 0 ? (int) round($feitos / $total * 100) : 0;
                $fim   = $feitos >= $total; ?>
                <tr>
                    <td class="fw-semibold">
                        <?= Sanitize::e($a['full_name']) ?>
                        <?php if (($a['status'] ?? 'ativo') !== 'ativo'): ?>
                            <span class="badge text-bg-light border ms-1"><?= Sanitize::e($a['status']) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><span class="badge <?= $a['type'] === 'onboarding' ? 'bg-success' : 'bg-warning text-dark' ?>"><?= ucfirst($a['type']) ?></span></td>
                    <td>
                        <div class="progress" style="height:18px" role="progressbar"
                             aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
                             aria-label="<?= $feitos ?> de <?= $total ?> itens concluídos">
                            <div class="progress-bar <?= $fim ? 'bg-success' : '' ?>" style="width:<?= $pct ?>%"><?= $feitos ?>/<?= $total ?></div>
                        </div>
                    </td>
                    <td class="text-muted small"><?= $a['ultima'] ? Sanitize::formatDateTime($a['ultima']) : '—' ?></td>
                    <td class="text-end">
                        <a href="index.php?m=rh&page=onboarding&action=progress&employee_id=<?= (int) $a['id'] ?>"
                           class="btn btn-sm <?= $fim ? 'btn-outline-secondary' : 'btn-outline-primary' ?>">
                            <i class="bi bi-list-check me-1"></i> Abrir
                        </a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<h2 class="h6 text-muted text-uppercase mb-2">Templates</h2>
<div class="row g-3">
<?php foreach ($templates as $t): ?>
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <span class="badge <?= $t['type'] === 'onboarding' ? 'bg-success' : 'bg-warning text-dark' ?> mb-2"><?= ucfirst($t['type']) ?></span>
                <h6 class="fw-semibold"><?= Sanitize::e($t['name']) ?></h6>
                <small class="text-muted"><?= $t['active'] ? 'Ativo' : 'Inativo' ?></small>
            </div>
            <div class="card-footer bg-transparent">
                <a href="index.php?m=rh&page=onboarding&action=show&id=<?= $t['id'] ?>" class="btn btn-outline-primary btn-sm w-100"><i class="bi bi-eye me-1"></i> Ver itens</a>
            </div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (empty($templates)): ?>
    <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum template criado ainda.</div></div></div>
<?php endif; ?>
</div>
