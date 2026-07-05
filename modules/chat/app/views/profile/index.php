<div class="page-header">
    <h1><i class="bi bi-person me-2"></i>Meu Perfil</h1>
    <a href="index.php?page=profile&action=edit" class="btn btn-primary btn-sm">
        <i class="bi bi-pencil me-1"></i> Editar
    </a>
</div>

<div class="row g-4">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm text-center">
            <div class="card-body py-4">
                <div class="profile-avatar-lg mx-auto mb-3">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="<?= BASE_URL ?>/public/<?= Sanitize::e($user['avatar']) ?>" alt="" class="avatar-img-lg">
                    <?php else: ?>
                        <span class="avatar-initials-lg"><?= Sanitize::e(User::initials($user['name'])) ?></span>
                    <?php endif; ?>
                    <span class="status-badge-lg <?= Sanitize::e($user['status']) ?>"></span>
                </div>
                <h4><?= Sanitize::e($user['name']) ?></h4>
                <?php if ($user['title']): ?>
                <p class="text-muted"><?= Sanitize::e($user['title']) ?></p>
                <?php endif; ?>
                <span class="badge bg-<?= match($user['role']) { 'admin' => 'danger', 'manager' => 'warning', default => 'primary' } ?>">
                    <?= match($user['role']) { 'admin' => 'Administrador', 'manager' => 'Gestor', default => 'Membro' } ?>
                </span>
            </div>
        </div>
    </div>

    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-2"></i>Informações
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="detail-item">
                            <label>E-mail</label>
                            <span><?= Sanitize::e($user['email']) ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <label>Departamento</label>
                            <span><?= Sanitize::e($user['department'] ?: '-') ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <label>Telefone</label>
                            <span><?= Sanitize::e($user['phone'] ?: '-') ?></span>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="detail-item">
                            <label>Fuso horário</label>
                            <span><?= Sanitize::e($user['timezone']) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow-sm mt-3">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-people me-2"></i>Equipes (<?= count($teams) ?>)
            </div>
            <div class="card-body p-0">
                <?php if (empty($teams)): ?>
                    <p class="text-muted text-center py-3">Nenhuma equipe.</p>
                <?php else: ?>
                <div class="list-group list-group-flush">
                    <?php foreach ($teams as $team): ?>
                    <a href="index.php?page=teams&action=show&id=<?= $team['id'] ?>" class="list-group-item list-group-item-action d-flex align-items-center">
                        <span class="team-dot" style="background: <?= Sanitize::e($team['color']) ?>"></span>
                        <span class="ms-2"><?= Sanitize::e($team['name']) ?></span>
                        <span class="badge bg-secondary ms-auto"><?= $team['member_role'] === 'leader' ? 'Líder' : 'Membro' ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
