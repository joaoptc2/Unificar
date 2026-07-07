<?php
/** INTRANET — editor de documentos (Quill) com versionamento. */

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

    $title    = trim((string) ($_POST['title'] ?? ''));
    $layoutId = (int) ($_POST['layout_id'] ?? 0) ?: null;
    $note     = trim((string) ($_POST['note'] ?? ''));
    $content  = intra_sanitize_html((string) ($_POST['content'] ?? ''));

    $canPublish = core_can('documents.publish');
    $status     = $canPublish && ($_POST['status'] ?? '') === 'published' ? 'published' : 'draft';
    $isPublic   = $canPublish && !empty($_POST['is_public']) ? 1 : 0;

    if ($title === '') {
        Flash::set('error', 'Informe o título do documento.');
    } elseif ($doc) {
        $changed = $title !== $doc['title']
            || $content !== (string) $doc['content_html']
            || $layoutId !== ($doc['layout_id'] !== null ? (int) $doc['layout_id'] : null);

        $version = (int) $doc['current_version'];
        if ($changed) {
            $version++;
            DB::execute(
                'INSERT INTO intra_document_versions (document_id, version, title, layout_id, content_html, note, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$doc['id'], $version, $title, $layoutId, $content, $note ?: null, Auth::id()]
            );
        }
        DB::execute(
            'UPDATE intra_documents SET title = ?, layout_id = ?, content_html = ?, status = ?, is_public = ?,
                    current_version = ?, updated_by = ? WHERE id = ?',
            [$title, $layoutId, $content, $canPublish ? $status : $doc['status'],
             $canPublish ? $isPublic : (int) $doc['is_public'], $version, Auth::id(), $doc['id']]
        );
        Audit::log('intranet.document_update', 'intra_documents', (string) $doc['id'], ['v' => $version, 'note' => $note]);
        Flash::set('success', $changed ? "Documento salvo — versão v{$version} registrada." : 'Documento salvo (sem alterações de conteúdo).');
        core_redirect('index.php?m=intranet&page=editor&id=' . $doc['id']);
    } else {
        DB::execute(
            'INSERT INTO intra_documents (title, layout_id, content_html, status, is_public, public_token, current_version, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)',
            [$title, $layoutId, $content, $status, $isPublic, bin2hex(random_bytes(16)), Auth::id(), Auth::id()]
        );
        $newId = DB::lastId();
        DB::execute(
            'INSERT INTO intra_document_versions (document_id, version, title, layout_id, content_html, note, created_by)
             VALUES (?, 1, ?, ?, ?, ?, ?)',
            [$newId, $title, $layoutId, $content, $note ?: 'Criação do documento', Auth::id()]
        );
        Audit::log('intranet.document_create', 'intra_documents', (string) $newId, ['title' => $title]);
        Flash::set('success', 'Documento criado (v1).');
        core_redirect('index.php?m=intranet&page=editor&id=' . $newId);
    }
}

// ---- Formulário ---------------------------------------------------------------
$layouts    = intra_active_layouts();
$layout     = intra_find_layout($doc ? (int) $doc['layout_id'] : null);
$canPublish = core_can('documents.publish');
[$pw]       = $layout ? intra_page_dims((string) $layout['page_size'], (string) $layout['orientation']) : [210];

$dimsJson = json_encode(array_map(
    fn ($l) => ['w' => intra_page_dims((string) $l['page_size'], (string) $l['orientation'])[0],
                'ml' => (int) $l['margin_left'], 'mr' => (int) $l['margin_right']],
    array_column($layouts, null, 'id')
), JSON_FORCE_OBJECT);

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
        <?php if ($doc && core_can('documents.export')): ?>
            <a class="btn btn-outline-secondary btn-sm" target="_blank" href="<?= MODULE_URL ?>&page=print&id=<?= (int) $doc['id'] ?>">
                <i class="bi bi-filetype-pdf me-1"></i>Exportar PDF
            </a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= MODULE_URL ?>&page=documents">Voltar</a>
    </div>
</div>

<form method="post" id="docForm">
    <?= Csrf::field() ?>
    <input type="hidden" name="id" value="<?= (int) ($doc['id'] ?? 0) ?>">
    <input type="hidden" name="content" id="contentField">

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
                            <label class="form-label">Layout (papel timbrado)</label>
                            <select class="form-select" name="layout_id" id="layoutSelect">
                                <?php foreach ($layouts as $l): ?>
                                    <option value="<?= (int) $l['id'] ?>" <?= ($layout && (int) $layout['id'] === (int) $l['id']) ? 'selected' : '' ?>>
                                        <?= core_e($l['name']) ?> — <?= core_e($l['page_size']) ?> <?= $l['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?>
                                    </option>
                                <?php endforeach; ?>
                                <?php if (!$layouts): ?><option value="">(nenhum layout cadastrado)</option><?php endif; ?>
                            </select>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Editor: a largura acompanha a área útil do layout escolhido -->
            <div class="intra-editor-wrap" id="editorWrap" style="--sheet-w: <?= (int) $pw ?>mm">
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
                    <div class="form-text">Cada salvamento com alterações gera uma nova versão no histórico.</div>
                </div>
            </div>

            <button class="btn btn-primary w-100"><i class="bi bi-check-lg me-1"></i>Salvar documento</button>
        </div>
    </div>
</form>
<?php
$content = (string) ob_get_clean();

$head = '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css">';

$scripts = '<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function () {
    var quill = new Quill("#editor", {
        theme: "snow",
        placeholder: "Escreva o conteúdo do documento...",
        modules: { toolbar: [
            [{ header: [1, 2, 3, false] }],
            ["bold", "italic", "underline", "strike"],
            [{ color: [] }, { background: [] }],
            [{ align: [] }],
            [{ list: "ordered" }, { list: "bullet" }],
            [{ indent: "-1" }, { indent: "+1" }],
            ["blockquote", "link", "image"],
            ["clean"]
        ]}
    });
    document.getElementById("docForm").addEventListener("submit", function () {
        document.getElementById("contentField").value = quill.root.innerHTML;
    });
    // Largura do editor acompanha o layout selecionado (área útil da página)
    var dims = ' . $dimsJson . ';
    var sel = document.getElementById("layoutSelect");
    if (sel) sel.addEventListener("change", function () {
        var d = dims[this.value];
        if (d) document.getElementById("editorWrap").style.setProperty("--sheet-w", d.w + "mm");
    });
})();
</script>';

intra_view($doc ? 'Editar documento' : 'Novo documento', $content, $doc ? 'documents' : 'editor', $head, $scripts);
