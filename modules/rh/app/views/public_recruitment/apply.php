<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Candidatar-se — <?= Sanitize::e($job['title']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>style.css" rel="stylesheet">
</head>
<body class="bg-light">
    <nav class="navbar navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand fw-bold" href="index.php?m=rh&page=public_recruitment">
                <i class="bi bi-hospital me-1"></i> RH Hospital — Trabalhe Conosco
            </a>
        </div>
    </nav>

    <div class="container py-4">
        <div class="row justify-content-center">
            <div class="col-md-8">
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success"><?= Sanitize::e($success) ?></div>
                <?php endif; ?>
                <?php if (!empty($error)): ?>
                    <div class="alert alert-danger"><?= Sanitize::e($error) ?></div>
                <?php endif; ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h4 class="fw-bold"><?= Sanitize::e($job['title']) ?></h4>
                        <p class="text-muted"><i class="bi bi-building me-1"></i><?= Sanitize::e($job['department_name'] ?? 'Geral') ?></p>
                        <?php if ($job['description']): ?>
                            <p><?= nl2br(Sanitize::e($job['description'])) ?></p>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-header bg-white fw-semibold">
                        <i class="bi bi-person-plus me-1"></i> Formulário de Inscrição
                    </div>
                    <div class="card-body">
                        <form method="POST" action="index.php?m=rh&page=public_recruitment&action=submit" enctype="multipart/form-data">
                            <?= Csrf::field() ?>
                            <input type="hidden" name="job_id" value="<?= $job['id'] ?>">
                            <input type="hidden" name="form_ts" value="<?= time() ?>">
                            <!-- Honeypot: campo oculto para humanos, mas bots tendem a preencher.
                                 Se vier preenchido, descartamos a submissão silenciosamente. -->
                            <div aria-hidden="true" style="position:absolute; left:-9999px; top:-9999px; height:0; overflow:hidden;">
                                <label>Não preencha este campo
                                    <input type="text" name="website" tabindex="-1" autocomplete="off">
                                </label>
                            </div>

                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label required">Nome Completo</label>
                                    <input type="text" name="full_name" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label required">E-mail</label>
                                    <input type="email" name="email" class="form-control" required>
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Telefone</label>
                                    <input type="text" name="phone" class="form-control" data-mask="phone">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">CPF</label>
                                    <input type="text" name="cpf" class="form-control" data-mask="cpf">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Área de Atuação</label>
                                    <input type="text" name="area" class="form-control" placeholder="Ex: Enfermagem">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Experiência Profissional</label>
                                    <textarea name="experience" class="form-control" rows="4" placeholder="Descreva sua experiência..."></textarea>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Currículo</label>
                                    <input type="file" name="resume" class="form-control" accept=".pdf,.doc,.docx">
                                    <small class="text-muted">Formatos: PDF, DOC, DOCX. Máximo: 5MB.</small>
                                </div>
                                <div class="col-12">
                                    <div class="form-check border rounded p-3 bg-light">
                                        <input class="form-check-input" type="checkbox" name="lgpd_consent" id="lgpd_consent" value="1" required>
                                        <label class="form-check-label small" for="lgpd_consent">
                                            Li e concordo com a
                                            <a href="index.php?m=rh&page=privacy" target="_blank">Política de Privacidade</a>
                                            e autorizo o tratamento dos meus dados pessoais para fins de processo seletivo,
                                            nos termos da Lei Geral de Proteção de Dados (LGPD).
                                        </label>
                                    </div>
                                </div>
                                <div class="col-12">
                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="bi bi-send me-1"></i> Enviar Candidatura
                                    </button>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="index.php?m=rh&page=public_recruitment" class="text-decoration-none">
                        <i class="bi bi-arrow-left me-1"></i> Ver outras vagas
                    </a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="<?= ASSET_URL ?>app.js"></script>
</body>
</html>
