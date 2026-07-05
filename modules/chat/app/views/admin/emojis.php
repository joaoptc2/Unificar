<div class="page-header">
    <h1><i class="bi bi-emoji-smile me-2"></i>Emojis Personalizados</h1>
    <div>
        <button class="btn btn-primary btn-sm" data-bs-toggle="collapse" data-bs-target="#addEmojiForm">
            <i class="bi bi-plus-lg me-1"></i> Adicionar Emoji
        </button>
        <a href="index.php?m=chat&page=admin" class="btn btn-outline-secondary btn-sm ms-1">
            <i class="bi bi-arrow-left me-1"></i> Voltar
        </a>
    </div>
</div>

<div class="collapse mb-4" id="addEmojiForm">
    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <h6 class="card-title mb-3">Enviar novo emoji</h6>
            <form method="POST" action="index.php?m=chat&page=admin&action=storeEmoji" enctype="multipart/form-data">
                <?= Csrf::field() ?>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label required">Nome</label>
                        <input type="text" name="name" class="form-control" required
                               placeholder=":nome:" pattern="^:[a-z0-9_]+:$"
                               title="Use o formato :nome: com letras minúsculas, números e underline">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label required">Imagem</label>
                        <input type="file" name="image" class="form-control" required accept="image/*">
                        <div class="form-text">PNG, GIF ou JPG. Recomendado: 128x128px.</div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload me-1"></i> Enviar Emoji
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<?php if (empty($emojis)): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
            <i class="bi bi-emoji-smile display-4 text-muted"></i>
            <p class="mt-2 text-muted">Nenhum emoji personalizado cadastrado.</p>
        </div>
    </div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($emojis as $emoji): ?>
    <div class="col-auto">
        <div class="card border-0 shadow-sm text-center" style="min-width: 120px;">
            <div class="card-body py-3">
                <img src="<?= Sanitize::e($emoji['image_path']) ?>" alt="<?= Sanitize::e($emoji['name']) ?>"
                     width="48" height="48" class="mb-2" style="object-fit: contain;">
                <div class="small fw-semibold mb-2"><?= Sanitize::e($emoji['name']) ?></div>
                <form method="POST" action="index.php?m=chat&page=admin&action=deleteEmoji"
                      onsubmit="return confirm('Excluir este emoji?')">
                    <?= Csrf::field() ?>
                    <input type="hidden" name="id" value="<?= (int)$emoji['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>
