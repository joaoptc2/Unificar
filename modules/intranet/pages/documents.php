<?php
/** INTRANET — listagem e acompanhamento dos documentos. */

declare(strict_types=1);

use Core\Audit;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('documents.view');

// ---- Excluir --------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    core_require('documents.delete');
    Csrf::check();
    $id  = (int) ($_POST['id'] ?? 0);
    $doc = intra_find_document($id);
    if ($doc) {
        DB::execute('DELETE FROM intra_documents WHERE id = ?', [$id]); // versões via CASCADE
        Audit::log('intranet.document_delete', 'intra_documents', (string) $id, ['title' => $doc['title']]);
        Flash::set('success', 'Documento excluído.');
    }
    core_redirect('index.php?m=intranet&page=documents');
}

// ---- Listagem ---------------------------------------------------------------
$q      = trim((string) ($_GET['q'] ?? ''));
$status = (string) ($_GET['status'] ?? '');
$conds  = [];
$params = [];
if ($q !== '') {
    $conds[] = 'd.title LIKE ?';
    $params[] = "%{$q}%";
}
if (in_array($status, ['draft', 'published'], true)) {
    $conds[] = 'd.status = ?';
    $params[] = $status;
}
$wc = $conds ? 'WHERE ' . implode(' AND ', $conds) : '';

$docs = DB::query(
    "SELECT d.*, l.name AS layout_name, l.page_size, l.orientation,
            uc.name AS created_name, uu.name AS updated_name
     FROM intra_documents d
     LEFT JOIN intra_layouts l ON l.id = d.layout_id
     LEFT JOIN users uc ON uc.id = d.created_by
     LEFT JOIN users uu ON uu.id = d.updated_by
     {$wc}
     ORDER BY d.updated_at DESC
     LIMIT 300",
    $params
);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-files me-2"></i>Documentos da Intranet</h1>
    <?php if (core_can('documents.create')): ?>
        <a class="btn btn-primary" href="<?= MODULE_URL ?>&page=editor"><i class="bi bi-file-earmark-plus me-1"></i>Novo documento</a>
    <?php endif; ?>
</div>

<form class="row g-2 mb-3" method="get">
    <input type="hidden" name="m" value="intranet"><input type="hidden" name="page" value="documents">
    <div class="col-auto">
        <input class="form-control" name="q" value="<?= core_e($q) ?>" placeholder="Buscar por título">
    </div>
    <div class="col-auto">
        <select class="form-select" name="status" onchange="this.form.submit()">
            <option value="">Todos os status</option>
            <option value="draft" <?= $status === 'draft' ? 'selected' : '' ?>>Rascunho</option>
            <option value="published" <?= $status === 'published' ? 'selected' : '' ?>>Publicado</option>
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button></div>
</form>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Título</th><th>Layout</th><th class="text-center">Status</th>
                    <th class="text-center">Versão</th><th>Atualização</th><th class="text-end"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (!$docs): ?>
                <tr><td colspan="6" class="text-center text-muted py-4">Nenhum documento. Crie o primeiro em "Novo documento".</td></tr>
            <?php endif; ?>
            <?php foreach ($docs as $d): ?>
                <tr>
                    <td>
                        <a class="fw-semibold text-decoration-none" href="<?= MODULE_URL ?>&page=view&id=<?= (int) $d['id'] ?>"><?= core_e($d['title']) ?></a>
                        <?php if ($d['is_public']): ?>
                            <span class="badge text-bg-info" title="Possui cópia pública"><i class="bi bi-globe2"></i> pública</span>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted">
                        <?= core_e($d['layout_name'] ?? '—') ?>
                        <?php if ($d['layout_name']): ?>
                            <span class="badge text-bg-light border"><?= core_e($d['page_size']) ?> <?= $d['orientation'] === 'landscape' ? 'paisagem' : 'retrato' ?></span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($d['status'] === 'published'): ?>
                            <span class="badge text-bg-success">publicado</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">rascunho</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center"><span class="badge text-bg-light border">v<?= (int) $d['current_version'] ?></span></td>
                    <td class="small text-muted">
                        <?= core_e(date('d/m/Y H:i', strtotime((string) $d['updated_at']))) ?><br>
                        por <?= core_e($d['updated_name'] ?? $d['created_name'] ?? '—') ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <?php if ($d['is_public']): ?>
                            <button class="btn btn-sm btn-outline-info" title="Copiar link público"
                                    onclick="navigator.clipboard.writeText('<?= core_e(core_url('index.php?m=intranet&page=public&token=' . $d['public_token'])) ?>');this.innerHTML='<i class=\'bi bi-check\'></i>'">
                                <i class="bi bi-link-45deg"></i>
                            </button>
                        <?php endif; ?>
                        <?php if (core_can('documents.export')): ?>
                            <a class="btn btn-sm btn-outline-secondary" title="Exportar PDF" target="_blank"
                               href="<?= MODULE_URL ?>&page=print&id=<?= (int) $d['id'] ?>"><i class="bi bi-filetype-pdf"></i></a>
                        <?php endif; ?>
                        <?php if (core_can('history.view')): ?>
                            <a class="btn btn-sm btn-outline-secondary" title="Histórico de edições"
                               href="<?= MODULE_URL ?>&page=history&id=<?= (int) $d['id'] ?>"><i class="bi bi-clock-history"></i></a>
                        <?php endif; ?>
                        <?php if (core_can('documents.edit')): ?>
                            <a class="btn btn-sm btn-outline-primary" title="Editar"
                               href="<?= MODULE_URL ?>&page=editor&id=<?= (int) $d['id'] ?>"><i class="bi bi-pencil"></i></a>
                        <?php endif; ?>
                        <?php if (core_can('documents.delete')): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Excluir o documento e todo o histórico?')">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= (int) $d['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" title="Excluir"><i class="bi bi-trash"></i></button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php
intra_view('Documentos', (string) ob_get_clean(), 'documents');
