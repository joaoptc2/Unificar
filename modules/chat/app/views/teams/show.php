<div class="page-header">
    <h1>
        <span class="team-icon-lg" style="background: <?= Sanitize::e($team['color']) ?>">
            <?= mb_strtoupper(mb_substr($team['name'], 0, 2)) ?>
        </span>
        <?= Sanitize::e($team['name']) ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if (core_can('teams.edit')): ?>
        <a href="index.php?m=chat&page=teams&action=edit&id=<?= $team['id'] ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i> Editar
        </a>
        <?php endif; ?>
        <a href="index.php?m=chat&page=teams" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-people me-2"></i>Membros (<?= count($team['members'] ?? []) ?>)
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr><th>Nome</th><th>Função</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($team['members'] ?? [] as $member): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="dm-status <?= Sanitize::e($member['status']) ?> me-2"></span>
                                        <div>
                                            <div class="fw-semibold"><?= Sanitize::e($member['name']) ?></div>
                                            <small class="text-muted"><?= Sanitize::e($member['email']) ?></small>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $member['role'] === 'leader' ? 'primary' : 'secondary' ?>-subtle text-<?= $member['role'] === 'leader' ? 'primary' : 'secondary' ?>">
                                        <?= $member['role'] === 'leader' ? 'Líder' : 'Membro' ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge bg-<?= $member['status'] === 'online' ? 'success' : ($member['status'] === 'away' ? 'warning' : 'secondary') ?>-subtle text-<?= $member['status'] === 'online' ? 'success' : ($member['status'] === 'away' ? 'warning' : 'secondary') ?>">
                                        <?= match($member['status']) { 'online' => 'Online', 'away' => 'Ausente', 'dnd' => 'Ocupado', default => 'Offline' } ?>
                                    </span>
                                </td>
                                <td class="text-end">
                                    <a href="index.php?m=chat&page=channels&action=direct&user_id=<?= $member['id'] ?>" class="btn btn-outline-primary btn-action" title="Mensagem direta">
                                        <i class="bi bi-chat"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">
                <i class="bi bi-info-circle me-2"></i>Detalhes
            </div>
            <div class="card-body">
                <?php if ($team['description']): ?>
                <div class="detail-item">
                    <label>Descrição</label>
                    <p><?= nl2br(Sanitize::e($team['description'])) ?></p>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <label>Menção</label>
                    <code>@<?= Sanitize::e($team['slug']) ?></code>
                </div>
                <div class="detail-item">
                    <label>Criado em</label>
                    <span><?= Sanitize::formatDateTime($team['created_at']) ?></span>
                </div>
            </div>
        </div>
    </div>
</div>
