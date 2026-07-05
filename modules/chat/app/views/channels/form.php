<?php $isEdit = !empty($channel['id']); ?>
<div class="page-header">
    <h1><i class="bi bi-hash me-2"></i><?= $isEdit ? 'Editar Canal' : 'Novo Canal' ?></h1>
    <a href="index.php?page=chat" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i> Voltar
    </a>
</div>

<div class="row justify-content-center">
    <div class="col-md-8">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <form method="POST" action="index.php?page=channels&action=<?= $isEdit ? 'update' : 'store' ?>">
                    <?= Csrf::field() ?>
                    <?php if ($isEdit): ?>
                        <input type="hidden" name="id" value="<?= $channel['id'] ?>">
                    <?php endif; ?>

                    <div class="row g-3">
                        <div class="col-md-8">
                            <label class="form-label required">Nome do canal</label>
                            <div class="input-group">
                                <span class="input-group-text">#</span>
                                <input type="text" name="name" class="form-control" required
                                       placeholder="ex: projetos-marketing"
                                       value="<?= Sanitize::e($channel['name'] ?? '') ?>">
                            </div>
                            <div class="form-text">Use letras minúsculas, números e hífens.</div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-select" <?= $isEdit ? 'disabled' : '' ?>>
                                <option value="public" <?= ($channel['type'] ?? '') === 'public' ? 'selected' : '' ?>>Público</option>
                                <option value="private" <?= ($channel['type'] ?? '') === 'private' ? 'selected' : '' ?>>Privado</option>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrição</label>
                            <textarea name="description" class="form-control" rows="2"
                                      placeholder="Sobre o que é este canal?"><?= Sanitize::e($channel['description'] ?? '') ?></textarea>
                        </div>
                        <?php if ($isEdit): ?>
                        <div class="col-12">
                            <label class="form-label">Tópico</label>
                            <input type="text" name="topic" class="form-control"
                                   placeholder="Tópico atual do canal"
                                   value="<?= Sanitize::e($channel['topic'] ?? '') ?>">
                        </div>
                        <?php endif; ?>

                        <?php if (!$isEdit): ?>
                        <div class="col-12">
                            <label class="form-label">Adicionar membros</label>
                            <select name="members[]" class="form-select" multiple size="5">
                                <?php foreach ($users ?? [] as $u): ?>
                                    <?php if ($u['id'] != Session::userId()): ?>
                                    <option value="<?= $u['id'] ?>"><?= Sanitize::e($u['name']) ?> (<?= Sanitize::e($u['email']) ?>)</option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Segure Ctrl para selecionar múltiplos. Você será adicionado automaticamente.</div>
                        </div>
                        <?php endif; ?>

                        <div class="col-12 text-end">
                            <a href="index.php?page=chat" class="btn btn-outline-secondary me-2">Cancelar</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Atualizar' : 'Criar Canal' ?>
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
