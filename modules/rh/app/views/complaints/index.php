<?php /** Canal de denúncias — lista da comissão. Variáveis: $items, $status, $category. */ ?>
<div class="page-header">
    <h1><i class="bi bi-shield-exclamation me-2"></i>Canal de denúncias</h1>
    <a href="index.php?m=rh&page=complaint_track" class="btn btn-outline-secondary btn-sm" target="_blank"><i class="bi bi-box-arrow-up-right me-1"></i> Página pública de acompanhamento</a>
</div>
<div class="alert alert-light border small">
    <i class="bi bi-incognito me-1"></i> As denúncias são <strong>anônimas por construção</strong>: o sistema não guarda quem abriu (nem usuário, nem IP). A conversa com o denunciante acontece pelo protocolo. Trate o conteúdo como sigiloso.
</div>
<div class="filter-panel">
    <form class="row g-2 align-items-end"><input type="hidden" name="m" value="rh"><input type="hidden" name="page" value="complaints">
        <div class="col-md-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select form-select-sm"><option value="">Todos</option><?php foreach (Complaint::STATUS_LABELS as $s => $lbl): ?><option value="<?= $s ?>" <?= ($status ?? '') === $s ? 'selected' : '' ?>><?= Sanitize::e($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Categoria</label>
            <select name="category" class="form-select form-select-sm"><option value="">Todas</option><?php foreach (Complaint::CATEGORIES as $k => $lbl): ?><option value="<?= $k ?>" <?= ($category ?? '') === $k ? 'selected' : '' ?>><?= Sanitize::e($lbl) ?></option><?php endforeach; ?></select>
        </div>
        <div class="col-md-2"><button type="submit" class="btn btn-primary btn-sm w-100">Filtrar</button></div>
    </form>
</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-sm table-hover mb-0 align-middle">
<thead class="table-light"><tr><th>Protocolo</th><th>Categoria</th><th>Assunto</th><th>Status</th><th>Responsável</th><th>Aberta em</th><th class="text-end"></th></tr></thead>
<tbody>
<?php foreach ($items as $c): ?>
<tr class="<?= $c['status'] === 'nova' ? 'fw-semibold' : '' ?>">
    <td class="text-nowrap"><code><?= Sanitize::e($c['protocol']) ?></code>
        <?php if ((int) $c['unread_from_reporter'] > 0): ?><span class="badge bg-danger ms-1" title="Mensagem nova do denunciante"><i class="bi bi-chat-dots"></i></span><?php endif; ?>
        <?php if (!empty($c['attachment_path'])): ?><i class="bi bi-paperclip text-muted ms-1" title="Com anexo"></i><?php endif; ?>
    </td>
    <td><span class="badge bg-light text-dark border"><?= Sanitize::e(Complaint::CATEGORIES[$c['category']] ?? $c['category']) ?></span></td>
    <td><?= Sanitize::e($c['subject']) ?><?php if ((int) $c['messages'] > 0): ?><small class="text-muted d-block"><?= (int) $c['messages'] ?> mensagem(ns)</small><?php endif; ?></td>
    <td><span class="badge <?= Complaint::badge($c['status']) ?>"><?= Sanitize::e(Complaint::STATUS_LABELS[$c['status']] ?? $c['status']) ?></span></td>
    <td class="small text-muted"><?= $c['handled_by_name'] ? Sanitize::e($c['handled_by_name']) : '—' ?></td>
    <td class="small text-muted text-nowrap"><?= Sanitize::formatDate(substr((string) $c['created_at'], 0, 10)) ?></td>
    <td class="text-end"><a href="index.php?m=rh&page=complaints&action=show&id=<?= (int) $c['id'] ?>" class="btn btn-outline-primary btn-action" title="Abrir"><i class="bi bi-folder2-open"></i></a></td>
</tr>
<?php endforeach; ?>
<?php if (empty($items)): ?><tr><td colspan="7" class="text-center text-muted py-4">Nenhuma denúncia.</td></tr><?php endif; ?>
</tbody>
</table></div></div></div>
