<div class="page-header">
    <h1><i class="bi bi-people me-2"></i>Equipes</h1>
    <?php if (core_can('teams.create')): ?>
    <a href="index.php?m=chat&page=teams&action=create" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> Nova Equipe
    </a>
    <?php endif; ?>
</div>

<?php if (empty($teams)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-people display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhuma equipe encontrada.</p>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($teams as $team): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100 team-card">
            <div class="card-body">
                <div class="d-flex align-items-center mb-3">
                    <div class="team-icon" style="background: <?= Sanitize::e($team['color']) ?>">
                        <?= mb_strtoupper(mb_substr($team['name'], 0, 2)) ?>
                    </div>
                    <div class="ms-3">
                        <h5 class="mb-0">
                            <a href="index.php?m=chat&page=teams&action=show&id=<?= $team['id'] ?>" class="text-decoration-none">
                                <?= Sanitize::e($team['name']) ?>
                            </a>
                        </h5>
                        <small class="text-muted"><?= $team['member_count'] ?? 0 ?> membros</small>
                    </div>
                </div>
                <?php if (!empty($team['description'])): ?>
                <p class="text-muted small mb-0"><?= Sanitize::e(mb_substr($team['description'], 0, 120)) ?><?= mb_strlen($team['description']) > 120 ? '...' : '' ?></p>
                <?php endif; ?>
            </div>
            <div class="card-footer bg-transparent border-0 pt-0">
                <a href="index.php?m=chat&page=teams&action=show&id=<?= $team['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                    Ver Equipe
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
