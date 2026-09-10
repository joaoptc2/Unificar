<?php
/**
 * Configuração → Emojis personalizados (exibida na Administração central).
 * POSTs vão para ?m=chat&page=admin&action=addEmoji|deleteEmoji e voltam
 * para core_admin_url('chat', 'emojis').
 */
$canCreate = core_can('emojis.create');
$canDelete = core_can('emojis.delete');
?>
<div class="page-header">
    <h1 class="h4"><i class="bi bi-emoji-smile me-2"></i>Emojis personalizados</h1>
</div>
<p class="text-muted">Emojis da organização ficam disponíveis no seletor do chat e podem ser usados no texto como <code>:nome:</code> e em reações.</p>

<div class="row g-4">
    <?php if ($canCreate): ?>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i>Novo emoji</div>
            <div class="card-body">
                <form method="POST" action="index.php?m=chat&page=admin&action=addEmoji" enctype="multipart/form-data">
                    <?= Csrf::field() ?>
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label required">Nome</label>
                            <div class="input-group">
                                <span class="input-group-text">:</span>
                                <input type="text" name="name" class="form-control" required maxlength="50"
                                       placeholder="nome_do_emoji" pattern="[A-Za-z0-9_:]+"
                                       title="Letras, números e sublinhado">
                                <span class="input-group-text">:</span>
                            </div>
                            <div class="form-text">Convertido para minúsculas; espaços viram "_".</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Imagem</label>
                            <input type="file" name="image" class="form-control" required accept="image/png,image/gif,image/jpeg,image/webp">
                            <div class="form-text">PNG, GIF, JPG ou WebP. Recomendado: 128×128 px, fundo transparente.</div>
                        </div>
                        <div class="col-12">
                            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-check-lg me-1"></i> Adicionar emoji</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="<?= $canCreate ? 'col-lg-7' : 'col-12' ?>">
        <?php if (empty($emojis)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-emoji-smile display-4"></i>
                    <p class="mt-2 mb-0">Nenhum emoji personalizado cadastrado.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm">
                <div class="table-responsive">
                    <table class="table table-hover table-sm align-middle mb-0">
                        <thead>
                            <tr>
                                <th style="width:70px"></th>
                                <th>Código</th>
                                <th>Criado por</th>
                                <th>Em</th>
                                <th class="text-end" style="width:70px"></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($emojis as $emoji): ?>
                            <tr>
                                <td><img src="<?= Sanitize::e(Upload::url((string) $emoji['image_path'])) ?>" alt=":<?= Sanitize::e($emoji['name']) ?>:" class="emoji-card-img"></td>
                                <td><code>:<?= Sanitize::e($emoji['name']) ?>:</code></td>
                                <td><?= Sanitize::e($emoji['creator_name'] ?? '—') ?></td>
                                <td class="text-muted small"><?= Sanitize::e(Sanitize::formatDateTime($emoji['created_at'] ?? null)) ?></td>
                                <td class="text-end">
                                    <?php if ($canDelete): ?>
                                    <form method="POST" action="index.php?m=chat&page=admin&action=deleteEmoji" class="d-inline">
                                        <?= Csrf::field() ?>
                                        <input type="hidden" name="id" value="<?= (int) $emoji['id'] ?>">
                                        <button type="submit" class="btn btn-outline-danger btn-sm" data-confirm="Excluir o emoji :<?= Sanitize::e($emoji['name']) ?>:?" title="Excluir"><i class="bi bi-trash"></i></button>
                                    </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
