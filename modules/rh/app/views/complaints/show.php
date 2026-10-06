<?php /** Denúncia — apuração. Variáveis: $c, $handler, $messages. */
$canRespond = core_can('complaints.respond');
$fechada = in_array($c['status'], ['concluida', 'arquivada'], true);
?>
<div class="page-header">
    <h1><i class="bi bi-shield-exclamation me-2"></i>Denúncia <code><?= Sanitize::e($c['protocol']) ?></code>
        <span class="badge <?= Complaint::badge($c['status']) ?> fs-6 align-middle ms-2"><?= Sanitize::e(Complaint::STATUS_LABELS[$c['status']] ?? $c['status']) ?></span>
    </h1>
    <a href="index.php?m=rh&page=complaints" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Voltar</a>
</div>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-file-text me-1"></i> Relato</div>
            <div class="card-body">
                <div class="row small mb-3">
                    <div class="col-sm-6"><span class="text-muted">Categoria:</span> <?= Sanitize::e(Complaint::CATEGORIES[$c['category']] ?? $c['category']) ?></div>
                    <div class="col-sm-6"><span class="text-muted">Aberta em:</span> <?= Sanitize::formatDate(substr((string) $c['created_at'], 0, 10)) ?></div>
                    <?php if ($c['occurred_at']): ?><div class="col-sm-6"><span class="text-muted">Ocorreu em:</span> <?= Sanitize::formatDate($c['occurred_at']) ?></div><?php endif; ?>
                    <?php if ($c['location']): ?><div class="col-sm-6"><span class="text-muted">Local:</span> <?= Sanitize::e($c['location']) ?></div><?php endif; ?>
                    <?php if ($c['involved']): ?><div class="col-12"><span class="text-muted">Envolvidos:</span> <?= Sanitize::e($c['involved']) ?></div><?php endif; ?>
                    <?php if ($c['contact']): ?><div class="col-12"><span class="text-muted">Identificação voluntária:</span> <?= Sanitize::e($c['contact']) ?></div>
                    <?php else: ?><div class="col-12 text-muted"><i class="bi bi-incognito me-1"></i>Denunciante anônimo.</div><?php endif; ?>
                </div>
                <h6 class="fw-semibold"><?= Sanitize::e($c['subject']) ?></h6>
                <div class="border rounded p-3 bg-light" style="white-space:pre-wrap"><?= Sanitize::e($c['body']) ?></div>
                <?php if (!empty($c['attachment_path'])): ?>
                    <a class="btn btn-outline-secondary btn-sm mt-2" href="index.php?m=rh&page=files&action=get&type=complaint&id=<?= (int) $c['id'] ?>"><i class="bi bi-paperclip me-1"></i> Baixar anexo</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-chat-dots me-1"></i> Conversa pelo protocolo</div>
            <div class="card-body">
                <?php if (empty($messages)): ?>
                    <p class="text-muted small mb-0">Nenhuma mensagem. Use este espaço para pedir detalhes ao denunciante — ele lê ao consultar o protocolo.</p>
                <?php else: ?>
                    <?php foreach ($messages as $m): $daComissao = $m['author'] === 'comissao'; ?>
                        <div class="mb-2 p-2 rounded <?= $daComissao ? 'bg-primary bg-opacity-10 ms-4' : 'bg-light me-4' ?>">
                            <div class="small text-muted"><?= $daComissao ? '<i class="bi bi-person-badge me-1"></i>' . Sanitize::e($m['user_name'] ?? 'Comissão') : '<i class="bi bi-incognito me-1"></i>Denunciante' ?> · <?= Sanitize::formatDateTime($m['created_at']) ?></div>
                            <div style="white-space:pre-wrap"><?= Sanitize::e($m['body']) ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($canRespond && !$fechada): ?>
                <form method="POST" action="index.php?m=rh&page=complaints&action=message" class="mt-3">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <textarea name="body" class="form-control form-control-sm" rows="3" maxlength="5000" required placeholder="Mensagem ao denunciante (ex.: pedir mais detalhes, informar andamento)"></textarea>
                    <button class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-send me-1"></i> Enviar ao denunciante</button>
                </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-clipboard-check me-1"></i> Apuração</div>
            <div class="card-body">
                <form method="POST" action="index.php?m=rh&page=complaints&action=update">
                    <?= Csrf::field() ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Status</label>
                        <select name="status" class="form-select form-select-sm" <?= $canRespond ? '' : 'disabled' ?>>
                            <?php foreach (Complaint::STATUS_LABELS as $k => $lbl): ?><option value="<?= $k ?>" <?= $c['status'] === $k ? 'selected' : '' ?>><?= Sanitize::e($lbl) ?></option><?php endforeach; ?>
                        </select>
                        <?php if ($handler): ?><div class="form-text">Responsável atual: <?= Sanitize::e($handler) ?></div><?php endif; ?>
                    </div>
                    <div class="mb-2">
                        <label class="form-label small fw-semibold">Notas internas <span class="text-muted fw-normal">(só a comissão vê)</span></label>
                        <textarea name="internal_notes" class="form-control form-control-sm" rows="6" maxlength="10000" <?= $canRespond ? '' : 'disabled' ?>><?= Sanitize::e($c['internal_notes'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Conclusão ao denunciante <span class="text-muted fw-normal">(visível pelo protocolo)</span></label>
                        <textarea name="response" class="form-control form-control-sm" rows="4" maxlength="5000" <?= $canRespond ? '' : 'disabled' ?>><?= Sanitize::e($c['response'] ?? '') ?></textarea>
                    </div>
                    <?php if ($canRespond): ?>
                        <button class="btn btn-primary btn-sm w-100"><i class="bi bi-check-lg me-1"></i> Salvar apuração</button>
                    <?php else: ?>
                        <p class="small text-muted mb-0">Você pode ver, mas não apurar (permissão <code>complaints.respond</code>).</p>
                    <?php endif; ?>
                </form>
                <?php if ($c['closed_at']): ?><div class="small text-muted mt-2">Encerrada em <?= Sanitize::formatDateTime($c['closed_at']) ?>.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>
