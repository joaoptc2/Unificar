<?php
$is_active = function(array $titles) use ($page_title) {
    return in_array($page_title ?? '', $titles, true) ? 'active' : '';
};
$_app_name = setting('app_name');
$_logo_icon = setting('logo_icon');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo e($page_title ?? 'Sistema'); ?> — <?php echo e($_app_name); ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="<?php echo asset('css/style.css'); ?>" rel="stylesheet">
    <style id="dynamic-theme"><?php echo settings_dynamic_css(); ?></style>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body data-notif-url="<?php echo e(url('api/notifications_count')); ?>">

<!-- ══ Navbar ══════════════════════════════════════════════════════════════ -->
<nav class="navbar navbar-expand-lg navbar-dark bg-primary fixed-top shadow-sm">
    <div class="container-fluid">
        <button class="navbar-toggler" type="button"
                data-bs-toggle="offcanvas" data-bs-target="#sidebar"
                aria-controls="sidebar" aria-label="Abrir menu">
            <span class="navbar-toggler-icon"></span>
        </button>

        <a class="navbar-brand fw-semibold" href="<?php echo url('dashboard'); ?>">
            <i class="bi <?php echo e($_logo_icon); ?> me-1"></i><?php echo e($_app_name); ?>
        </a>

        <div class="d-flex align-items-center ms-auto">
            <?php
                $nav_sectors = get_user_sectors();
                if (count($nav_sectors) > 1):
            ?>
            <!-- Sector Switcher -->
            <form method="POST" action="<?php echo url('profile/switch-sector'); ?>"
                  class="me-3 d-none d-md-flex">
                <?php echo csrf_field(); ?>
                <select name="sector_id" class="form-select form-select-sm"
                        onchange="this.form.submit()"
                        style="min-width:160px; background:rgba(255,255,255,.15); color:#fff; border-color:rgba(255,255,255,.25)">
                    <?php foreach ($nav_sectors as $ns): ?>
                        <option value="<?php echo (int) $ns['id']; ?>"
                                style="color:#1e293b"
                                <?php echo (int) $ns['id'] === get_sector_id() ? 'selected' : ''; ?>>
                            <?php echo e($ns['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php elseif (get_sector_name()): ?>
            <span class="text-white-50 me-3 d-none d-md-inline small">
                <i class="bi bi-diagram-3 me-1"></i><?php echo e(get_sector_name()); ?>
            </span>
            <?php endif; ?>

            <a href="<?php echo url('notifications'); ?>"
               class="nav-link text-white position-relative me-3"
               aria-label="Notificações">
                <i class="bi bi-bell fs-5"></i>
                <span class="badge bg-danger position-absolute top-0 start-100 translate-middle rounded-pill"
                      id="notif-badge" style="display:none; font-size:.6rem">0</span>
            </a>

            <div class="dropdown">
                <a class="nav-link text-white dropdown-toggle d-flex align-items-center"
                   href="#" data-bs-toggle="dropdown" aria-expanded="false">
                    <i class="bi bi-person-circle fs-5 me-1"></i>
                    <span class="d-none d-md-inline small"><?php echo e(get_user_name()); ?></span>
                </a>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li><span class="dropdown-item-text text-muted small"><?php echo e(get_user_email()); ?></span></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="<?php echo url('profile'); ?>">
                        <i class="bi bi-person me-2"></i>Meu perfil
                    </a></li>
                    <li><a class="dropdown-item" href="<?php echo url('profile/change-password'); ?>">
                        <i class="bi bi-key me-2"></i>Alterar senha
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item text-danger" href="<?php echo url('logout'); ?>">
                        <i class="bi bi-box-arrow-right me-2"></i>Sair
                    </a></li>
                </ul>
            </div>
        </div>
    </div>
</nav>

<!-- ══ Sidebar (offcanvas em mobile, fixa em desktop) ═════════════════════ -->
<div class="offcanvas-lg offcanvas-start sidebar" id="sidebar" tabindex="-1" aria-labelledby="sidebarLabel">
    <div class="offcanvas-header d-lg-none text-white border-bottom border-secondary">
        <h5 class="offcanvas-title" id="sidebarLabel"><?php echo e($_app_name); ?></h5>
        <button type="button" class="btn-close btn-close-white"
                data-bs-dismiss="offcanvas" data-bs-target="#sidebar" aria-label="Fechar"></button>
    </div>
    <div class="offcanvas-body">
        <nav class="sidebar-nav py-2">
            <ul class="nav flex-column">
                <li class="nav-header mt-2 mb-1">
                    <small class="text-muted fw-bold px-3">PRINCIPAL</small>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Dashboard']); ?>" href="<?php echo url('dashboard'); ?>">
                        <i class="bi bi-speedometer2 me-2"></i>Dashboard
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Documentos']); ?>" href="<?php echo url('documents'); ?>">
                        <i class="bi bi-folder2-open me-2"></i>Documentos
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Indicadores de Enfermagem']); ?>" href="<?php echo url('indicators'); ?>">
                        <i class="bi bi-graph-up me-2"></i>Indicadores
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Painel de Indicadores']); ?>" href="<?php echo url('indicators/dashboard'); ?>">
                        <i class="bi bi-speedometer me-2"></i>Painel
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Notificações']); ?>" href="<?php echo url('notifications'); ?>">
                        <i class="bi bi-bell me-2"></i>Notificações
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Planos de Ação']); ?>" href="<?php echo url('indicators/actions'); ?>">
                        <i class="bi bi-list-check me-2"></i>Planos de Ação
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Relatório de Conformidade']); ?>" href="<?php echo url('reports'); ?>">
                        <i class="bi bi-clipboard-data me-2"></i>Conformidade
                    </a>
                </li>

                <?php if (is_manager()): ?>
                <li class="nav-header mt-3 mb-1">
                    <small class="text-muted fw-bold px-3">ADMINISTRAÇÃO</small>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Administração']); ?>" href="<?php echo url('admin'); ?>">
                        <i class="bi bi-gear me-2"></i>Painel Admin
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Gerenciar Usuários']); ?>" href="<?php echo url('admin/users'); ?>">
                        <i class="bi bi-people me-2"></i>Usuários
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Gerenciar Setores']); ?>" href="<?php echo url('admin/sectors'); ?>">
                        <i class="bi bi-diagram-3 me-2"></i>Setores
                    </a>
                </li>
                <?php if (is_admin()): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo $is_active(['Configurações Visuais']); ?>" href="<?php echo url('admin/settings'); ?>">
                        <i class="bi bi-palette me-2"></i>Aparência
                    </a>
                </li>
                <?php endif; ?>
                <?php endif; ?>
            </ul>
        </nav>
    </div>
</div>

<!-- ══ Main ═══════════════════════════════════════════════════════════════ -->
<main class="main-content">
    <div class="container-fluid py-3">
        <?php foreach (['success' => 'success', 'error' => 'danger', 'info' => 'info'] as $type => $css):
            if (!has_flash($type)) continue;
            $icon = ['success' => 'check-circle', 'danger' => 'exclamation-circle', 'info' => 'info-circle'][$css];
        ?>
            <div class="alert alert-<?php echo $css; ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?php echo $icon; ?> me-2"></i><?php echo get_flash($type); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Fechar"></button>
            </div>
        <?php endforeach; ?>

        <?php echo $content; ?>
    </div>
</main>

<?php
    $_footer = setting('footer_text');
    $_footer_html = !empty($_footer) ? e($_footer) : e($_app_name) . ' &copy; ' . date('Y');
?>
<footer class="main-footer text-center text-muted py-3">
    <small><?php echo $_footer_html; ?></small>
</footer>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="<?php echo asset('js/app.js'); ?>"></script>
</body>
</html>
