<?php
/**
 * Portal do funcionário — cabeçalho compartilhado (standalone, sem o menu
 * da plataforma). Espera: $hospitalName, $employee, $flashSuccess,
 * $flashError, $unread. Opcionais: $portalTitle, $compact (sem hero grande).
 */
$portalTitle = $portalTitle ?? 'Minha Área';
$compact     = !empty($compact);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::e($portalTitle) ?> — <?= Sanitize::e($hospitalName) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>style.css" rel="stylesheet">
    <style>
        .my-hero { background: linear-gradient(135deg, #0d6efd 0%, #6610f2 100%); color: #fff; }
        .my-photo { width: 96px; height: 96px; border-radius: 50%; object-fit: cover; border: 3px solid #fff; box-shadow: 0 2px 8px rgba(0,0,0,.15); }
        .my-photo-sm { width: 44px; height: 44px; }
        .score-box { background: #fff; border-radius: 10px; padding: 20px; box-shadow: 0 2px 6px rgba(0,0,0,.06); }
        .compliment-card { background: #fff; border-left: 4px solid #ffc107; border-radius: 6px; padding: 14px 18px; margin-bottom: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.05); }
        .compliment-card .quote { color: #ffc107; font-size: 1.4rem; }
        .my-tabs { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }
        .my-tabs a { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 999px; background: #fff; color: #444; text-decoration: none; font-weight: 500; box-shadow: 0 1px 3px rgba(0,0,0,.06); border: 1px solid transparent; }
        .my-tabs a.active { background: #0d6efd; color: #fff; }
        .my-tabs a .badge { font-size: .7rem; }
        .my-pane { display: none; }
        .my-pane.active { display: block; }
        .ann-card.unread { border-left: 4px solid #0d6efd; }
        .ann-cover { width: 100%; max-height: 240px; object-fit: cover; border-radius: 8px; }
        .reward-img { width: 100%; height: 140px; object-fit: cover; border-radius: 8px 8px 0 0; background: #f1f3f5; }
        .reward-placeholder { height: 140px; display: flex; align-items: center; justify-content: center; background: #f1f3f5; border-radius: 8px 8px 0 0; color: #adb5bd; font-size: 2.5rem; }
        .star-rating { display: inline-flex; flex-direction: row-reverse; gap: 2px; }
        .star-rating input { display: none; }
        .star-rating label { font-size: 1.7rem; color: #ced4da; cursor: pointer; line-height: 1; }
        .star-rating input:checked ~ label, .star-rating label:hover, .star-rating label:hover ~ label { color: #ffc107; }
        .scale-options { display: flex; flex-wrap: wrap; gap: 6px; }
        .scale-options label { min-width: 40px; text-align: center; }
        .doc-body img { max-width: 100%; height: auto; }
        .doc-body table { border-collapse: collapse; width: 100%; }
        .doc-body td, .doc-body th { border: 1px solid #ccc; padding: 4px 6px; }
        .ql-align-center { text-align: center; } .ql-align-right { text-align: right; } .ql-align-justify { text-align: justify; }
    </style>
    <?= $extraHead ?? '' ?>
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark">
    <div class="container">
        <a class="navbar-brand fw-bold text-white text-decoration-none" href="index.php?m=rh&page=my">
            <i class="bi bi-hospital me-1"></i> <?= Sanitize::e($hospitalName) ?>
        </a>
        <div class="d-flex align-items-center gap-2">
            <a href="<?= core_url('index.php?m=auth&a=notifications') ?>" class="btn btn-outline-light btn-sm position-relative" title="Notificações">
                <i class="bi bi-bell"></i>
                <?php if (!empty($unread)): ?>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"><?= (int)$unread ?></span>
                <?php endif; ?>
            </a>
            <?php if (core_can('dashboard.view')): ?>
                <a href="index.php?m=rh&page=dashboard" class="btn btn-outline-light btn-sm"><i class="bi bi-speedometer2 me-1"></i> Painel RH</a>
            <?php endif; ?>
            <a href="<?= core_url('index.php') ?>" class="btn btn-outline-light btn-sm" title="Plataforma"><i class="bi bi-grid"></i></a>
            <a href="<?= core_url('index.php?m=auth&a=logout') ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i> Sair
            </a>
        </div>
    </div>
</nav>

<div class="my-hero <?= $compact ? 'py-3' : 'py-4' ?> mb-4">
    <div class="container">
        <div class="d-flex align-items-center gap-3">
            <?php if (!empty($employee['photo'])): ?>
                <img src="<?= Sanitize::e(Upload::publicUrl($employee['photo'])) ?>" class="my-photo <?= $compact ? 'my-photo-sm' : '' ?>" alt="">
            <?php else: ?>
                <div class="my-photo <?= $compact ? 'my-photo-sm' : '' ?> bg-light d-flex align-items-center justify-content-center">
                    <i class="bi bi-person <?= $compact ? 'fs-4' : 'fs-1' ?> text-primary"></i>
                </div>
            <?php endif; ?>
            <div>
                <h3 class="mb-0 fw-bold <?= $compact ? 'h5' : '' ?>"><?= Sanitize::e($employee['full_name']) ?></h3>
                <div class="small opacity-75">
                    <?= Sanitize::e($employee['position_title'] ?? 'Funcionário') ?>
                    <?= !empty($employee['department_name']) ? ' · ' . Sanitize::e($employee['department_name']) : '' ?>
                </div>
                <?php if (!$compact): ?>
                    <div class="small opacity-75 mt-1">
                        <i class="bi bi-calendar-check me-1"></i>
                        Admissão: <?= Sanitize::formatDate($employee['admission_date']) ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="container pb-5">
    <?php if (!empty($flashSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show"><?= $flashSuccess ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($flashError)): ?>
        <div class="alert alert-danger alert-dismissible fade show"><?= $flashError ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
