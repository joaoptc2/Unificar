<?php
/**
 * INTRANET — editor de documentos (DocEditor/Quill) com versionamento.
 * Usa os layouts do núcleo (Core\DocLayout): layout de página (papel
 * timbrado), layout de CAPA opcional com conteúdo próprio, e fonte/tamanho
 * do texto restritos à lista permitida pelo layout escolhido.
 */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = $id ? intra_find_document($id) : null;

if ($id && !$doc) {
    Flash::set('error', 'Documento não encontrado.');
    core_redirect('index.php?m=intranet&page=documents');
}
core_require($doc ? 'documents.edit' : 'documents.create');

// ---- Salvar -----------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::check();

    $title   = trim((string) ($_POST['title'] ?? ''));
    $note    = trim((string) ($_POST['note'] ?? ''));
    $content = intra_sanitize_html((string) ($_POST['content'] ?? ''));
    $lf      = intra_collect_layout_fields($_POST);

    $canPublish = core_can('documents.publish');
    $status     = $canPublish && ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    $isPublic   = $canPublish && !empty($_POST['is_public']) ? 1 : 0;

    if ($title === '') {
        Flash::set('error', 'Informe o título do documento.');
    } elseif ($lf['layout_id'] === null) {
        Flash::set('error', 'Nenhum layout de documento ativo — cadastre um em Administração > Layouts de documentos.');
    } elseif ($doc) {
        $changed = $title !== $doc['title']
            || $content !== (string) $doc['content_html']
            || $lf['layout_id'] !== ($doc['layout_id'] !== null ? (int) $doc['layout_id'] : null)
            || $lf['cover_layout_id'] !== ($doc['cover_layout_id'] !== null ? (int) $doc['cover_layout_id'] : null)
            || (string) $lf['cover_html'] !== (string) $doc['cover_html']
            || (string) $lf['font_family'] !== (string) $doc['font_family']
            || (string) $lf['font_size'] !== (string) $doc['font_size'];

        $version = (int) $doc['current_version'];
        if ($changed) {
            $version++;
            DB::execute(
                'INSERT INTO intra_document_versions
                    (document_id, version, title, layout_id, cover_layout_id, content_html, cover_html, font_family, font_size, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$doc['id'], $version, $title, $lf['layout_id'], $lf['cover_layout_id'], $content, $lf['cover_html'],
                 $lf['font_family'], $lf['font_size'], $note ?: null, Auth::id()]
            );
        }
        DB::execute(
            'UPDATE intra_documents SET title = ?, layout_id = ?, cover_layout_id = ?, content_html = ?, cover_html = ?,
                    font_family = ?, font_size = ?, status = ?, is_public = ?, current_version = ?, updated_by = ?
             WHERE id = ?',
            [$title, $lf['layout_id'], $lf['cover_layout_id'], $content, $lf['cover_html'], $lf['font_family'], $lf['font_size'],
             $canPublish ? $status : $doc['status'], $canPublish ? $isPublic : (int) $doc['is_public'],
             $version, Auth::id(), $doc['id']]
        );
        Audit::log('intranet.document_update', 'intra_documents', (string) $doc['id'], ['v' => $version, 'note' => $note]);
        Flash::set('success', $changed ? "Documento salvo — versão v{$version} registrada." : 'Documento salvo (sem alterações de conteúdo).');
        core_redirect('index.php?m=intranet&page=editor&id=' . $doc['id']);
    } else {
        DB::execute(
            'INSERT INTO intra_documents
                (title, layout_id, cover_layout_id, content_html, cover_html, font_family, font_size, status, is_public, public_token, current_version, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$title, $lf['layout_id'], $lf['cover_layout_id'], $content, $lf['cover_html'], $lf['font_family'], $lf['font_size'],
             $status, $isPublic, bin2hex(random_bytes(16)), Auth::id(), Auth::id()]
        );
        $newId = DB::lastId();
        DB::execute(
            'INSERT INTO intra_document_versions
                (document_id, version, title, layout_id, cover_layout_id, content_html, cover_html, font_family, font_size, note, created_by)
             VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$newId, $title, $lf['layout_id'], $lf['cover_layout_id'], $content, $lf['cover_html'], $lf['font_family'], $lf['font_size'],
             $note ?: 'Criação do documento', Auth::id()]
        );
        Audit::log('intranet.document_create', 'intra_documents', (string) $newId, ['title' => $title]);
        Flash::set('success', 'Documento criado (v1).');
        core_redirect('index.php?m=intranet&page=editor&id=' . $newId);
    }
}

// ---- Formulário ---------------------------------------------------------------
$pageLayouts  = intra_active_layouts('page');
$coverLayouts = intra_active_layouts('cover');
$layout       = intra_find_layout($doc ? (int) $doc['layout_id'] : null);
$canPublish   = core_can('documents.publish');

$curLayout = $layout ? (int) $layout['id'] : 0;
$curCover  = $doc && !empty($doc['cover_layout_id']) ? (int) $doc['cover_layout_id'] : 0;
$curFont   = (string) ($doc['font_family'] ?? '');
$curSize   = (string) ($doc['font_size'] ?? '');
$layoutsJson = intra_layouts_editor_json(array_merge($pageLayouts, $coverLayouts));
$editorCfg   = Core\DocLayout::editorConfig($layout);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0">
        <i class="bi bi-file-earmark-richtext me-2"></i><?= $doc ? 'Editar documento' : 'Novo documento' ?>
        <?php if ($doc): ?><span class="badge text-bg-light border">v<?= (int) $doc['current_version'] ?></span><?php endif; ?>
    </h1>
    <div class="d-flex gap-2">
        <?php if ($doc && core_can('history.view')): ?>
            <a class="btn btn-outline-secondary btn-sm" href="<?= MODULE_URL ?>&page=history&id=<?= (int) $doc['id'] ?>">
                <i class="bi bi-clock-history me-1"></i>Histórico
            </a>
        <?php endif; ?>
        <?php if ($doc && core_can('documents.view')): ?>
            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= MODULE_URL ?>&page=view&id=<?= (int) $doc['id'] ?>">
                <i class="bi bi-eye me-1"></i>Visualizar
            </a>
        <?php endif; ?>
        <?php if ($doc && core_can('documents.export')): ?>
            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= MODULE_URL ?>&page=print&id=<?= (int) $doc['id'] ?>">
                <i class="bi bi-filetype-pdf me-1"></i>Exportar PDF
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= MODULE_URL ?>&page=documents">Voltar</a>
    </div>
</div>

<?php if (!$pageLayouts): ?>
<div class="alert alert-warning">
    <i class="bi bi-exclamation-triangle me-1"></i>Nenhum layout de página ativo. Cadastre um em
    <a href="<?= core_url('index.php?m=admin&a=layouts') ?>">Administração &rsaquo; Layouts de documentos</a>.
</div>
<?php endif; ?>

<form method="post" id="docForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($doc['id'] ?? 0) ?>">
    <input type="hidden" name="content" id="contentField" value="<?= core_e($doc['content_html'] ?? '') ?>">
    <input type="hidden" name="cover_html" id="coverField" value="<?= core_e($doc['cover_html'] ?? '') ?>">

    <div class="row g-3">
        <div class="col-12 col-xl-9">
            <div class="card mb-3">
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-12 col-md-7">
                            <label class="form-label">Título *</label>
                            <input class="form-control" name="title" value="<?= core_e($doc['title'] ?? '') ?>" required>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Layout de página (papel timbrado)</label>
                            <select class="form-select" name="layout_id" id="layoutSelect">
                                <?php foreach ($pageLayouts as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>" <?= $curLayout === (int) $l['id'] ? 'selected' : '' ?>>
                                        <?= core_e($l['name']) ?> — <?= core_e($l['page_size']) ?> <?= $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$pageLayouts): ?><option value="">(nenhum layout cadastrado)</option><?php endif; ?>
                            </select>
                        </div>
                        <div class="col-6 col-md-4">
                            <label class="form-label">Fonte do texto</label>
                            <select class="form-select" name="font_family" id="fontSelect" data-current="<?= core_e($curFont) ?>"></select>
                            <div class="form-text">Somente as fontes permitidas pelo layout.</div>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label">Tamanho</label>
                            <select class="form-select" name="font_size" id="sizeSelect" data-current="<?= core_e($curSize) ?>"></select>
                        </div>
                        <div class="col-12 col-md-5">
                            <label class="form-label">Capa (opcional)</label>
                            <select class="form-select" name="cover_layout_id" id="coverLayoutSelect">
                                <option value="">Sem capa</option>
                                <?php foreach ($coverLayouts as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>" <?= $curCover === (int) $l['id'] ? 'selected' : '' ?>><?= core_e($l['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Capa: conteúdo próprio (vazio = modelo de capa do layout) -->
            <div class="card mb-3" id="coverBox" <?= $curCover ? '' : 'hidden' ?>>
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-file-earmark-break me-1"></i>Conteúdo da capa</span>
                    <small class="text-muted">Variáveis: {{titulo}} {{codigo}} {{setor}} {{autor}} {{data}} {{versao}} {{subtitulo}} {{org}} {{logo}}. Deixe vazio para usar o modelo de capa do layout.</small>
                </div>
                <div class="card-body">
                    <div class="doc-editor-wrap doc-editor-cover">
                        <div id="coverEditor"><?= $doc['cover_html'] ?? '' ?></div>
                    </div>
                </div>
            </div>

            <!-- Editor: a largura acompanha a área útil do layout escolhido -->
            <div class="doc-editor-wrap" id="editorWrap">
                <div id="editor"><?= $doc['content_html'] ?? '' ?></div>
            </div>
        </div>

        <div class="col-12 col-xl-3">
            <div class="card mb-3">
                <div class="card-header">Publicação</div>
                <div class="card-body">
                    <?php if ($canPublish): ?>
                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select class="form-select" name="status">
                                <option value="draft" <?= ($doc['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>Rascunho</option>
                                <option value="published" <?= ($doc['status'] ?? '') === 'published' ? 'selected' : '' ?>>Publicado</option>
                            </select>
                        </div>
                        <div class="form-check form-switch mb-2">
                            <input class="form-check-input" type="checkbox" name="is_public" id="isPub" value="1" <?= !empty($doc['is_public']) ? 'checked' : '' ?>>
                            <label class="form-check-label" for="isPub">Cópia pública (link sem login)</label>
                        </div>
                        <?php if ($doc && $doc['is_public']): ?>
                            <div class="input-group input-group-sm">
                                <input class="form-control" readonly id="pubLink"
                                       value="<?= core_e(core_url('index.php?m=intranet&page=public&token=' . $doc['public_token'])) ?>">
                                <button type="button" class="btn btn-outline-secondary"
                                        onclick="navigator.clipboard.writeText(document.getElementById('pubLink').value);this.innerHTML='<i class=\'bi bi-check\'></i>'">
                                    <i class="bi bi-clipboard"></i>
                                </button>
                            </div>
                            <div class="form-text">A cópia pública só abre quando o status é "Publicado".</div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="small text-muted mb-0">
                            Status atual: <strong><?= ($doc['status'] ?? 'draft') === 'published' ? 'Publicado' : 'Rascunho' ?></strong>.<br>
                            Você não tem a permissão "Publicar / tornar público".
                        </p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Registro da edição</div>
                <div class="card-body">
                    <label class="form-label small">O que mudou nesta edição? (opcional)</label>
                    <input class="form-control" name="note" maxlength="255" placeholder="ex.: revisão do item 3.2">
                    <div class="form-text">Cada salvamento com alterações (texto, capa, layout ou fonte) gera uma nova versão no histórico.</div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header">Como funciona</div>
                <div class="card-body small text-muted">
                    <p class="mb-2"><strong>Layout de página:</strong> define papel timbrado, margens, fundo e as fontes permitidas.</p>
                    <p class="mb-0"><strong>Capa:</strong> folha própria antes do documento; se o conteúdo ficar vazio, usa o modelo de capa do layout escolhido.</p>
                </div>
            </div>

            <button class="btn btn-primary w-100" <?= $pageLayouts ? '' : 'disabled' ?>><i class="bi bi-check-lg me-1"></i>Salvar documento</button>
        </div>
    </div>
</form>
<?php
$content = (string) ob_get_clean();

$head = Core\DocLayout::editorHead();

$scripts = Core\DocLayout::editorScripts() . '
<script>
(function () {
    var LAYOUTS = ' . $layoutsJson . ';
    var CFG = ' . json_encode([
        'currentLayout' => $curLayout, 'currentCover' => $curCover,
        'currentFont' => $curFont, 'currentSize' => $curSize,
        'defaults' => $editorCfg,
    ], JSON_UNESCAPED_UNICODE) . ';
    var form = document.getElementById("docForm");
    var layoutSelect = document.getElementById("layoutSelect");
    var fontSelect   = document.getElementById("fontSelect");
    var sizeSelect   = document.getElementById("sizeSelect");
    var coverSelect  = document.getElementById("coverLayoutSelect");
    var coverBox     = document.getElementById("coverBox");
    var contentField = document.getElementById("contentField");
    var coverField   = document.getElementById("coverField");
    var quill = null, coverQuill = null;

    function layoutCfg() {
        var id = layoutSelect ? layoutSelect.value : CFG.currentLayout;
        return LAYOUTS[String(id)] || CFG.defaults;
    }
    function fillSelect(sel, list, wanted, fallback) {
        if (!sel) return;
        sel.innerHTML = "";
        var found = false;
        (list || []).forEach(function (v) {
            var o = document.createElement("option");
            o.value = v; o.textContent = v;
            if (v === wanted) { o.selected = true; found = true; }
            sel.appendChild(o);
        });
        if (!found && fallback && (list || []).indexOf(fallback) !== -1) sel.value = fallback;
    }
    function applyFont() {
        var f = fontSelect ? fontSelect.value : "", s = sizeSelect ? sizeSelect.value : "";
        [quill, coverQuill].forEach(function (q) {
            var root = q ? q.root : null;
            if (!root) return;
            root.style.fontFamily = f ? "\'" + f.replace(/\'/g, "") + "\'" : "";
            root.style.fontSize = s || "";
        });
    }
    function applyLayout(keep) {
        var lc = layoutCfg();
        fillSelect(fontSelect, lc.fonts, keep && fontSelect ? fontSelect.value : (CFG.currentFont || lc.defaultFont), lc.defaultFont);
        fillSelect(sizeSelect, lc.sizes, keep && sizeSelect ? sizeSelect.value : (CFG.currentSize || lc.defaultSize), lc.defaultSize);
        if (window.DocEditor) {
            [quill, coverQuill].forEach(function (q) {
                if (q) DocEditor.applyLayout(q, { sheetWidth: lc.sheetWidth, marginLeft: lc.marginLeft, marginRight: lc.marginRight,
                    defaultFont: fontSelect ? fontSelect.value : lc.defaultFont, defaultSize: sizeSelect ? sizeSelect.value : lc.defaultSize });
            });
        }
        applyFont();
    }
    function toggleCover() {
        if (coverBox) coverBox.hidden = !(coverSelect && coverSelect.value !== "");
    }
    function fallbackTextarea(el, hidden) {
        var ta = document.createElement("textarea");
        ta.className = "form-control font-monospace"; ta.rows = 14;
        ta.value = hidden.value || el.innerHTML;
        el.parentNode.replaceChild(ta, el);
        form.addEventListener("submit", function () { hidden.value = ta.value; });
    }

    var lc0 = layoutCfg();
    var el = document.getElementById("editor"), coverEl = document.getElementById("coverEditor");
    if (window.Quill && window.DocEditor) {
        quill = DocEditor.init(el, { fonts: lc0.fonts, sizes: lc0.sizes,
            defaultFont: CFG.currentFont || lc0.defaultFont, defaultSize: CFG.currentSize || lc0.defaultSize,
            sheetWidth: lc0.sheetWidth, marginLeft: lc0.marginLeft, marginRight: lc0.marginRight,
            hiddenInput: "#contentField", placeholder: "Escreva o conteúdo do documento..." });
        coverQuill = DocEditor.init(coverEl, { fonts: lc0.fonts, sizes: lc0.sizes,
            defaultFont: CFG.currentFont || lc0.defaultFont, defaultSize: CFG.currentSize || lc0.defaultSize,
            sheetWidth: lc0.sheetWidth, marginLeft: lc0.marginLeft, marginRight: lc0.marginRight,
            hiddenInput: "#coverField", noWarn: true,
            placeholder: "Conteúdo da capa (título, subtítulo, setor...). Vazio = modelo de capa do layout." });
    } else {
        if (el) fallbackTextarea(el, contentField);
        if (coverEl) fallbackTextarea(coverEl, coverField);
    }
    applyLayout(false);
    toggleCover();

    if (layoutSelect) layoutSelect.addEventListener("change", function () { applyLayout(true); });
    if (fontSelect) fontSelect.addEventListener("change", applyFont);
    if (sizeSelect) sizeSelect.addEventListener("change", applyFont);
    if (coverSelect) coverSelect.addEventListener("change", toggleCover);

    form.addEventListener("submit", function () {
        if (quill) contentField.value = quill.root.innerHTML;
        if (coverQuill) coverField.value = coverSelect && coverSelect.value !== "" ? coverQuill.root.innerHTML : "";
    });
})();
</script>';

intra_view($doc ? 'Editar documento' : 'Novo documento', $content, $doc ? 'documents' : 'editor', $head, $scripts);
