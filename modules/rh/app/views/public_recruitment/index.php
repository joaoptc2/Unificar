<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Vagas Disponíveis - RH Hospital</title>
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
            <a href="index.php?page=public_recruitment&action=track" class="btn btn-outline-light btn-sm">
                <i class="bi bi-search me-1"></i> Acompanhar Candidatura
            </a>
        </div>
    </nav>

    <div class="container py-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger"><?= Sanitize::e($error) ?></div>
        <?php endif; ?>

        <h2 class="fw-bold mb-4">Vagas Disponíveis</h2>

        <?php if (empty($jobs)): ?>
            <div class="card border-0 shadow-sm">
                <div class="card-body text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    Nenhuma vaga disponível no momento.
                </div>
            </div>
        <?php else: ?>
            <div class="row g-3">
                <?php foreach ($jobs as $job): ?>
                    <div class="col-md-6">
                        <div class="card border-0 shadow-sm h-100">
                            <div class="card-body">
                                <h5 class="fw-bold"><?= Sanitize::e($job['title']) ?></h5>
                                <p class="text-muted small mb-2">
                                    <i class="bi bi-building me-1"></i><?= Sanitize::e($job['department_name'] ?? 'Geral') ?>
                                </p>
                                <?php if ($job['description']): ?>
                                    <p class="small"><?= nl2br(Sanitize::e($job['description'])) ?></p>
                                <?php endif; ?>
                                <?php if ($job['requirements']): ?>
                                    <p class="small"><strong>Requisitos:</strong><br><?= nl2br(Sanitize::e($job['requirements'])) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="card-footer bg-transparent">
                                <a href="index.php?page=public_recruitment&action=apply&job_id=<?= $job['id'] ?>"
                                   class="btn btn-primary w-100">
                                    <i class="bi bi-send me-1"></i> Candidatar-se
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
