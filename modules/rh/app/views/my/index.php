<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Minha Área — <?= Sanitize::e($hospitalName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>style.css" rel="stylesheet">
    <style>
        .my-hero { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #fff; }
        .my-photo {
            width: 96px; height: 96px; border-radius: 50%; object-fit: cover;
            border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        .score-box {
            background: #fff; border-radius: 10px; padding: 20px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.06);
        }
        .compliment-card {
            background: #fff; border-left: 4px solid #ffc107; border-radius: 6px;
            padding: 14px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .compliment-card .quote { color: #ffc107; font-size: 1.4rem; }
    </style>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <span class="navbar-brand fw-bold">
            <i class="bi bi-hospital me-1"></i> <?= Sanitize::e($hospitalName) ?>
        </span>
        <a href="<?php echo core_url('index.php?m=auth&a=logout'); ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-box-arrow-right me-1"></i> Sair
        </a>
    </div>
</nav>

<div class="my-hero py-4 mb-4">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($employee['photo'])): ?>
                <img src="<?= Sanitize::e(Upload::url($employee['photo'])) ?>" class="my-photo" alt="">
            <?php else: ?>
                <div class="my-photo bg-light d-flex align-items-center justify-content-center">
                    <i class="bi bi-person fs-1 text-primary"></i>
                </div>
            <?php endif; ?>
            <div>
                <h3 class="mb-0 fw-bold"><?= Sanitize::e($employee['full_name']) ?></h3>
                <div class="small opacity-75">
                    <?= Sanitize::e($employee['position_title'] ?? 'Funcionário') ?>
                    <?= $employee['department_name'] ? ' · ' . Sanitize::e($employee['department_name']) : '' ?>
                </div>
                <div class="small opacity-75 mt-1">
                    <i class="bi bi-calendar-check me-1"></i>
                    Admissão: <?= Sanitize::formatDate($employee['admission_date']) ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if ($flashSuccess): ?>
        <div class="alert alert-success"><?= Sanitize::e($flashSuccess) ?></div>
    <?php endif; ?>
    <?php if ($flashError): ?>
        <div class="alert alert-danger"><?= Sanitize::e($flashError) ?></div>
    <?php endif; ?>

    <!-- Pontuação -->
    <div class="row g-3 mb-4">
        <div class="col-md-6">
            <div class="score-box text-center">
                <div class="text-muted small">Minha pontuação atual</div>
                <div class="display-4 fw-bold <?= $scoresTotal >= 0 ? 'text-success' : 'text-danger' ?>">
                    <?= ($scoresTotal >= 0 ? '+' : '') . $scoresTotal ?>
                </div>
                <div class="text-muted small">pontos acumulados</div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="score-box text-center">
                <div class="text-muted small">Elogios recebidos</div>
                <div class="display-4 fw-bold text-warning">
                    <i class="bi bi-heart-fill" style="font-size:2.2rem;"></i> <?= count($compliments) ?>
                </div>
                <div class="text-muted small">no total</div>
            </div>
        </div>
    </div>

    <!-- Histórico de pontos -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-trophy me-1"></i> Histórico de pontos
        </div>
        <div class="card-body">
            <?php if (empty($scores)): ?>
                <p class="text-muted text-center py-3">Você ainda não tem pontos lançados.</p>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                        <thead class="text-muted small">
                            <tr><th>Data</th><th>Pontos</th><th>Categoria</th><th>Motivo</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($scores as $s): ?>
                            <tr>
                                <td class="text-muted small" style="white-space:nowrap"><?= Sanitize::formatDateTime($s['created_at']) ?></td>
                                <td>
                                    <strong class="<?= $s['points'] >= 0 ? 'text-success' : 'text-danger' ?>">
                                        <?= ($s['points'] >= 0 ? '+' : '') . (int)$s['points'] ?>
                                    </strong>
                                </td>
                                <td><span class="badge bg-light text-dark"><?= Sanitize::e($s['category'] ?: '-') ?></span></td>
                                <td><?= Sanitize::e($s['reason']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Elogios recebidos -->
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-heart me-1 text-warning"></i> Elogios recebidos
        </div>
        <div class="card-body">
            <?php if (empty($compliments)): ?>
                <p class="text-muted text-center py-3">Nenhum elogio registrado ainda — continue o bom trabalho!</p>
            <?php else: ?>
                <?php foreach ($compliments as $c): ?>
                    <div class="compliment-card">
                        <div class="d-flex">
                            <span class="quote me-2">“</span>
                            <div class="flex-grow-1">
                                <p class="mb-1"><?= nl2br(Sanitize::e($c['message'])) ?></p>
                                <small class="text-muted">
                                    <?= $c['compliment_from'] ? 'de <strong>' . Sanitize::e($c['compliment_from']) . '</strong> &middot; ' : '' ?>
                                    <?= Sanitize::formatDate($c['created_at']) ?>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Próximos vencimentos do funcionário -->
    <?php if (!empty($upcoming)): ?>
    <div class="card border-0 shadow-sm mb-4">
        <div class="card-header bg-white fw-semibold">
            <i class="bi bi-clock-history me-1"></i> Minhas próximas pendências
        </div>
        <div class="card-body">
            <ul class="list-group list-group-flush">
                <?php foreach ($upcoming as $u): ?>
                    <?php
                    $days = (int)(new DateTime())->diff(new DateTime($u['expiry_date']))->format('%r%a');
                    ?>
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <div>
                            <strong><?= Sanitize::e($u['title']) ?></strong>
                            <small class="text-muted d-block"><?= Sanitize::e(str_replace('_', ' ', $u['type'])) ?></small>
                        </div>
                        <span class="badge <?= $days < 0 ? 'bg-danger' : ($days <= 7 ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                            <?= Sanitize::formatDate($u['expiry_date']) ?>
                            &middot;
                            <?= $days < 0 ? 'Vencido' : 'Em ' . $days . 'd' ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <div class="text-center text-muted small mt-4">
        Precisa alterar sua senha? Acesse <a href="<?php echo core_url('index.php?m=auth&a=security'); ?>">Senha e Segurança</a>.
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
