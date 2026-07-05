<div class="page-header">
    <h1><i class="bi bi-calendar-event me-2"></i>Reuniões</h1>
    <div class="d-flex gap-2">
        <a href="index.php?m=chat&page=meetings&action=calendar" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-calendar3 me-1"></i> Calendário
        </a>
        <a href="index.php?m=chat&page=meetings&action=create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> Agendar Reunião
        </a>
    </div>
</div>

<?php if (empty($meetings)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-calendar-x display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhuma reunião agendada.</p>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($meetings as $meeting): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm meeting-card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <span class="badge bg-<?= match($meeting['type']) {
                        'video' => 'info', 'presential' => 'success', 'hybrid' => 'warning', default => 'secondary'
                    } ?>-subtle text-<?= match($meeting['type']) {
                        'video' => 'info', 'presential' => 'success', 'hybrid' => 'warning', default => 'secondary'
                    } ?>">
                        <i class="bi bi-<?= match($meeting['type']) {
                            'video' => 'camera-video', 'presential' => 'geo-alt', 'hybrid' => 'arrows-angle-expand', default => 'calendar'
                        } ?> me-1"></i>
                        <?= match($meeting['type']) { 'video' => 'Videoconferência', 'presential' => 'Presencial', 'hybrid' => 'Híbrido', default => $meeting['type'] } ?>
                    </span>
                    <?php if (($meeting['my_status'] ?? 'pending') !== 'pending'): ?>
                    <span class="badge bg-<?= match($meeting['my_status']) {
                        'accepted' => 'success', 'declined' => 'danger', 'tentative' => 'warning', default => 'secondary'
                    } ?>-subtle text-<?= match($meeting['my_status']) {
                        'accepted' => 'success', 'declined' => 'danger', 'tentative' => 'warning', default => 'secondary'
                    } ?>">
                        <?= match($meeting['my_status']) { 'accepted' => 'Confirmado', 'declined' => 'Recusado', 'tentative' => 'Talvez', default => 'Pendente' } ?>
                    </span>
                    <?php endif; ?>
                </div>

                <h5 class="mb-2">
                    <a href="index.php?m=chat&page=meetings&action=show&id=<?= $meeting['id'] ?>" class="text-decoration-none">
                        <?= Sanitize::e($meeting['title']) ?>
                    </a>
                </h5>

                <div class="meeting-meta">
                    <div><i class="bi bi-calendar3 me-2"></i><?= Sanitize::formatDateTime($meeting['scheduled_at']) ?></div>
                    <div><i class="bi bi-clock me-2"></i><?= (int)$meeting['duration_minutes'] ?> min</div>
                    <?php if ($meeting['location']): ?>
                    <div><i class="bi bi-geo-alt me-2"></i><?= Sanitize::e($meeting['location']) ?></div>
                    <?php endif; ?>
                    <div><i class="bi bi-person me-2"></i><?= Sanitize::e($meeting['creator_name'] ?? '') ?></div>
                </div>

                <?php if (($meeting['my_status'] ?? 'pending') === 'pending'): ?>
                <div class="d-flex gap-2 mt-3">
                    <form method="POST" action="index.php?m=chat&page=meetings&action=respond" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                        <input type="hidden" name="status" value="accepted">
                        <button class="btn btn-success btn-sm"><i class="bi bi-check-lg me-1"></i>Aceitar</button>
                    </form>
                    <form method="POST" action="index.php?m=chat&page=meetings&action=respond" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                        <input type="hidden" name="status" value="tentative">
                        <button class="btn btn-warning btn-sm">Talvez</button>
                    </form>
                    <form method="POST" action="index.php?m=chat&page=meetings&action=respond" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="meeting_id" value="<?= $meeting['id'] ?>">
                        <input type="hidden" name="status" value="declined">
                        <button class="btn btn-outline-danger btn-sm">Recusar</button>
                    </form>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
