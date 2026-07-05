<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acompanhar Candidatura - RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>css/style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php?page=public_recruitment">
                <i class="bi bi-hospital me-1"></i> RH Hospital — Trabalhe Conosco
            </a>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-search me-1"></i> Acompanhar Candidatura
                    </div>
                    <div class="card-body">
                        <form method="GET">
                            <input type="hidden" name="page" value="public_recruitment">
                            <input type="hidden" name="action" value="track">
                            <div class="input-group">
                                <input type="text" name="token" class="form-control"
                                       placeholder="Insira seu código de acompanhamento"
                                       value="<?= Sanitize::e($token ?? '') ?>">
                                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if ($token && !$candidate): ?>
                    <div class="alert alert-warning">Candidatura não encontrada. Verifique o código.</div>
                <?php elseif ($candidate): ?>
                    <div class="card border-0 shadow-sm">
                        <div class="card-body">
                            <h5 class="fw-bold"><?= Sanitize::e($candidate['full_name']) ?></h5>
                            <p class="text-muted mb-1">Vaga: <?= Sanitize::e($candidate['job_title']) ?></p>
                            <p class="mb-3">
                                Status:
                                <span class="badge <?= match($candidate['status']) {
                                    'inscrito' => 'bg-info',
                                    'em_andamento' => 'bg-primary',
                                    'aprovado' => 'bg-success',
                                    'reprovado' => 'bg-danger',
                                    default => 'bg-secondary'
                                } ?>">
                                    <?= ucfirst(str_replace('_', ' ', $candidate['status'])) ?>
                                </span>
                                <?php if ($candidate['current_step_name']): ?>
                                    — Etapa: <strong><?= Sanitize::e($candidate['current_step_name']) ?></strong>
                                <?php endif; ?>
                            </p>

                            <?php if (!empty($progress)): ?>
                                <h6 class="fw-semibold">Progresso</h6>
                                <div class="timeline">
                                    <?php foreach ($progress as $p): ?>
                                        <div class="d-flex mb-2">
                                            <div class="me-3" style="min-width:80px;">
                                                <small class="text-muted"><?= date('d/m/Y', strtotime($p['created_at'])) ?></small>
                                            </div>
                                            <div class="border-start border-2 border-primary ps-3">
                                                <strong><?= Sanitize::e($p['step_name']) ?></strong>
                                                <span class="badge bg-light text-dark ms-1"><?= ucfirst($p['status']) ?></span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-footer bg-light small">
                            <details>
                                <summary class="text-danger" style="cursor:pointer">
                                    <i class="bi bi-shield-lock me-1"></i> Direito ao esquecimento (LGPD)
                                </summary>
                                <p class="mt-2 mb-2">
                                    Você pode solicitar a exclusão da sua candidatura e dos dados pessoais
                                    (incluindo currículo) a qualquer momento. Esta ação é irreversível.
                                </p>
                                <form method="POST" action="index.php?page=privacy&action=delete_candidate">
                                    <?= Csrf::field() ?>
                                    <input type="hidden" name="token" value="<?= Sanitize::e($token) ?>">
                                    <button type="submit" class="btn btn-outline-danger btn-sm"
                                            data-confirm="Tem certeza? Esta ação remove permanentemente sua candidatura e dados pessoais.">
                                        <i class="bi bi-trash me-1"></i> Excluir minha candidatura
                                    </button>
                                </form>
                            </details>
                        </div>
                    </div>
                <?php endif; ?>

                <?php $flashSuccess = Session::flash('success'); $flashError = Session::flash('error'); ?>
                <?php if ($flashSuccess): ?><div class="alert alert-success mt-3"><?= Sanitize::e($flashSuccess) ?></div><?php endif; ?>
                <?php if ($flashError): ?><div class="alert alert-danger mt-3"><?= Sanitize::e($flashError) ?></div><?php endif; ?>

                <div class="text-center mt-3">
                    <a href="index.php?page=public_recruitment" class="text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i> Ver vagas disponíveis
                    </a>
                    &middot;
                    <a href="index.php?page=privacy" class="text-decoration-none">
                        <i class="bi bi-shield-lock me-1"></i> Política de Privacidade
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= ASSET_URL ?>js/app.js"></script>
</body>
</html>
