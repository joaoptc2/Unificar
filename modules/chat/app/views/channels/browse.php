<div class="page-header">
    <h1 class="h4"><i class="bi bi-hash me-2"></i>Explorar canais</h1>
    <div class="d-flex gap-2">
        <?php if (core_can('channels.create')): ?>
        <a href="index.php?m=chat&page=channels&action=create" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> Novo canal</a>
        <?php endif; ?>
        <a href="index.php?m=chat&page=chat" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar ao chat</a>
    </div>
</div>
<p class="text-muted">Canais públicos da organização. Entre em um canal para acompanhar e participar da conversa.</p>

<?php if (empty($channels)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-hash display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhum canal público disponível.</p>
            <?php if (core_can('channels.create')): ?>
            <a href="index.php?m=chat&page=channels&action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i> Criar canal
            </a>
            <?php endif; ?>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($channels as $ch): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h5 class="mb-1 d-flex align-items-center gap-1">
                    <i class="bi bi-hash text-muted"></i><?= Sanitize::e($ch['name']) ?>
                    <?php if (!empty($ch['is_general'])): ?><span class="badge text-bg-primary ms-1">geral</span><?php endif; ?>
                </h5>
                <?php if (!empty($ch['category_name'])): ?>
                <div class="small text-muted mb-1"><i class="bi bi-collection me-1"></i><?= Sanitize::e($ch['category_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($ch['description'])): ?>
                <p class="text-muted small mb-2"><?= Sanitize::e(mb_substr($ch['description'], 0, 100)) ?></p>
                <?php endif; ?>
                <div class="small text-muted mb-3">
                    <i class="bi bi-people me-1"></i><?= (int) ($ch['member_count'] ?? 0) ?> membro<?= (int) ($ch['member_count'] ?? 0) === 1 ? '' : 's' ?>
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
