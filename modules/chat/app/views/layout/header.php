<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::e($pageTitle ?? 'TeamChat') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= BASE_URL ?>/public/css/style.css" rel="stylesheet">
    <meta name="csrf-token" content="<?= Csrf::token() ?>">
    <meta name="base-url" content="<?= BASE_URL ?>">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark fixed-top">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="<?= BASE_URL ?>/index.php?page=chat">
                <i class="bi bi-chat-dots-fill me-2"></i>TeamChat
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarMain">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarMain">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'chat' ? 'active' : '' ?>" href="index.php?page=chat">
                            <i class="bi bi-chat-dots me-1"></i> Chat
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'tasks' ? 'active' : '' ?>" href="index.php?page=tasks">
                            <i class="bi bi-kanban me-1"></i> Tarefas
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'meetings' ? 'active' : '' ?>" href="index.php?page=meetings">
                            <i class="bi bi-calendar-event me-1"></i> Reuniões
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'teams' ? 'active' : '' ?>" href="index.php?page=teams">
                            <i class="bi bi-people me-1"></i> Equipes
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'processes' ? 'active' : '' ?>" href="index.php?page=processes">
                            <i class="bi bi-diagram-3 me-1"></i> Processos
                        </a>
                    </li>
                    <?php if (Auth::isAdmin() || Auth::isManager()): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'admin' ? 'active' : '' ?>" href="index.php?page=admin">
                            <i class="bi bi-gear me-1"></i> Admin
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="index.php?page=search">
                            <i class="bi bi-search"></i>
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i> <?= Sanitize::e(Session::userName()) ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person me-2"></i>Meu Perfil</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="index.php?page=auth&action=logout"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <main class="main-content">
        <div class="container-fluid py-4">
            <?php if ($flash = Session::flash('success')): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <i class="bi bi-check-circle me-2"></i><?= Sanitize::e($flash) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flash = Session::flash('error')): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <i class="bi bi-exclamation-triangle me-2"></i><?= Sanitize::e($flash) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
