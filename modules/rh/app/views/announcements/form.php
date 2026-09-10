<?php
/**
 * Formulário de comunicado (novo/editar). Variáveis: $item (array|null),
 * $departments, $editorCfg (Core\DocLayout::editorConfig).
 * O corpo usa o editor compartilhado (Quill via DocLayout::editorHead/Scripts);
 * se o editor não carregar (CDN indisponível), o textarea de texto simples
 * assume — o controller aceita `body` como fallback.
 */
$isEdit = !empty($item['id']);
$formAction = $isEdit ? 'index.php?m=rh&page=announcements&action=update' : 'index.php?m=rh&page=announcements&action=store';
$published = !empty($item['published_at']);
?>
<?= Core\DocLayout::editorHead() ?>
<style>
    #editor { min-height: 280px; background: #fff; }
    .doc-editor-toolbar { background: #fff; }
    .ann-cover-preview { max-height: 140px; border-radius: 6px; }
</style>
<div class="page-header">
    <h1><i class="bi bi-megaphone me-2"></i><?= $isEdit ? 'Editar Comunicado' : 'Novo Comunicado' ?></h1>
    <a href="index.php?m=rh&page=announcements" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>
<form method="POST" action="<?= $formAction ?>" enctype="multipart/form-data" id="annForm">
    <?= Csrf::field() ?>
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= (int)$item['id'] ?>"><?php endif; ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-9">
                            <label class="form-label required">Título</label>
                            <input type="text" name="title" class="form-control" required maxlength="200" value="<?= Sanitize::e($item['title'] ?? '') ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo</label>
                            <select name="type" class="form-select">
                                <?php foreach (Announcement::TYPES as $k => $label): ?>
                                    <option value="<?= $k ?>" <?= ($item['type'] ?? 'informativo') === $k ? 'selected' : '' ?>><?= $label ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Resumo</label>
                            <input type="text" name="summary" class="form-control" maxlength="300" value="<?= Sanitize::e($item['summary'] ?? '') ?>" placeholder="Uma frase curta exibida na lista, na notificação e no e-mail">
                        </div>
                        <div class="col-12">
                            <label class="form-label required">Corpo do comunicado</label>
                            <div id="editorWrap">
                                <div id="editor"><?= $item['body_html'] ?? '' ?></div>
                            </div>
                            <input type="hidden" name="body_html" id="bodyHtmlField" value="<?= Sanitize::e($item['body_html'] ?? '') ?>">
                            <div id="plainWrap" hidden>
                                <textarea name="body" id="bodyPlain" class="form-control" rows="10" placeholder="Texto do comunicado"><?= Sanitize::e($item['body'] ?? '') ?></textarea>
                                <div class="form-text">O editor de texto rico não pôde ser carregado — o comunicado será salvo como texto simples.</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-image me-1"></i> Imagem de capa e anexo</div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Imagem de capa (JPG/PNG, até 5 MB)</label>
                            <input type="file" name="image" class="form-control" accept=".jpg,.jpeg,.png">
                            <?php if (!empty($item['image_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-3">
                                    <img src="<?= Sanitize::e(Upload::publicUrl($item['image_path'])) ?>" class="ann-cover-preview" alt="">
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_image" value="1" id="rmImg"><label class="form-check-label" for="rmImg">Remover imagem atual</label></div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Anexo (PDF, imagem, Word ou Excel, até 5 MB)</label>
                            <input type="file" name="attachment" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx,.xls,.xlsx">
                            <?php if (!empty($item['attachment_path'])): ?>
                                <div class="mt-2 d-flex align-items-center gap-3">
                                    <a href="<?= Sanitize::e(Upload::url($item['attachment_path'], 'announcement', (int)$item['id'])) ?>" target="_blank"><i class="bi bi-paperclip me-1"></i><?= Sanitize::e($item['attachment_name'] ?: 'Anexo') ?></a>
                                    <div class="form-check"><input class="form-check-input" type="checkbox" name="remove_attachment" value="1" id="rmAtt"><label class="form-check-label" for="rmAtt">Remover anexo atual</label></div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i> Público e divulgação</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Departamento-alvo</label>
                        <select name="department_id" class="form-select">
                            <option value="">Todos os departamentos</option>
                            <?php foreach ($departments as $d): ?>
                                <option value="<?= (int)$d['id'] ?>" <?= (int)($item['department_id'] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= Sanitize::e($d['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Validade (exibir até)</label>
                        <input type="date" name="expires_at" class="form-control" value="<?= Sanitize::e($item['expires_at'] ?? '') ?>">
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="pinned" value="1" id="pinned" <?= !empty($item['pinned']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="pinned"><i class="bi bi-pin me-1"></i>Fixar no topo</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" name="show_in_portal" value="1" id="showPortal" <?= !isset($item['show_in_portal']) || (int)$item['show_in_portal'] ? 'checked' : '' ?>>
                        <label class="form-check-label" for="showPortal"><i class="bi bi-person-badge me-1"></i>Exibir na Minha Área (portal)</label>
                    </div>
                    <div class="form-check form-switch mb-2">
                        <?php if (!empty($item['emailed_at'])): ?><input type="hidden" name="send_email" value="<?= (int)$item['send_email'] ?>"><?php endif; ?>
                        <input class="form-check-input" type="checkbox" name="send_email" value="1" id="sendEmail" <?= !empty($item['send_email']) ? 'checked' : '' ?> <?= !empty($item['emailed_at']) ? 'disabled' : '' ?>>
                        <label class="form-check-label" for="sendEmail"><i class="bi bi-envelope me-1"></i>Enviar por e-mail ao publicar</label>
                        <?php if (!empty($item['emailed_at'])): ?>
                            <div class="form-text text-success">E-mails enfileirados em <?= Sanitize::formatDateTime($item['emailed_at']) ?>.</div>
                        <?php else: ?>
                            <div class="form-text">Somente funcionários ativos do público-alvo com e-mail real.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-send me-1"></i> Publicação</div>
                <div class="card-body">
                    <?php if ($published): ?>
                        <p class="small text-muted mb-2">Publicado em <?= Sanitize::formatDateTime($item['published_at']) ?>.</p>
                        <input type="hidden" name="publish_now" value="1">
                        <div class="form-check mb-3">
                            <input class="form-check-input" type="checkbox" name="unpublish" value="1" id="unpublish" onchange="document.querySelector('[name=publish_now]').value = this.checked ? '' : '1'">
                            <label class="form-check-label" for="unpublish">Voltar para rascunho (despublicar)</label>
                        </div>
                    <?php else: ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="publish_now" value="1" id="publishNow" checked>
                            <label class="form-check-label" for="publishNow">Publicar agora</label>
                            <div class="form-text">Desmarque para salvar como rascunho.</div>
                        </div>
                    <?php endif; ?>
                    <div class="d-grid gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> <?= $isEdit ? 'Salvar alterações' : 'Salvar comunicado' ?></button>
                        <a href="index.php?m=rh&page=announcements" class="btn btn-outline-secondary">Cancelar</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<?= Core\DocLayout::editorScripts() ?>
<script>
(function () {
    var cfg = <?= json_encode($editorCfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    var form = document.getElementById('annForm');
    var quill = (window.Quill && window.DocEditor) ? DocEditor.init('#editor', cfg) : null;
    if (!quill) {
        // Sem editor: usa o texto simples e ignora o HTML anterior.
        document.getElementById('editorWrap').hidden = true;
        document.getElementById('plainWrap').hidden = false;
        form.addEventListener('submit', function () { document.getElementById('bodyHtmlField').value = ''; });
    }
})();
</script>
