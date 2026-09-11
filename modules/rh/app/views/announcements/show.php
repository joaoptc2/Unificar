<?php
/** Leitura de comunicado (layout RH). Variáveis: $item. */
$annBadge = match ($item['type']) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info text-dark' };
?>
<style>
    .doc-body img { max-width: 100%; height: auto; }
    .doc-body table { border-collapse: collapse; width: 100%; }
    .doc-body td, .doc-body th { border: 1px solid #ccc; padding: 4px 6px; }
    .ql-align-center { text-align: center; } .ql-align-right { text-align: right; } .ql-align-justify { text-align: justify; }
</style>
<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i>Comunicado</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('announcements.edit')): ?>
            <a href="index.php?m=rh&page=announcements&action=edit&id=<?= (int)$item['id'] ?>" class="btn btn-warning btn-sm"><i class="bi bi-pencil me-1"></i> Editar</a>
        <?php endif; ?>
        <a href="index.php?m=rh&page=announcements" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
    </div>
</div>
<div class="row justify-content-center"><div class="col-lg-9">
    <article class="card border-0 shadow-sm">
        <?php if (!empty($item['image_path'])): ?>
            <img src="<?= Sanitize::e(Upload::publicUrl($item['image_path'])) ?>" class="card-img-top" style="max-height:320px;object-fit:cover" alt="">
        <?php endif; ?>
        <div class="card-body p-4">
            <div class="mb-2">
                <?php if (empty($item['published_at'])): ?><span class="badge bg-secondary">Rascunho</span><?php endif; ?>
                <?php if ((int)$item['pinned']): ?><span class="badge bg-dark"><i class="bi bi-pin"></i> Fixo</span><?php endif; ?>
                <span class="badge <?= $annBadge ?>"><?= Sanitize::e(Announcement::TYPES[$item['type']] ?? ucfirst($item['type'])) ?></span>
            </div>
            <h2 class="fw-bold h3"><?= Sanitize::e($item['title']) ?></h2>
            <?php if (!empty($item['summary'])): ?><p class="lead fs-6 text-muted"><?= Sanitize::e($item['summary']) ?></p><?php endif; ?>
            <small class="text-muted d-block mb-3">
                <?= !empty($item['published_at']) ? 'Publicado em ' . Sanitize::formatDateTime($item['published_at']) : 'Criado em ' . Sanitize::formatDateTime($item['created_at']) ?>
                <?= $item['expires_at'] ? ' · válido até ' . Sanitize::formatDate($item['expires_at']) : '' ?>
            </small>
            <hr>
            <div class="doc-body"><?= !empty($item['body_html']) ? $item['body_html'] : nl2br(Sanitize::e((string)$item['body'])) ?></div>
            <?php if (!empty($item['attachment_path'])): ?>
                <hr>
                <a href="<?= Sanitize::e(Upload::url($item['attachment_path'], 'announcement', (int)$item['id'])) ?>" class="btn btn-outline-primary btn-sm" target="_blank">
                    <i class="bi bi-paperclip me-1"></i> <?= Sanitize::e($item['attachment_name'] ?: 'Anexo') ?>
                </a>
            <?php endif; ?>
        </div>
    </article>
</div></div>
