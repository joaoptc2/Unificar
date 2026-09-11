<?php
/** Lista de comunicados (RH). Variáveis: $items (com is_read/read_count), $manage. */
$annBadge = fn (string $t): string => match ($t) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info text-dark' };
?>
<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i>Comunicados</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('announcements.create')): ?>
            <a href="index.php?m=rh&page=announcements&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo comunicado</a>
        <?php endif; ?>
    </div>
</div>

<?php if (empty($items)): ?>
    <div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum comunicado publicado.</div></div>
<?php endif; ?>

<?php foreach ($items as $a): $isRead = (int)$a['is_read']; $draft = empty($a['published_at']); $expired = !empty($a['expires_at']) && $a['expires_at'] < date('Y-m-d'); ?>
<div class="card border-0 shadow-sm mb-3 <?= !$isRead && !$draft ? 'border-start border-primary border-3' : '' ?> <?= $draft ? 'opacity-75' : '' ?>">
    <div class="card-body">
        <div class="row g-3">
            <?php if (!empty($a['image_path'])): ?>
                <div class="col-md-3">
                    <img src="<?= Sanitize::e(Upload::publicUrl($a['image_path'])) ?>" class="img-fluid rounded" style="max-height:140px;object-fit:cover;width:100%" alt="">
                </div>
            <?php endif; ?>
            <div class="col">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                    <div>
                        <?php if ($draft): ?><span class="badge bg-secondary me-1"><i class="bi bi-pencil"></i> Rascunho</span><?php endif; ?>
                        <?php if ($expired): ?><span class="badge bg-dark me-1">Expirado</span><?php endif; ?>
                        <?php if ((int)$a['pinned']): ?><span class="badge bg-dark me-1"><i class="bi bi-pin"></i> Fixo</span><?php endif; ?>
                        <span class="badge <?= $annBadge($a['type']) ?>"><?= Sanitize::e(Announcement::TYPES[$a['type']] ?? ucfirst($a['type'])) ?></span>
                        <?php if (!$isRead && !$draft): ?><span class="badge bg-primary ms-1">Não lido</span><?php endif; ?>
                        <h5 class="fw-bold mt-2 mb-1"><?= Sanitize::e($a['title']) ?></h5>
                        <small class="text-muted">
                            <?= Sanitize::e($a['author_name'] ?? '') ?>
                            &middot; <?= $draft ? 'criado em ' . Sanitize::formatDateTime($a['created_at']) : Sanitize::formatDateTime($a['published_at']) ?>
                            &middot; <i class="bi bi-people"></i> <?= $a['department_name'] ? Sanitize::e($a['department_name']) : 'Todos' ?>
                            <?= $a['expires_at'] ? ' &middot; válido até ' . Sanitize::formatDate($a['expires_at']) : '' ?>
                        </small>
                    </div>
                    <?php if ($manage): ?>
                        <div class="text-end small text-muted">
                            <div><i class="bi bi-eye"></i> <?= (int)$a['read_count'] ?> leitura(s)</div>
                            <div>
                                <?= (int)$a['show_in_portal'] ? '<span class="badge bg-light text-dark border">Portal</span>' : '' ?>
                                <?php if ((int)$a['send_email']): ?>
                                    <span class="badge <?= $a['emailed_at'] ? 'bg-success' : 'bg-light text-dark border' ?>" title="<?= $a['emailed_at'] ? 'E-mails enfileirados em ' . Sanitize::formatDateTime($a['emailed_at']) : 'E-mail será enviado ao publicar' ?>"><i class="bi bi-envelope"></i> E-mail<?= $a['emailed_at'] ? ' ✓' : '' ?></span>
                                <?php endif; ?>
                                <?php if (!empty($a['attachment_name'])): ?><span class="badge bg-light text-dark border"><i class="bi bi-paperclip"></i></span><?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <p class="mt-2 mb-0"><?= Sanitize::e($a['summary'] ?: mb_substr((string)$a['body'], 0, 300) . (mb_strlen((string)$a['body']) > 300 ? '…' : '')) ?></p>
                <div class="mt-2 d-flex flex-wrap gap-1">
                    <a href="index.php?m=rh&page=announcements&action=read&id=<?= (int)$a['id'] ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-book me-1"></i>Ler completo</a>
                    <?php if (core_can('announcements.edit')): ?>
                        <a href="index.php?m=rh&page=announcements&action=edit&id=<?= (int)$a['id'] ?>" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil"></i></a>
                    <?php endif; ?>
                    <?php if ($draft && core_can('announcements.create')): ?>
                        <form method="POST" action="index.php?m=rh&page=announcements&action=publish" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button type="submit" class="btn btn-outline-success btn-sm"><i class="bi bi-send me-1"></i>Publicar</button></form>
                    <?php endif; ?>
                    <?php if (core_can('announcements.delete')): ?>
                        <form method="POST" action="index.php?m=rh&page=announcements&action=delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Excluir este comunicado?"><i class="bi bi-trash"></i></button></form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>
