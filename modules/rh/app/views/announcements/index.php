<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i>Comunicados</h1>
    <div class="d-flex gap-2">
        <?php if (Auth::can('announcements', 'create')): ?>
            <a href="index.php?page=announcements&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo</a>
        <?php endif; ?>
    </div>
</div>
<?php foreach ($items as $a): $isRead = Announcement::isRead((int)$a['id'], (int)Session::userId()); ?>
<div class="card border-0 shadow-sm mb-3 <?= !$isRead ? 'border-start border-primary border-3' : '' ?>">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-start">
            <div>
                <?php if ((int)$a['pinned']): ?><span class="badge bg-dark me-1"><i class="bi bi-pin"></i> Fixo</span><?php endif; ?>
                <span class="badge <?= match($a['type']) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info' } ?>"><?= ucfirst($a['type']) ?></span>
                <h5 class="fw-bold mt-2 mb-1"><?= Sanitize::e($a['title']) ?></h5>
                <small class="text-muted"><?= Sanitize::e($a['author_name'] ?? '') ?> &middot; <?= Sanitize::formatDateTime($a['published_at']) ?></small>
            </div>
        </div>
        <p class="mt-2 mb-0"><?= nl2br(Sanitize::e(mb_substr($a['body'], 0, 300))) ?><?= mb_strlen($a['body']) > 300 ? '...' : '' ?></p>
        <div class="mt-2">
            <a href="index.php?page=announcements&action=read&id=<?= $a['id'] ?>" class="btn btn-outline-primary btn-sm">Ler completo</a>
            <?php if (Auth::can('announcements', 'delete')): ?>
                <form method="POST" action="index.php?page=announcements&action=delete" class="d-inline"><?= Csrf::field() ?><input type="hidden" name="id" value="<?= $a['id'] ?>"><button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Excluir?"><i class="bi bi-trash"></i></button></form>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
<?php if (empty($items)): ?>
<div class="card border-0 shadow-sm"><div class="card-body text-center text-muted py-4">Nenhum comunicado publicado.</div></div>
<?php endif; ?>
