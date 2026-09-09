<?php
/**
 * Template do layout unificado.
 * Variáveis disponíveis (ver Core\Layout::render):
 * $title, $content, $head, $scripts, $fluid, $body_class, $active,
 * $user, $module_slug, $topbar_active, $manifest, $sidebar, $modules_nav,
 * $unread, $flash, $admin_link, $migrations_pending
 */
$appName = Core\Settings::get('org_name', core_config('app.name', 'Portal Corporativo'));
$moodleLink = core_config('moodle.enabled') ? core_config('moodle.url') : null;
$hasSidebar = !empty($sidebar);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= core_e($title) ?> — <?= core_e($appName) ?></title>
    <meta name="csrf-token" content="<?= Core\Csrf::token() ?>">
    <meta name="base-url" content="<?= core_e(BASE_URL) ?>">
    <?php if ($module_slug): ?><meta name="module" content="<?= core_e($module_slug) ?>"><?php endif; ?>
    <?php if ($user): ?><meta name="user-id" content="<?= (int) $user['id'] ?>"><?php endif; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="<?= core_asset('core/app.css') ?>">
    <?= $head ?>
</head>
<body class="portal-body <?= core_e($body_class) ?>">

<!-- ===================== MENU SUPERIOR (módulos) ===================== -->
<nav class="navbar navbar-expand-lg portal-topbar fixed-top" data-bs-theme="dark">
    <div class="container-fluid">
        <?php if ($hasSidebar): ?>
        <button class="btn btn-link text-white d-lg-none px-2" data-bs-toggle="offcanvas" data-bs-target="#portalSidebar" aria-label="Menu">
            <i class="bi bi-list fs-4"></i>
        </button>
        <?php endif; ?>

        <a class="navbar-brand d-flex align-items-center gap-2" href="<?= core_url('index.php') ?>">
            <i class="bi bi-grid-3x3-gap-fill"></i>
            <span class="fw-semibold"><?= core_e($appName) ?></span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#topbarNav" aria-label="Alternar navegação">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="topbarNav">
            <!-- Seletor de módulos: apenas os módulos que o usuário pode acessar -->
            <ul class="navbar-nav portal-module-nav me-auto">
                <?php foreach ($modules_nav as $slug => $m): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $slug === $topbar_active ? 'active' : '' ?>"
                           href="<?= core_module_url($slug) ?>">
                            <i class="bi <?= core_e($m['icon'] ?? 'bi-app') ?> me-1"></i><?= core_e($m['name']) ?>
                        </a>
                    </li>
                <?php endforeach; ?>
                <?php if ($moodleLink): ?>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= core_e($moodleLink) ?>" target="_blank" rel="noopener">
                            <i class="bi bi-mortarboard me-1"></i><?= core_e(core_config('moodle.link_label', 'Moodle')) ?>
                            <i class="bi bi-box-arrow-up-right small"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>

            <?php if ($user): ?>
            <ul class="navbar-nav align-items-lg-center">
                <!-- Notificações unificadas -->
                <li class="nav-item dropdown">
                    <a class="nav-link position-relative" href="#" data-bs-toggle="dropdown" aria-label="Notificações" id="portalBell">
                        <i class="bi bi-bell fs-5"></i>
                        <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger <?= $unread ? '' : 'd-none' ?>" id="portalBellBadge">
                            <?= $unread > 99 ? '99+' : (int) $unread ?>
                        </span>
                    </a>
                    <div class="dropdown-menu dropdown-menu-end portal-notif-menu p-0">
                        <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom">
                            <strong>Notificações</strong>
                            <a class="small text-decoration-none" href="<?= core_module_url('auth', ['a' => 'notifications_read_all']) ?>">marcar todas como lidas</a>
                        </div>
                        <div id="portalNotifList" class="portal-notif-list">
                            <?php foreach (Core\Notifications::latest((int) $user['id'], 10) as $n): ?>
                                <a class="dropdown-item text-wrap py-2 <?= $n['read_at'] ? 'text-muted' : 'fw-semibold' ?>"
                                   href="<?= core_e($n['link'] ?: '#') ?>">
                                    <span class="badge text-bg-light border me-1"><?= core_e($n['module'] ?: 'portal') ?></span>
                                    <?= core_e($n['title']) ?>
                                    <div class="small text-muted fw-normal"><?= core_e(date('d/m/Y H:i', strtotime((string) $n['created_at']))) ?></div>
                                </a>
                            <?php endforeach; ?>
                        </div>
                        <div class="border-top px-3 py-2 text-center">
                            <a class="small text-decoration-none" href="<?= core_module_url('auth', ['a' => 'notifications']) ?>">ver todas</a>
                        </div>
                    </div>
                </li>

                <!-- Usuário -->
                <li class="nav-item dropdown ms-lg-2">
                    <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
                        <span class="portal-avatar"><?= core_e(mb_strtoupper(mb_substr((string) $user['name'], 0, 1))) ?></span>
                        <span class="d-none d-lg-inline"><?= core_e($user['name']) ?></span>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li class="dropdown-header">
                            <?= core_e($user['email']) ?>
                            <?php if (!empty($user['is_admin'])): ?><span class="badge text-bg-primary ms-1">Admin</span><?php endif; ?>
                        </li>
                        <li><a class="dropdown-item" href="<?= core_module_url('auth', ['a' => 'profile']) ?>"><i class="bi bi-person me-2"></i>Meu perfil</a></li>
                        <li><a class="dropdown-item" href="<?= core_module_url('auth', ['a' => 'security']) ?>"><i class="bi bi-shield-lock me-2"></i>Senha e 2FA</a></li>
                        <?php if (!empty($admin_link)): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= core_module_url('admin') ?>"><i class="bi bi-gear me-2"></i>Administração</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= core_module_url('auth', ['a' => 'logout']) ?>"><i class="bi bi-box-arrow-right me-2"></i>Sair</a></li>
                    </ul>
                </li>
            </ul>
            <?php endif; ?>
        </div>
    </div>
</nav>

<div class="portal-shell <?= $hasSidebar ? 'has-sidebar' : '' ?>">
    <?php if ($hasSidebar): ?>
    <!-- ===================== MENU LATERAL (módulo ativo) ===================== -->
    <aside class="portal-sidebar offcanvas-lg offcanvas-start" tabindex="-1" id="portalSidebar">
        <div class="offcanvas-header d-lg-none">
            <strong><?= core_e($manifest['name'] ?? $title) ?></strong>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" data-bs-target="#portalSidebar" aria-label="Fechar"></button>
        </div>
        <div class="offcanvas-body d-flex flex-column p-0">
            <?php if ($manifest): ?>
                <div class="portal-sidebar-brand d-none d-lg-flex">
                    <i class="bi <?= core_e($manifest['icon'] ?? 'bi-app') ?> me-2"></i>
                    <span><?= core_e($manifest['name']) ?></span>
                </div>
            <?php endif; ?>
            <nav class="portal-sidebar-nav flex-grow-1">
                <?php foreach ($sidebar as $section): ?>
                    <?php if (!empty($section['heading'])): ?>
                        <div class="portal-sidebar-heading"><?= core_e($section['heading']) ?></div>
                    <?php endif; ?>
                    <ul class="nav flex-column">
                        <?php foreach ($section['items'] ?? [] as $item): ?>
                            <li class="nav-item">
                                <a class="nav-link <?= ($item['key'] ?? '') !== '' && ($item['key'] ?? null) === $active ? 'active' : '' ?>"
                                   href="<?= core_e($item['url']) ?>">
                                    <i class="bi <?= core_e($item['icon'] ?? 'bi-dot') ?>"></i>
                                    <span><?= core_e($item['label']) ?></span>
                                    <?php if (!empty($item['badge'])): ?>
                                        <span class="badge text-bg-danger ms-auto"><?= core_e($item['badge']) ?></span>
                                    <?php endif; ?>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </nav>
        </div>
    </aside>
    <?php endif; ?>

    <!-- ===================== CONTEÚDO ===================== -->
    <main class="portal-main">
        <?php if (!empty($migrations_pending)): ?>
            <div class="alert alert-warning d-flex align-items-center gap-2" role="alert" data-no-auto-dismiss="1">
                <i class="bi bi-database-exclamation fs-5"></i>
                <div class="flex-grow-1">
                    <strong>Atualizações de banco pendentes.</strong> Há alterações de estrutura desta versão do sistema
                    que ainda não foram aplicadas — algumas funções podem falhar até que sejam executadas.
                </div>
                <a class="btn btn-sm btn-warning text-nowrap" href="<?= core_module_url('admin', ['a' => 'migrations']) ?>">Aplicar agora</a>
            </div>
        <?php endif; ?>
        <?php foreach ($flash as $type => $messages): ?>
            <?php foreach ((array) $messages as $msg):
                $cls = ['success' => 'success', 'error' => 'danger', 'warning' => 'warning'][$type] ?? 'info'; ?>
                <div class="alert alert-<?= $cls ?> alert-dismissible fade show" role="alert">
                    <?= core_e($msg) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>

        <div class="<?= $fluid ? 'portal-content-fluid' : 'portal-content' ?>">
            <?= $content ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?= core_asset('core/app.js') ?>"></script>
<?= $scripts ?>
</body>
</html>
