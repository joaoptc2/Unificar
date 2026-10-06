<?php /** PÚBLICA — acompanhar denúncia pelo protocolo + chave. Variáveis: $hospitalName, $c, $messages, $protocol, $key, $erro, $ok. */ ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Acompanhar denúncia — <?= Sanitize::e($hospitalName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark bg-dark"><div class="container"><span class="navbar-brand fw-bold"><i class="bi bi-shield-exclamation me-1"></i> <?= Sanitize::e($hospitalName) ?> — Canal de denúncias</span></div></nav>
<div class="container py-4">
<div class="row justify-content-center"><div class="col-lg-7">
    <?php if ($erro): ?><div class="alert alert-danger"><?= Sanitize::e($erro) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="alert alert-success"><?= Sanitize::e($ok) ?></div><?php endif; ?>

    <?php if (!$c): ?>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-search me-1"></i> Acompanhar denúncia</div>
        <div class="card-body">
            <p class="small text-muted">Informe o protocolo e a chave recebidos ao abrir a denúncia. Esta consulta não exige login e não registra quem consultou.</p>
            <form method="POST" autocomplete="off">
                <?= Csrf::field() ?>
                <div class="mb-2"><label class="form-label small">Protocolo</label><input type="text" name="protocol" class="form-control" placeholder="DEN-2026-XXXXXX" value="<?= Sanitize::e($protocol) ?>" required></div>
                <div class="mb-3"><label class="form-label small">Chave</label><input type="password" name="key" class="form-control" placeholder="32 caracteres" required></div>
                <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i> Consultar</button>
            </form>
        </div>
    </div>
    <?php else: ?>
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Protocolo <code><?= Sanitize::e($c['protocol']) ?></code></span>
            <span class="badge <?= Complaint::badge($c['status']) ?>"><?= Sanitize::e(Complaint::STATUS_LABELS[$c['status']] ?? $c['status']) ?></span>
        </div>
        <div class="card-body">
            <div class="small text-muted mb-2">Aberta em <?= Sanitize::formatDate(substr((string) $c['created_at'], 0, 10)) ?> · <?= Sanitize::e(Complaint::CATEGORIES[$c['category']] ?? '') ?></div>
            <h6 class="fw-semibold"><?= Sanitize::e($c['subject']) ?></h6>
            <?php if (!empty($c['response'])): ?>
                <div class="alert alert-success mt-3 mb-0"><strong>Conclusão da comissão:</strong><br><span style="white-space:pre-wrap"><?= Sanitize::e($c['response']) ?></span></div>
            <?php elseif (in_array($c['status'], ['nova','em_apuracao'], true)): ?>
                <div class="small text-muted">Ainda sem conclusão. Volte a consultar mais tarde.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-white fw-semibold"><i class="bi bi-chat-dots me-1"></i> Mensagens</div>
        <div class="card-body">
            <?php if (empty($messages)): ?><p class="text-muted small mb-0">Nenhuma mensagem.</p><?php endif; ?>
            <?php foreach ($messages as $m): $daComissao = $m['author'] === 'comissao'; ?>
                <div class="mb-2 p-2 rounded <?= $daComissao ? 'bg-primary bg-opacity-10 me-4' : 'bg-light ms-4' ?>">
                    <div class="small text-muted"><?= $daComissao ? 'Comissão' : 'Você' ?> · <?= Sanitize::formatDateTime($m['created_at']) ?></div>
                    <div style="white-space:pre-wrap"><?= Sanitize::e($m['body']) ?></div>
                </div>
            <?php endforeach; ?>
            <?php if (!in_array($c['status'], ['concluida','arquivada'], true)): ?>
            <form method="POST" class="mt-3" autocomplete="off">
                <?= Csrf::field() ?>
                <input type="hidden" name="protocol" value="<?= Sanitize::e($c['protocol']) ?>">
                <input type="hidden" name="key" value="<?= Sanitize::e($key) ?>">
                <textarea name="reply" class="form-control form-control-sm" rows="3" maxlength="5000" required placeholder="Acrescentar informações ou responder à comissão"></textarea>
                <button class="btn btn-outline-primary btn-sm mt-2"><i class="bi bi-send me-1"></i> Enviar</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <div class="text-center mt-3"><a href="index.php?m=rh&page=complaint_track" class="small">Consultar outro protocolo</a></div>
    <?php endif; ?>
</div></div>
</div>
</body>
</html>
