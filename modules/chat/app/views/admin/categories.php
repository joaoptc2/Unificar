<div class="page-header">
    <h1><i class="bi bi-collection me-2"></i>Categorias de Canais</h1>
    <a href="index.php?m=chat&page=admin" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row g-4">
    <?php if (core_can('categories.create')): ?>
    <div class="col-md-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold">Nova Categoria</div>
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=admin&action=saveCategory">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" class="form-control" required placeholder="Ex: Projetos">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Ordem</label>
                            <input type="number" name="order_num" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Canais desta categoria</label>
                            <select name="channel_ids[]" class="form-select" multiple size="5">
                                <?php foreach ($channels as $ch): ?>
                                <option value="<?= $ch['id'] ?>"><?= Sanitize::e($ch['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar múltiplos</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-plus-lg me-1"></i> Criar Categoria
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="col-md-7">
        <?php if (empty($categories)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-collection display-4"></i>
                    <p class="mt-2">Nenhuma categoria criada.</p>
                </div>
            </div>
        <?php else: ?>
            <?php foreach ($categories as $cat): ?>
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="mb-0"><?= Sanitize::e($cat['name']) ?></h6>
                        <small class="text-muted">Ordem: <?= (int)$cat['order_num'] ?></small>
                        <?php
                        $catChannels = array_filter($channels, fn($c) => ($c['category_id'] ?? null) == $cat['id']);
                        if ($catChannels):
                        ?>
                        <div class="mt-1">
                            <?php foreach ($catChannels as $cc): ?>
                            <span class="badge bg-primary-subtle text-primary me-1">#<?= Sanitize::e($cc['name']) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (core_can('categories.delete')): ?>
                    <form method="POST" action="index.php?m=chat&page=admin&action=deleteCategory" class="d-inline">
                        <?= Csrf::field() ?>
                        <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Remover esta categoria?">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>
