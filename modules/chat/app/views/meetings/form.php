<?php $isEdit = !empty($meeting['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-calendar-event me-2"></i><?= $isEdit ? 'Editar Reunião' : 'Agendar Reunião' ?></h1>
    <a href="index.php?page=meetings" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=meetings&action=<?= $isEdit ? 'update' : 'store' ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $meeting['id'] ?>">
                    <?php endif; ?>
                    <?php if (!empty($channelId)): ?>
                        <input type="hidden" name="channel_id" value="<?= (int)$channelId ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" required
                                   value="<?= Sanitize::e($meeting['title'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="3"><?= Sanitize::e($meeting['description'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label required">Data e hora</label>
                            <input type="datetime-local" name="scheduled_at" class="form-control" required
                                   value="<?= !empty($meeting['scheduled_at']) ? date('Y-m-d\TH:i', strtotime($meeting['scheduled_at'])) : '' ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Duração (minutos)</label>
                            <input type="number" name="duration_minutes" class="form-control" min="15" step="15"
                                   value="<?= (int)($meeting['duration_minutes'] ?? 60) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-select">
                                <option value="video" <?= ($meeting['type'] ?? '') === 'video' ? 'selected' : '' ?>>Videoconferência</option>
                                <option value="presential" <?= ($meeting['type'] ?? '') === 'presential' ? 'selected' : '' ?>>Presencial</option>
                                <option value="hybrid" <?= ($meeting['type'] ?? '') === 'hybrid' ? 'selected' : '' ?>>Híbrido</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Local</label>
                            <input type="text" name="location" class="form-control" placeholder="Sala, endereço..."
                                   value="<?= Sanitize::e($meeting['location'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Link da reunião</label>
                            <input type="url" name="meeting_link" class="form-control" placeholder="https://..."
                                   value="<?= Sanitize::e($meeting['meeting_link'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Participantes</label>
                            <select name="participants[]" class="form-select" multiple size="6">
                                <?php foreach ($users as $u): ?>
                                <option value="<?= $u['id'] ?>"
                                    <?= in_array($u['id'], $participantIds ?? []) ? 'selected' : '' ?>>
                                    <?= Sanitize::e($u['name']) ?> (<?= Sanitize::e($u['email']) ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar múltiplos</div>
                        </div>

                        <?php if (!$isEdit && empty($channelId)): ?>
                        <div class="col-12">
                            <label class="form-label">Vincular a canal (opcional)</label>
                            <select name="channel_id" class="form-select">
                                <option value="">Nenhum</option>
                                <?php foreach ($channels ?? [] as $ch): ?>
                                <option value="<?= $ch['id'] ?>"><?= Sanitize::e($ch['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 text-end">
                            <a href="index.php?page=meetings" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Agendar' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
