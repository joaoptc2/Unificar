<div class="page-header">
    <h1><i class="bi bi-calendar-event me-2"></i><?= Sanitize::e($meeting['title']) ?></h1>
    <div class="d-flex gap-2">
        <?php if ((int)$meeting['created_by'] === Session::userId() || Auth::isAdmin()): ?>
        <a href="index.php?page=meetings&action=edit&id=<?= $meeting['id'] ?>" class="btn btn-outline-warning btn-sm">
            <i class="bi bi-pencil me-1"></i> Editar
        </a>
        <?php endif; ?>
        <a href="index.php?page=meetings" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="row g-4">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <?php if ($meeting['description']): ?>
                <div class="mb-4"><?= nl2br(Sanitize::e($meeting['description'])) ?></div>
                <?php endif; ?>

                <?php if ($meeting['meeting_link']): ?>
                <a href="<?= Sanitize::e($meeting['meeting_link']) ?>" target="_blank" class="btn btn-primary mb-4">
                    <i class="bi bi-camera-video me-2"></i>Entrar na reunião
                </a>
                <?php endif; ?>

                <h5><i class="bi bi-people me-2"></i>Participantes (<?= count($meeting['participants'] ?? []) ?>)</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover">
                        <thead><tr><th>Nome</th><th>Status</th><th>Respondido em</th></tr></thead>
                        <tbody>
                        <?php foreach ($meeting['participants'] ?? [] as $p): ?>
                        <tr>
                            <td><?= Sanitize::e($p['name']) ?></td>
                            <td>
                                <span class="badge bg-<?= match($p['status']) {
                                    'accepted' => 'success', 'declined' => 'danger', 'tentative' => 'warning', default => 'secondary'
                                } ?>">
                                    <?= match($p['status']) { 'accepted' => 'Confirmado', 'declined' => 'Recusado', 'tentative' => 'Talvez', default => 'Pendente' } ?>
                                </span>
                            </td>
                            <td><?= $p['responded_at'] ? Sanitize::formatDateTime($p['responded_at']) : '-' ?></td>
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
            <div class="card-body">
                <div class="detail-item">
                    <label>Data e hora</label>
                    <span class="fw-semibold"><?= Sanitize::formatDateTime($meeting['scheduled_at']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Duração</label>
                    <span><?= (int)$meeting['duration_minutes'] ?> minutos</span>
                </div>
                <div class="detail-item">
                    <label>Tipo</label>
                    <span><?= match($meeting['type']) { 'video' => 'Videoconferência', 'presential' => 'Presencial', 'hybrid' => 'Híbrido', default => $meeting['type'] } ?></span>
                </div>
                <?php if ($meeting['location']): ?>
                <div class="detail-item">
                    <label>Local</label>
                    <span><?= Sanitize::e($meeting['location']) ?></span>
                </div>
                <?php endif; ?>
                <div class="detail-item">
                    <label>Status</label>
                    <span class="badge bg-<?= match($meeting['status']) {
                        'scheduled' => 'primary', 'in_progress' => 'warning', 'completed' => 'success', 'cancelled' => 'danger', default => 'secondary'
                    } ?>"><?= match($meeting['status']) {
                        'scheduled' => 'Agendada', 'in_progress' => 'Em andamento', 'completed' => 'Concluída', 'cancelled' => 'Cancelada', default => $meeting['status']
                    } ?></span>
                </div>
                <div class="detail-item">
                    <label>Organizador</label>
                    <span><?= Sanitize::e($meeting['creator_name'] ?? 'Desconhecido') ?></span>
                </div>
            </div>
        </div>

        <?php if ($meeting['status'] === 'scheduled'): ?>
        <div class="card border-0 shadow-sm mt-3">
            <div class="card-body">
                <h6>Sua resposta</h6>
                <div class="d-grid gap-2">
                    <form method="POST" action="index.php?page=meetings&action=respond">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                        <div class="d-grid gap-2">
                            <button type="submit" name="status" value="accepted" class="btn btn-success btn-sm">
                                <i class="bi bi-check-lg me-1"></i>Aceitar
                            </button>
                            <button type="submit" name="status" value="tentative" class="btn btn-warning btn-sm">
                                <i class="bi bi-question-lg me-1"></i>Talvez
                            </button>
                            <button type="submit" name="status" value="declined" class="btn btn-outline-danger btn-sm">
                                <i class="bi bi-x-lg me-1"></i>Recusar
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
