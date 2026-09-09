<?php
/** Portal — leitura de comunicado. Variáveis: $employee, $item (+ as do _top). */
$portalTitle = $item['title'];
$compact = true;
$annBadge = match ($item['type']) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info text-dark' };
require __DIR__ . '/_top.php';
?>
    <div class="mb-3"><a href="index.php?m=rh&page=my#comunicados" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i> Voltar aos comunicados</a></div>
    <div class="row justify-content-center"><div class="col-lg-9">
        <article class="card border-0 shadow-sm">
            <?php if (!empty($item['image_path'])): ?>
                <img src="<?= Sanitize::e(Upload::publicUrl($item['image_path'])) ?>" class="card-img-top" style="max-height:320px;object-fit:cover" alt="">
            <?php endif; ?>
            <div class="card-body p-4">
                <div class="mb-2">
                    <?php if ((int)$item['pinned']): ?><span class="badge bg-dark"><i class="bi bi-pin"></i> Fixo</span><?php endif; ?>
                    <span class="badge <?= $annBadge ?>"><?= Sanitize::e(Announcement::TYPES[$item['type']] ?? ucfirst($item['type'])) ?></span>
                </div>
                <h2 class="fw-bold h3"><?= Sanitize::e($item['title']) ?></h2>
                <?php if (!empty($item['summary'])): ?><p class="lead fs-6 text-muted"><?= Sanitize::e($item['summary']) ?></p><?php endif; ?>
                <small class="text-muted d-block mb-3">
                    Publicado em <?= Sanitize::formatDateTime($item['published_at'] ?? $item['created_at']) ?>
                    <?= $item['expires_at'] ? ' · válido até ' . Sanitize::formatDate($item['expires_at']) : '' ?>
                </small>
                <hr>
                <div class="doc-body">
                    <?= !empty($item['body_html']) ? $item['body_html'] : nl2br(Sanitize::e((string)$item['body'])) ?>
                </div>
                <?php if (!empty($item['attachment_path'])): ?>
                    <hr>
                    <a href="<?= Sanitize::e(Upload::url($item['attachment_path'], 'announcement', (int)$item['id'])) ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                        <i class="bi bi-paperclip me-1"></i> <?= Sanitize::e($item['attachment_name'] ?: 'Anexo') ?>
                    </a>
                <?php endif; ?>
            </div>
        </article>
    </div></div>
<?php require __DIR__ . '/_bottom.php'; ?>
