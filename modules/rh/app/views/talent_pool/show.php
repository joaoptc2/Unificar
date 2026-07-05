<div class="page-header">
    <h1><i class="bi bi-person-lines-fill me-2"></i><?= Sanitize::e($candidate['full_name']) ?></h1>
    <a href="index.php?page=talent_pool" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row g-3">
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Dados do Candidato</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-6"><small class="text-muted">Nome</small><div class="fw-semibold"><?= Sanitize::e($candidate['full_name']) ?></div></div>
                    <div class="col-6"><small class="text-muted">E-mail</small><div><?= Sanitize::e($candidate['email']) ?></div></div>
                    <div class="col-6"><small class="text-muted">Telefone</small><div><?= Sanitize::e($candidate['phone'] ?: '-') ?></div></div>
                    <div class="col-6"><small class="text-muted">CPF</small><div><?= Sanitize::e($candidate['cpf'] ?: '-') ?></div></div>
                    <div class="col-6"><small class="text-muted">Área</small><div><?= Sanitize::e($candidate['area'] ?: '-') ?></div></div>
                    <div class="col-6"><small class="text-muted">Vaga Original</small><div><?= Sanitize::e($candidate['job_title'] ?? '-') ?></div></div>
                    <div class="col-6"><small class="text-muted">Status</small><div><span class="badge bg-secondary"><?= ucfirst(str_replace('_', ' ', $candidate['status'])) ?></span></div></div>
                    <div class="col-6"><small class="text-muted">Data de Inscrição</small><div><?= date('d/m/Y', strtotime($candidate['created_at'])) ?></div></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Experiência</div>
            <div class="card-body">
                <?php if ($candidate['experience']): ?>
                    <p><?= nl2br(Sanitize::e($candidate['experience'])) ?></p>
                <?php else: ?>
                    <p class="text-muted">Nenhuma experiência informada.</p>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($candidate['resume_path']): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-body text-center">
                    <a href="<?= Sanitize::e(Upload::url($candidate['resume_path'], 'resume', (int)$candidate['id'])) ?>" target="_blank" class="btn btn-primary">
                        <i class="bi bi-file-earmark-pdf me-1"></i> Baixar Currículo
                    </a>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($candidate['notes']): ?>
            <div class="card border-0 shadow-sm mt-3">
                <div class="card-header bg-white fw-semibold">Notas</div>
                <div class="card-body">
                    <p class="small"><?= nl2br(Sanitize::e($candidate['notes'])) ?></p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
