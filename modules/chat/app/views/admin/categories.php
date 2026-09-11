<?php
/**
 * Configuração → Categorias de canais (exibida na Administração central).
 * POSTs vão para ?m=chat&page=admin&action=saveCategory|deleteCategory e
 * voltam para core_admin_url('chat', 'categories').
 */
$canCreate = core_can('categories.create');
$canEdit   = core_can('categories.edit');
$canDelete = core_can('categories.delete');
$byCategory = [];
foreach ($channels as $c) {
    $byCategory[(int) ($c['category_id'] ?? 0)][] = $c;
}
?>
<div class="page-header">
    <h1 class="h4"><i class="bi bi-collection me-2"></i>Categorias de canais</h1>
</div>
<p class="text-muted">As categorias agrupam os canais na lista lateral do chat, na ordem definida aqui. Canais sem categoria aparecem primeiro.</p>

<div class="row g-4">
    <?php if ($canCreate): ?>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-plus-lg me-1"></i>Nova categoria</div>
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=admin&action=saveCategory">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-8">
                            <label class="form-label required">Nome</label>
                            <input type="text" name="name" class="form-control" required maxlength="200" placeholder="Ex.: Projetos">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Ordem</label>
                            <input type="number" name="order_num" class="form-control" value="0" min="0">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Canais desta categoria</label>
                            <select name="channel_ids[]" class="form-select" multiple size="6">
                                <?php foreach ($channels as $ch): ?>
                                <option value="<?= (int) $ch['id'] ?>">#<?= Sanitize::e($ch['name']) ?><?= $ch['type'] === 'private' ? ' (privado)' : '' ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar vários. Um canal pertence a uma única categoria.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Criar categoria</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $canCreate ? 'col-lg-7' : 'col-12' ?>">
        <?php if (empty($categories)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-collection display-4"></i>
                    <p class="mt-2 mb-0">Nenhuma categoria criada.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:70px">Ordem</th>
                                <th>Categoria</th>
                                <th>Canais</th>
                                <th class="text-end" style="width:110px">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($categories as $cat): $catId = (int) $cat['id']; ?>
                            <tr>
                                <td><?= (int) $cat['order_num'] ?></td>
                                <td class="fw-semibold"><?= Sanitize::e($cat['name']) ?></td>
                                <td>
                                    <?php foreach ($byCategory[$catId] ?? [] as $cc): ?>
                                        <span class="badge text-bg-light border me-1">#<?= Sanitize::e($cc['name']) ?></span>
                                    <?php endforeach; ?>
                                    <?php if (empty($byCategory[$catId])): ?><span class="text-muted small">—</span><?php endif; ?>
                                </td>
                                <td class="text-end text-nowrap">
                                    <?php if ($canEdit): ?>
                                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="collapse" data-bs-target="#editCat<?= $catId ?>" title="Editar"><i class="bi bi-pencil"></i></button>
                                    <?php endif; ?>
                                    <?php if ($canDelete): ?>
                                    <form method="POST" action="index.php?m=chat&page=admin&action=deleteCategory" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $catId ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Remover a categoria &quot;<?= Sanitize::e($cat['name']) ?>&quot;? Os canais ficam sem categoria." title="Remover"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php if ($canEdit): ?>
                            <tr class="collapse" id="editCat<?= $catId ?>">
                                <td colspan="4" class="bg-light">
                                    <form method="POST" action="index.php?m=chat&page=admin&action=saveCategory" class="row g-2 align-items-end p-2">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= $catId ?>">
                                        <div class="col-md-4">
                                            <label class="form-label small mb-1">Nome</label>
                                            <input type="text" name="name" class="form-control form-control-sm" required maxlength="200" value="<?= Sanitize::e($cat['name']) ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small mb-1">Ordem</label>
                                            <input type="number" name="order_num" class="form-control form-control-sm" min="0" value="<?= (int) $cat['order_num'] ?>">
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small mb-1">Canais</label>
                                            <select name="channel_ids[]" class="form-select form-select-sm" multiple size="4">
                                                <?php foreach ($channels as $ch): ?>
                                                <option value="<?= (int) $ch['id'] ?>" <?= (int) ($ch['category_id'] ?? 0) === $catId ? 'selected' : '' ?>>#<?= Sanitize::e($ch['name']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-2 text-end">
                                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
