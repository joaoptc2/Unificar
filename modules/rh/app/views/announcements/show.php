<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i>Comunicado</h1>
    <a href="index.php?m=rh&page=announcements" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<div class="row justify-content-center"><div class="col-md-8">
<div class="card border-0 shadow-sm"><div class="card-body">
    <span class="badge <?= match($item['type']) { 'urgente' => 'bg-danger', 'celebracao' => 'bg-success', default => 'bg-info' } ?> mb-2"><?= ucfirst($item['type']) ?></span>
    <h3 class="fw-bold"><?= Sanitize::e($item['title']) ?></h3>
    <small class="text-muted"><?= Sanitize::formatDateTime($item['published_at'] ?? $item['created_at']) ?></small>
    <hr>
    <div class="mb-0"><?= nl2br(Sanitize::e($item['body'])) ?></div>
</div></div></div></div>
