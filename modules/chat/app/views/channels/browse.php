<div class="page-header">
    <h1><i class="bi bi-hash me-2"></i>Explorar Canais</h1>
    <a href="index.php?m=chat&page=chat" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar ao Chat
    </a>
</div>

<?php if (empty($channels)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-hash display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhum canal público disponível.</p>
            <a href="index.php?m=chat&page=channels&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Criar Canal
            </a>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($channels as $ch): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1">
                    <i class="bi bi-hash me-1 text-muted"></i><?= Sanitize::e($ch['name']) ?>
                </h5>
                <?php if ($ch['description']): ?>
                <p class="text-muted small mb-2"><?= Sanitize::e(mb_substr($ch['description'], 0, 100)) ?></p>
                <?php endif; ?>
                <div class="small text-muted mb-3">
                    <i class="bi bi-people me-1"></i><?= $ch['member_count'] ?? 0 ?> membros
                </div>
                <?php if (!empty($ch['is_member'])): ?>
                    <a href="index.php?m=chat&page=chat&channel_id=<?= $ch['id'] ?>" class="btn btn-outline-primary btn-sm w-100">
                        <i class="bi bi-chat me-1"></i> Abrir
                    </a>
                <?php else: ?>
                    <form method="POST" action="index.php?m=chat&page=channels&action=join">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="bi bi-box-arrow-in-right me-1"></i> Entrar
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
