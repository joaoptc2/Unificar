<?php
/** INTRANET — histórico e acompanhamento das edições de um documento. */

declare(strict_types=1);

use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;

core_require('history.view');

$id  = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$doc = intra_find_document($id);
if (!$doc) {
    Flash::set('error', 'Documento não encontrado.');
    core_redirect('index.php?m=intranet&page=documents');
}

// ---- Restaurar versão -------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    core_require('history.restore');
    Csrf::check();
    $v   = (int) ($_POST['version'] ?? 0);
    $row = DB::queryOne('SELECT * FROM intra_document_versions WHERE document_id = ? AND version = ?', [$id, $v]);
    if ($row) {
        $newVersion = (int) $doc['current_version'] + 1;
        DB::execute(
            'INSERT INTO intra_document_versions (document_id, version, title, layout_id, content_html, note, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$id, $newVersion, $row['title'], $row['layout_id'], $row['content_html'],
             "Restauração da versão v{$v}", Auth::id()]
        );
        DB::execute(
            'UPDATE intra_documents SET title = ?, layout_id = ?, content_html = ?, current_version = ?, updated_by = ? WHERE id = ?',
            [$row['title'], $row['layout_id'], $row['content_html'], $newVersion, Auth::id(), $id]
        );
        Audit::log('intranet.document_restore', 'intra_documents', (string) $id, ['restored' => $v, 'new' => $newVersion]);
        Flash::set('success', "Versão v{$v} restaurada como v{$newVersion}.");
    }
    core_redirect('index.php?m=intranet&page=history&id=' . $id);
}

$versions = DB::query(
    'SELECT v.*, u.name AS author FROM intra_document_versions v
     LEFT JOIN users u ON u.id = v.created_by
     WHERE v.document_id = ? ORDER BY v.version DESC',
    [$id]
);

ob_start(); ?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h4 mb-0"><i class="bi bi-clock-history me-2"></i>Histórico — <?= core_e($doc['title']) ?></h1>
    <div class="d-flex gap-2">
        <?php if (core_can('documents.edit')): ?>
            <a class="btn btn-outline-primary btn-sm" href="<?= MODULE_URL ?>&page=editor&id=<?= (int) $doc['id'] ?>"><i class="bi bi-pencil me-1"></i>Editar</a>
        <?php endif; ?>
        <a class="btn btn-outline-secondary btn-sm" href="<?= MODULE_URL ?>&page=documents">Voltar</a>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th class="text-center">Versão</th><th>O que mudou</th><th>Autor</th><th>Quando</th><th class="text-end"></th></tr></thead>
            <tbody>
            <?php foreach ($versions as $v): $isCurrent = (int) $v['version'] === (int) $doc['current_version']; ?>
                <tr class="<?= $isCurrent ? 'table-primary' : '' ?>">
                    <td class="text-center">
                        <span class="badge <?= $isCurrent ? 'text-bg-primary' : 'text-bg-light border' ?>">v<?= (int) $v['version'] ?></span>
                        <?= $isCurrent ? '<div class="small text-muted">atual</div>' : '' ?>
                    </td>
                    <td>
                        <div class="fw-semibold small"><?= core_e($v['title']) ?></div>
                        <div class="small text-muted"><?= core_e($v['note'] ?? '—') ?></div>
                    </td>
                    <td class="small"><?= core_e($v['author'] ?? '—') ?></td>
                    <td class="small text-muted"><?= core_e(date('d/m/Y H:i', strtotime((string) $v['created_at']))) ?></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-outline-secondary" title="Visualizar esta versão" target="_blank"
                           href="<?= MODULE_URL ?>&page=view&id=<?= (int) $doc['id'] ?>&v=<?= (int) $v['version'] ?>">
                            <i class="bi bi-eye"></i>
                        </a>
                        <?php if (!$isCurrent && core_can('history.restore')): ?>
                            <form method="post" class="d-inline" onsubmit="return confirm('Restaurar a versão v<?= (int) $v['version'] ?>? O conteúdo atual será preservado no histórico.')">
                                <?= Csrf::field() ?>
                                <input type="hidden" name="action" value="restore">
                                <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                                <input type="hidden" name="version" value="<?= (int) $v['version'] ?>">
                                <button class="btn btn-sm btn-outline-warning" title="Restaurar"><i class="bi bi-arrow-counterclockwise"></i></button>
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
intra_view('Histórico do documento', (string) ob_get_clean(), 'documents');
