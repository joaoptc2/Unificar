<!DOCTYPE html>
<html lang="pt-BR" data-bs-theme="<?= Session::get('theme_preference', 'light') ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= Sanitize::e($pageTitle ?? 'RH Hospital') ?> - RH Hospital</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?= ASSET_URL ?>css/style.css" rel="stylesheet">
    <?php
    // CSS dinâmico: sobrescreve variáveis e cores com a personalização do admin.
    $theme = SettingsController::theme();
    ?>
    <style>
    :root {
        --primary-color: <?= Sanitize::e($theme['theme_primary']) ?>;
        --sidebar-bg: <?= Sanitize::e($theme['theme_sidebar_bg']) ?>;
        --sidebar-text: <?= Sanitize::e($theme['theme_sidebar_text']) ?>;
        --sidebar-hover-bg: <?= Sanitize::e($theme['theme_sidebar_hover']) ?>;
    }
    body { background-color: <?= Sanitize::e($theme['theme_page_bg']) ?>; font-family: <?= $theme['theme_font_family'] ?>; }
    .navbar.bg-primary, .navbar-dark.bg-primary { background-color: <?= Sanitize::e($theme['theme_navbar_bg']) ?> !important; }
    .btn-primary { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; border-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .btn-primary:hover { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; filter: brightness(0.9); border-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .btn-outline-primary { color: <?= Sanitize::e($theme['theme_primary']) ?>; border-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .btn-outline-primary:hover { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; border-color: <?= Sanitize::e($theme['theme_primary']) ?>; color: #fff; }
    .text-primary { color: <?= Sanitize::e($theme['theme_primary']) ?> !important; }
    .bg-primary { background-color: <?= Sanitize::e($theme['theme_primary']) ?> !important; }
    a { color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .stat-card, .card { border-radius: <?= Sanitize::e($theme['theme_border_radius']) ?>rem; }
    .stat-card .stat-icon { border-radius: <?= Sanitize::e($theme['theme_border_radius']) ?>rem; }
    .badge-ativo, .badge-valido { background-color: <?= Sanitize::e($theme['theme_badge_ativo']) ?>; }
    .badge-afastado, .badge-proximo { background-color: <?= Sanitize::e($theme['theme_badge_alerta']) ?>; }
    .badge-desligado, .badge-vencido { background-color: <?= Sanitize::e($theme['theme_badge_perigo']) ?>; }
    .login-icon { background: linear-gradient(135deg, <?= Sanitize::e($theme['theme_login_gradient_start']) ?>, <?= Sanitize::e($theme['theme_login_gradient_end']) ?>); }
    .sidebar-nav .nav-link.active { border-left-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .page-item.active .page-link { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; border-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .form-check-input:checked { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; border-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    .border-primary { border-color: <?= Sanitize::e($theme['theme_primary']) ?> !important; }
    .progress-bar { background-color: <?= Sanitize::e($theme['theme_primary']) ?>; }
    </style>
    <?php if (!empty($extraCss)): ?>
        <?php foreach ($extraCss as $css): ?>
            <link href="<?= $css ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Navbar superior -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top shadow-sm">
        <div class="container-fluid">
            <a class="navbar-brand fw-bold" href="index.php?page=dashboard">
                <i class="bi <?= Sanitize::e($theme['theme_logo_icon'] ?? 'bi-hospital') ?> me-1"></i>
                <?= Sanitize::e($theme['app_name'] ?? 'RH Hospital') ?>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebar">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="d-none d-lg-flex align-items-center ms-auto">
                <!-- Busca global -->
                <button type="button" class="btn btn-sm btn-light text-muted me-3 px-3"
                        data-bs-toggle="modal" data-bs-target="#globalSearchModal"
                        title="Buscar (Ctrl+K)">
                    <i class="bi bi-search me-1"></i>
                    <span class="d-none d-xl-inline">Buscar</span>
                    <kbd class="ms-2 small bg-secondary text-white px-1 rounded">Ctrl+K</kbd>
                </button>
                <!-- Modo escuro -->
                <button type="button" id="themeToggle" class="btn btn-sm btn-link text-white me-2 p-1" title="Alternar tema">
                    <i class="bi bi-moon-stars fs-5" id="themeIcon"></i>
                </button>
                <!-- Notificações -->
                <div class="dropdown me-3">
                    <a class="nav-link text-white position-relative" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-bell fs-5"></i>
                        <?php
                        $unreadCount = 0;
                        try {
                            $db = Database::getInstance();
                            $stmt = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
                            $stmt->execute([Session::userId()]);
                            $unreadCount = (int)$stmt->fetchColumn();
                        } catch (Exception $e) {}
                        ?>
                        <?php if ($unreadCount > 0): ?>
                            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size:0.6rem;">
                                <?= $unreadCount > 99 ? '99+' : $unreadCount ?>
                            </span>
                        <?php endif; ?>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end shadow" style="width:320px; max-height:400px; overflow-y:auto;">
                        <h6 class="dropdown-header">Notificações</h6>
                        <?php
                        try {
                            $stmt = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 10');
                            $stmt->execute([Session::userId()]);
                            $notifs = $stmt->fetchAll();
                            if (empty($notifs)):
                        ?>
                            <div class="px-3 py-2 text-muted small">Nenhuma notificação.</div>
                        <?php else: foreach ($notifs as $n): ?>
                            <a class="dropdown-item small <?= $n['is_read'] ? '' : 'fw-bold bg-light' ?>"
                               href="index.php?page=notifications&action=read&id=<?= $n['id'] ?>">
                                <div class="text-truncate"><?= Sanitize::e($n['title']) ?></div>
                                <small class="text-muted"><?= Sanitize::formatDateTime($n['created_at']) ?></small>
                            </a>
                        <?php endforeach; endif; } catch (Exception $e) {} ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-center small" href="index.php?page=notifications">Ver todas</a>
                    </div>
                </div>

                <!-- Utilizador -->
                <div class="dropdown">
                    <a class="nav-link text-white dropdown-toggle" href="#" data-bs-toggle="dropdown">
                        <i class="bi bi-person-circle me-1"></i>
                        <?= Sanitize::e(Session::userName()) ?>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end shadow">
                        <li><span class="dropdown-item-text small text-muted"><?= Sanitize::e(ucfirst(Session::userRole())) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="index.php?page=profile"><i class="bi bi-person me-2"></i>Meu perfil</a></li>
                        <li><a class="dropdown-item text-danger" href="index.php?page=logout&action=logout"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <!-- Sidebar -->
    <div class="offcanvas-lg offcanvas-start sidebar" tabindex="-1" id="sidebar">
        <div class="offcanvas-header d-lg-none">
            <h5 class="offcanvas-title">Menu</h5>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#sidebar"></button>
        </div>
        <div class="offcanvas-body p-0">
            <nav class="sidebar-nav">
                <ul class="nav flex-column">
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'dashboard' ? 'active' : '' ?>" href="index.php?page=dashboard">
                            <i class="bi bi-speedometer2 me-2"></i> Dashboard
                        </a>
                    </li>

                    <?php if (Auth::can('employees', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'employees' ? 'active' : '' ?>" href="index.php?page=employees">
                            <i class="bi bi-people me-2"></i> Funcionários
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('expirations', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'expirations' ? 'active' : '' ?>" href="index.php?page=expirations">
                            <i class="bi bi-clock-history me-2"></i> Vencimentos
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('vacations', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'vacations' ? 'active' : '' ?>" href="index.php?page=vacations">
                            <i class="bi bi-sun me-2"></i> Ferias
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('shifts', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'shifts' ? 'active' : '' ?>" href="index.php?page=shifts">
                            <i class="bi bi-calendar2-week me-2"></i> Escalas
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('schedules', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'schedules' ? 'active' : '' ?>" href="index.php?page=schedules">
                            <i class="bi bi-calendar3 me-2"></i> Agenda
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('trainings', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'trainings' ? 'active' : '' ?>" href="index.php?page=trainings">
                            <i class="bi bi-mortarboard me-2"></i> Treinamentos
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('birthdays', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'birthdays' ? 'active' : '' ?>" href="index.php?page=birthdays">
                            <i class="bi bi-gift me-2"></i> Aniversariantes
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('recruitment', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'recruitment' ? 'active' : '' ?>" href="index.php?page=recruitment">
                            <i class="bi bi-briefcase me-2"></i> Processos Seletivos
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('talent_pool', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'talent_pool' ? 'active' : '' ?>" href="index.php?page=talent_pool">
                            <i class="bi bi-star me-2"></i> Banco de Talentos
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('announcements', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'announcements' ? 'active' : '' ?>" href="index.php?page=announcements">
                            <i class="bi bi-megaphone me-2"></i> Comunicados
                        </a>
                    </li>
                    <?php endif; ?>

                    <?php if (Auth::can('surveys', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'surveys' ? 'active' : '' ?>" href="index.php?page=surveys">
                            <i class="bi bi-clipboard-data me-2"></i> Pesquisas
                        </a>
                    </li>
                    <?php endif; ?>

                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'notifications' ? 'active' : '' ?>" href="index.php?page=notifications">
                            <i class="bi bi-bell me-2"></i> Notificações
                            <?php if ($unreadCount > 0): ?>
                                <span class="badge bg-danger ms-1"><?= $unreadCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>

                    <?php if (Auth::isAdmin()): ?>
                    <li class="nav-header mt-3 mb-1"><small class="text-muted fw-bold px-3">ADMINISTRAÇÃO</small></li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'users' ? 'active' : '' ?>" href="index.php?page=users">
                            <i class="bi bi-shield-lock me-2"></i> Utilizadores
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'departments' ? 'active' : '' ?>" href="index.php?page=departments">
                            <i class="bi bi-building me-2"></i> Departamentos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'positions' ? 'active' : '' ?>" href="index.php?page=positions">
                            <i class="bi bi-diagram-3 me-2"></i> Cargos
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'users' && ($action ?? '') === 'audit_log' ? 'active' : '' ?>" href="index.php?page=users&action=audit_log">
                            <i class="bi bi-journal-text me-2"></i> Log de Auditoria
                        </a>
                    </li>
                    <?php if (Auth::can('onboarding', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'onboarding' ? 'active' : '' ?>" href="index.php?page=onboarding">
                            <i class="bi bi-list-check me-2"></i> Onboarding
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (Auth::can('requests', 'view')): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'requests' ? 'active' : '' ?>" href="index.php?page=requests">
                            <i class="bi bi-envelope-paper me-2"></i> Solicitacoes
                        </a>
                    </li>
                    <?php endif; ?>
                    <li class="nav-item">
                        <a class="nav-link <?= ($page ?? '') === 'settings' ? 'active' : '' ?>" href="index.php?page=settings">
                            <i class="bi bi-palette me-2"></i> Personalização
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
    </div>

    <!-- Conteúdo principal -->
    <main class="main-content">
        <div class="container-fluid py-3">
            <!-- Flash messages -->
            <?php $flashError = Session::flash('error'); ?>
            <?php $flashSuccess = Session::flash('success'); ?>
            <?php if ($flashError): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <?= $flashError ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashSuccess): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?= $flashSuccess ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
