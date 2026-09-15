<?php
/**
 * Administração central da plataforma:
 *  - usuários (CRUD) e suas micropermissões, grupos, módulos, configurações,
 *    auditoria, atualizações de banco e fila de e-mails — somente admins globais;
 *  - layouts de documentos (papel timbrado) — admins globais ou quem tem
 *    'layouts.*' em algum módulo;
 *  - painéis de configuração dos MÓDULOS (setores, categorias, departamentos,
 *    cargos, emojis...) — abertos a quem tem as micropermissões do módulo
 *    (ver Core\AdminPanel).
 * Rotas: index.php?m=admin&a=<ação>
 */

declare(strict_types=1);

use Core\AdminPanel;
use Core\Audit;
use Core\Auth;
use Core\Csrf;
use Core\DB;
use Core\Flash;
use Core\Layout;
use Core\MailQueue;
use Core\Migrations;
use Core\Modules;
use Core\Perms;
use Core\Settings;

Auth::requireLogin();

$action   = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($_GET['a'] ?? 'index')));
$userId   = (int) Auth::id();
$isGlobal = Auth::isGlobalAdmin();

// Ações exclusivas do administrador global
$coreActions = [
    'users', 'user_form', 'user_save', 'user_delete', 'user_perms', 'user_perms_save',
    'groups', 'group_form', 'group_save', 'group_delete',
    'modules', 'settings', 'appearance', 'appearance_save', 'appearance_export', 'audit',
    'migrations', 'migrations_apply', 'health',
    'backup', 'backup_create', 'backup_download', 'backup_delete', 'backup_verify',
    'backup_schedule_save',
    'mailqueue', 'mailqueue_process', 'mailqueue_retry',
    'mail', 'mail_save', 'mail_test', 'mail_probe',
    'mail_layout_save', 'mail_layout_preview', 'mail_inbox_save', 'mail_inbox_test',
];
if (in_array($action, $coreActions, true)) {
    Auth::requireGlobalAdmin();
} elseif ($action === 'index' && !AdminPanel::canAccess($userId)) {
    http_response_code(403);
    Layout::renderError(403, 'Acesso restrito aos administradores da plataforma.');
    exit;
}

function admin_sidebar(): array
{
    $userId   = (int) Auth::id();
    $sections = [];

    $core = [['label' => 'Visão geral', 'url' => core_module_url('admin'), 'icon' => 'bi-speedometer2', 'key' => 'index']];
    if (Auth::isGlobalAdmin()) {
        $core[] = ['label' => 'Usuários',            'url' => core_module_url('admin', ['a' => 'users']),      'icon' => 'bi-people',       'key' => 'users'];
        $core[] = ['label' => 'Grupos de permissões','url' => core_module_url('admin', ['a' => 'groups']),     'icon' => 'bi-diagram-3',    'key' => 'groups'];
        $core[] = ['label' => 'Módulos',             'url' => core_module_url('admin', ['a' => 'modules']),    'icon' => 'bi-grid',         'key' => 'modules'];
        $core[] = ['label' => 'Configurações',       'url' => core_module_url('admin', ['a' => 'settings']),   'icon' => 'bi-sliders',      'key' => 'settings'];
        $core[] = ['label' => 'Aparência',           'url' => core_module_url('admin', ['a' => 'appearance']), 'icon' => 'bi-palette',      'key' => 'appearance'];
        $core[] = ['label' => 'Atualizações de banco','url' => core_module_url('admin', ['a' => 'migrations']),'icon' => 'bi-database-up',  'key' => 'migrations'];
        $core[] = ['label' => 'Backup',              'url' => core_module_url('admin', ['a' => 'backup']),     'icon' => 'bi-hdd-stack',    'key' => 'backup'];
        $core[] = ['label' => 'E-mail',              'url' => core_module_url('admin', ['a' => 'mail']),       'icon' => 'bi-envelope-at',  'key' => 'mail'];
        $core[] = ['label' => 'Auditoria',           'url' => core_module_url('admin', ['a' => 'audit']),      'icon' => 'bi-journal-text', 'key' => 'audit'];
    }
    $sections[] = ['heading' => 'Administração', 'items' => $core];

    if (AdminPanel::canManageLayouts($userId)) {
        $sections[] = ['heading' => 'Padronização', 'items' => [
            ['label' => 'Layouts de documentos', 'url' => core_module_url('admin', ['a' => 'layouts']), 'icon' => 'bi-layout-text-window-reverse', 'key' => 'layouts'],
        ]];
    }

    $mods = [];
    foreach (AdminPanel::modulesFor($userId) as $slug => $info) {
        $m = $info['manifest'];
        $mods[] = [
            'label' => $m['admin']['label'] ?? $m['name'],
            'url'   => AdminPanel::url($slug),
            'icon'  => $m['admin']['icon'] ?? ($m['icon'] ?? 'bi-app'),
            'key'   => 'mod-' . $slug,
        ];
    }
    if ($mods) {
        $sections[] = ['heading' => 'Configuração dos módulos', 'items' => $mods];
    }
    return $sections;
}

function admin_render(string $title, string $content, string $active): void
{
    Layout::render([
        'title'   => $title,
        'content' => $content,
        'module'  => null,
        'sidebar' => admin_sidebar(),
        'active'  => $active,
    ]);
}

/**
 * Árvore de micropermissões (todos os módulos) com checkboxes.
 *
 * @param array $checked   chaves efetivamente marcadas: [module => [key => true]]
 * @param array $inherited chaves herdadas de grupos (editor de usuário): [module => [key => true]]
 * @param bool  $showInheritance exibe badges de herança (editor de usuário)
 */
function admin_perm_tree(array $checked, array $inherited = [], bool $showInheritance = false): string
{
    ob_start(); ?>
    <?php foreach (Modules::all() as $slug => $manifest):
        $catalog = Perms::catalog($slug);
        if (!$catalog) {
            continue;
        }
        $presets = (array) ($manifest['presets'] ?? []); ?>
        <div class="card mb-3 perm-module" data-module="<?= core_e($slug) ?>">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <strong class="me-auto"><i class="bi <?= core_e($manifest['icon'] ?? 'bi-app') ?> me-1"></i><?= core_e($manifest['name']) ?></strong>
                <?php foreach ($presets as $presetKey => $preset):
                    $keys = Perms::expand($slug, (array) ($preset['keys'] ?? [])); ?>
                    <button type="button" class="btn btn-sm btn-outline-secondary preset-btn"
                            data-keys='<?= core_e(json_encode($keys)) ?>'>
                        <i class="bi bi-magic me-1"></i><?= core_e($preset['label'] ?? $presetKey) ?>
                    </button>
                <?php endforeach; ?>
                <button type="button" class="btn btn-sm btn-outline-primary check-all">Marcar tudo</button>
                <button type="button" class="btn btn-sm btn-outline-secondary uncheck-all">Limpar</button>
            </div>
            <div class="card-body py-2">
                <div class="row">
                <?php foreach ($catalog as $resource => $def): ?>
                    <div class="col-12 col-md-6 col-xl-4 py-2 perm-resource">
                        <div class="fw-semibold small text-uppercase text-muted mb-1 d-flex align-items-center">
                            <?= core_e($def['label'] ?? $resource) ?>
                            <a href="#" class="ms-2 small fw-normal text-decoration-none res-all">todas</a>
                        </div>
                        <?php foreach (($def['actions'] ?? []) as $act => $actLabel):
                            $key   = $resource . '.' . $act;
                            $isChk = isset($checked[$slug][$key]);
                            $isInh = isset($inherited[$slug][$key]); ?>
                            <div class="form-check form-check-inline me-3">
                                <input class="form-check-input" type="checkbox" id="p_<?= core_e($slug . '_' . $resource . '_' . $act) ?>"
                                       name="perms[<?= core_e($slug) ?>][]" value="<?= core_e($key) ?>"
                                       <?= $isChk ? 'checked' : '' ?> <?= $isInh ? 'data-inherited="1"' : '' ?>>
                                <label class="form-check-label" for="p_<?= core_e($slug . '_' . $resource . '_' . $act) ?>">
                                    <?= core_e($actLabel) ?>
                                    <?php if ($showInheritance && $isInh): ?>
                                        <i class="bi bi-diagram-3 text-info" title="Herdada de grupo"></i>
                                    <?php endif; ?>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                </div>
            </div>
            <!-- garante que o módulo apareça no POST mesmo sem nenhuma caixa marcada -->
            <input type="hidden" name="perms[<?= core_e($slug) ?>][]" value="">
        </div>
    <?php endforeach; ?>
    <?php if ($showInheritance): ?>
        <p class="small text-muted"><i class="bi bi-diagram-3 text-info"></i> = herdada de grupo.
            Desmarcar uma permissão herdada cria uma exceção individual (negação) para este usuário;
            marcar uma não herdada cria uma concessão individual.</p>
    <?php endif; ?>
    <script>
    document.addEventListener('click', function (e) {
        var mod = e.target.closest('.perm-module');
        if (e.target.closest('.check-all')) {
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; });
        } else if (e.target.closest('.uncheck-all')) {
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = false; });
        } else if (e.target.closest('.preset-btn')) {
            var keys = JSON.parse(e.target.closest('.preset-btn').dataset.keys || '[]');
            mod.querySelectorAll('input[type=checkbox]').forEach(function (c) {
                if (c.value) c.checked = keys.indexOf(c.value) !== -1;
            });
        } else if (e.target.closest('.res-all')) {
            e.preventDefault();
            e.target.closest('.perm-resource').querySelectorAll('input[type=checkbox]').forEach(function (c) { c.checked = true; });
        }
    });
    </script>
    <?php
    return (string) ob_get_clean();
}

/** Lê o POST perms[module][] e devolve [module => keys[]] saneado. */
function admin_read_perms_post(): array
{
    $out = [];
    foreach ((array) ($_POST['perms'] ?? []) as $slug => $keys) {
        $slug = (string) $slug;
        if (!Modules::manifest($slug)) {
            continue;
        }
        $out[$slug] = array_values(array_filter(array_map('strval', (array) $keys), fn ($k) => $k !== ''));
    }
    return $out;
}

/** Cartões dos painéis de configuração de módulos acessíveis ao usuário. */
function admin_module_cards(): string
{
    $mods = AdminPanel::modulesFor((int) Auth::id());
    if (!$mods) {
        return '';
    }
    ob_start(); ?>
    <h2 class="h6 text-uppercase text-muted mt-4 mb-2">Configuração dos módulos</h2>
    <div class="row g-3">
        <?php foreach ($mods as $slug => $info): $m = $info['manifest']; ?>
            <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                <a class="card portal-module-card h-100" href="<?= AdminPanel::url($slug) ?>">
                    <div class="card-body d-flex flex-column gap-2">
                        <div class="module-icon"><i class="bi <?= core_e($m['admin']['icon'] ?? ($m['icon'] ?? 'bi-app')) ?>"></i></div>
                        <div class="fw-semibold text-body"><?= core_e($m['admin']['label'] ?? $m['name']) ?></div>
                        <div class="small text-muted"><?= core_e(implode(' · ', array_map(fn ($t) => $t['label'] ?? '', $info['tabs']))) ?></div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
    </div>
    <?php
    return (string) ob_get_clean();
}

switch ($action) {

    case 'index':
        if (!$isGlobal) {
            ob_start(); ?>
            <h1 class="h4 mb-3"><i class="bi bi-gear me-2"></i>Administração</h1>
            <p class="text-muted">Você tem acesso às configurações abaixo. A gestão de usuários, grupos e permissões é feita pelos administradores da plataforma.</p>
            <?= admin_module_cards() ?>
            <?php
            admin_render('Administração', (string) ob_get_clean(), 'index');
            break;
        }
        $stats = [
            'users'   => (int) (DB::queryOne('SELECT COUNT(*) n FROM users')['n'] ?? 0),
            'active'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE active = 1')['n'] ?? 0),
            'groups'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM user_groups')['n'] ?? 0),
            'moodle'  => (int) (DB::queryOne('SELECT COUNT(*) n FROM users WHERE auth_source = "moodle"')['n'] ?? 0),
        ];
        $recent = DB::query('SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 12');
        $mailStats = MailQueue::stats();
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-gear me-2"></i>Administração da plataforma</h1>
        <div class="row g-3 mb-4">
            <?php foreach ([
                ['Usuários', $stats['users'], 'bi-people', 'primary'],
                ['Ativos', $stats['active'], 'bi-person-check', 'success'],
                ['Grupos', $stats['groups'], 'bi-diagram-3', 'info'],
                ['Contas Moodle', $stats['moodle'], 'bi-mortarboard', 'secondary'],
            ] as [$label, $value, $icon, $color]): ?>
                <div class="col-6 col-lg-3">
                    <div class="card h-100">
                        <div class="card-body d-flex align-items-center gap-3">
                            <span class="fs-3 text-<?= $color ?>"><i class="bi <?= $icon ?>"></i></span>
                            <div>
                                <div class="fs-4 fw-semibold"><?= (int) $value ?></div>
                                <div class="small text-muted"><?= core_e($label) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($mailStats['pending'] > 0 || $mailStats['failed'] > 0): ?>
            <div class="alert alert-info py-2 small">
                <i class="bi bi-envelope-paper me-1"></i>Fila de e-mails: <strong><?= $mailStats['pending'] ?></strong> pendente(s),
                <strong><?= $mailStats['failed'] ?></strong> com falha —
                <a href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => 'queue']) ?>">gerenciar</a>.
            </div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header">Atividade recente</div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Quando</th><th>Usuário</th><th>Módulo</th><th>Ação</th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?= core_e(date('d/m H:i', strtotime((string) $r['created_at']))) ?></td>
                            <td><?= core_e($r['user_name'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= core_e($r['module'] ?? 'core') ?></span></td>
                            <td><?= core_e($r['action']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?= admin_module_cards() ?>
        <?php
        admin_render('Administração', (string) ob_get_clean(), 'index');
        break;

    // ================= PAINÉIS DE CONFIGURAÇÃO DOS MÓDULOS =================

    case 'module':
        $slug     = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['slug'] ?? '')));
        $manifest = Modules::manifest($slug);
        if (!$manifest || empty($manifest['admin']) || !($manifest['active'] ?? true)) {
            Layout::renderError(404, 'Este módulo não possui painel de configuração.');
            break;
        }
        $tabs = AdminPanel::tabsFor($userId, $slug);
        if ($tabs === []) {
            http_response_code(403);
            Layout::renderError(403, 'Você não tem permissão para configurar este módulo.');
            break;
        }
        $tab = preg_replace('/[^a-z0-9_-]/', '', strtolower((string) ($_GET['tab'] ?? '')));
        if ($tab === '' || !isset($tabs[$tab])) {
            if ($tab !== '') {
                http_response_code(403);
                Layout::renderError(403, 'Você não tem permissão para esta configuração.');
                break;
            }
            $tab = (string) array_key_first($tabs);
        }

        // Contexto do módulo (como no front controller)
        if (!defined('MODULE_SLUG')) {
            define('MODULE_SLUG', $slug);
            define('MODULE_PATH', $manifest['path']);
            define('MODULE_URL', BASE_URL . '/index.php?m=' . $slug);
        }
        define('CORE_ADMIN_TAB', $tab);
        $GLOBALS['MODULE_PERMS'] = Perms::effective($userId, $slug);

        $entry = MODULE_PATH . '/' . ltrim((string) ($manifest['admin']['entry'] ?? 'admin.php'), '/');
        if (!is_file($entry)) {
            Layout::renderError(500, 'Painel de configuração do módulo não encontrado (' . core_e(basename($entry)) . ').');
            break;
        }

        // Cabeçalho + abas do painel, prefixados ao conteúdo que o módulo renderizar
        ob_start(); ?>
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <h1 class="h5 mb-0 text-muted">
                <i class="bi <?= core_e($manifest['admin']['icon'] ?? ($manifest['icon'] ?? 'bi-app')) ?> me-1"></i>
                <?= core_e($manifest['admin']['label'] ?? $manifest['name']) ?>
                <span class="text-muted fw-normal">— configuração</span>
            </h1>
            <a class="btn btn-sm btn-outline-secondary ms-auto" href="<?= core_module_url($slug) ?>">
                <i class="bi bi-box-arrow-up-right me-1"></i>Abrir o módulo
            </a>
        </div>
        <ul class="nav nav-tabs mb-3 admin-module-tabs">
            <?php foreach ($tabs as $key => $t): ?>
                <?php
                // Aba 'hidden': tela de detalhe alcançada por um botão de
                // dentro de outra aba (ex.: "Usuários do setor"). Continua
                // navegável e com permissão própria — só não ocupa a barra.
                if (!empty($t['hidden']) && $key !== $tab) { continue; }
                ?>
                <li class="nav-item">
                    <a class="nav-link <?= $key === $tab ? 'active' : '' ?>" href="<?= AdminPanel::url($slug, (string) $key) ?>">
                        <?php if (!empty($t['icon'])): ?><i class="bi <?= core_e($t['icon']) ?> me-1"></i><?php endif; ?>
                        <?= core_e($t['label'] ?? $key) ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
        Layout::embed([
            'sidebar' => admin_sidebar(),
            'active'  => 'mod-' . $slug,
            'prepend' => (string) ob_get_clean(),
            'title'   => 'Administração',
        ]);
        chdir(MODULE_PATH);
        require $entry;
        break;

    // ================= LAYOUTS DE DOCUMENTOS =================

    case 'layouts':
    case 'layout_form':
    case 'layout_save':
    case 'layout_delete':
    case 'layout_preview':
        require CORE_PATH . '/controllers/admin_layouts.php';
        match ($action) {
            'layouts'        => core_admin_layouts_index(),
            'layout_form'    => core_admin_layouts_form(),
            'layout_save'    => core_admin_layouts_save(),
            'layout_delete'  => core_admin_layouts_delete(),
            'layout_preview' => core_admin_layout_preview(),
        };
        break;

    // ================= BACKUP E RESTAURAÇÃO =================

    case 'backup':
    case 'backup_create':
    case 'backup_download':
    case 'backup_delete':
    case 'backup_verify':
    case 'backup_schedule_save':
        require CORE_PATH . '/controllers/admin_backup.php';
        match ($action) {
            'backup'               => admin_render('Backup', core_admin_backup(), 'backup'),
            'backup_create'        => core_admin_backup_create(),
            'backup_download'      => core_admin_backup_download(),
            'backup_delete'        => core_admin_backup_delete(),
            'backup_verify'        => core_admin_backup_verify(),
            'backup_schedule_save' => core_admin_backup_schedule_save(),
        };
        break;

    // ================= E-MAIL (configuração, teste, fila, diagnóstico) =================

    case 'mail':
    case 'mail_save':
    case 'mail_test':
    case 'mail_probe':
    case 'mail_layout_save':
    case 'mail_layout_preview':
    case 'mail_inbox_save':
    case 'mail_inbox_test':
        require CORE_PATH . '/controllers/admin_mail.php';
        match ($action) {
            'mail'                 => admin_render('E-mail', core_admin_mail(), 'mail'),
            'mail_save'            => core_admin_mail_save(),
            'mail_test'            => core_admin_mail_test(),
            'mail_probe'           => core_admin_mail_probe(),
            'mail_layout_save'     => core_admin_mail_layout_save(),
            'mail_layout_preview'  => core_admin_mail_layout_preview(),
            'mail_inbox_save'      => core_admin_mail_inbox_save(),
            'mail_inbox_test'      => core_admin_mail_inbox_test(),
        };
        break;

    // ================= APARÊNCIA (identidade visual) =================

    case 'appearance':
    case 'appearance_save':
    case 'appearance_export':
    case 'appearance_preview':
        require CORE_PATH . '/controllers/admin_appearance.php';
        match ($action) {
            'appearance'         => admin_render('Aparência', core_admin_appearance(), 'appearance'),
            'appearance_save'    => core_admin_appearance_save(),
            'appearance_export'  => core_admin_appearance_export(),
            'appearance_preview' => core_admin_appearance_preview(),
        };
        break;


    // ================= USUÁRIOS =================

    case 'users':
        $q   = trim((string) ($_GET['q'] ?? ''));
        $sql = 'SELECT * FROM users';
        $par = [];
        if ($q !== '') {
            $sql .= ' WHERE name LIKE ? OR email LIKE ? OR username LIKE ?';
            $par  = ["%{$q}%", "%{$q}%", "%{$q}%"];
        }
        $users   = DB::query($sql . ' ORDER BY name LIMIT 500', $par);
        $modules = Modules::all();
        $groupsByUser = [];
        foreach (DB::query('SELECT m.user_id, g.name FROM user_group_members m JOIN user_groups g ON g.id = m.group_id') as $row) {
            $groupsByUser[(int) $row['user_id']][] = $row['name'];
        }
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-people me-2"></i>Usuários</h1>
            <a class="btn btn-primary" href="<?= core_module_url('admin', ['a' => 'user_form']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo usuário</a>
        </div>
        <form class="mb-3" method="get">
            <input type="hidden" name="m" value="admin"><input type="hidden" name="a" value="users">
            <div class="input-group" style="max-width: 420px">
                <input class="form-control" name="q" value="<?= core_e($q) ?>" placeholder="Buscar por nome, e-mail ou usuário">
                <button class="btn btn-outline-secondary"><i class="bi bi-search"></i></button>
            </div>
        </form>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead>
                        <tr>
                            <th>Usuário</th>
                            <th>Grupos</th>
                            <?php foreach ($modules as $slug => $m): ?>
                                <th class="text-center small" title="<?= core_e($m['name']) ?>"><i class="bi <?= core_e($m['icon'] ?? '') ?>"></i></th>
                            <?php endforeach; ?>
                            <th class="text-center">Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= core_e($u['name']) ?>
                                    <?php if ($u['is_admin']): ?><span class="badge text-bg-primary">Admin</span><?php endif; ?>
                                    <?php if ($u['auth_source'] === 'moodle'): ?><span class="badge text-bg-info" title="Autentica via Moodle"><i class="bi bi-mortarboard"></i></span><?php endif; ?>
                                </div>
                                <div class="small text-muted"><?= core_e($u['email']) ?> · <?= core_e($u['username']) ?></div>
                            </td>
                            <td>
                                <?php foreach ($groupsByUser[(int) $u['id']] ?? [] as $gName): ?>
                                    <span class="badge text-bg-light border"><?= core_e($gName) ?></span>
                                <?php endforeach; ?>
                            </td>
                            <?php foreach ($modules as $slug => $m):
                                $n = $u['is_admin'] ? count(Perms::allKeys($slug)) : count(Perms::effective((int) $u['id'], $slug)); ?>
                                <td class="text-center">
                                    <?php if ($n > 0): ?>
                                        <span class="badge text-bg-success" title="<?= $n ?> permissões"><?= $n ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center">
                                <?php if ($u['active']): ?>
                                    <span class="badge text-bg-success">ativo</span>
                                <?php else: ?>
                                    <span class="badge text-bg-danger">inativo</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end text-nowrap">
                                <a class="btn btn-sm btn-outline-secondary" title="Permissões"
                                   href="<?= core_module_url('admin', ['a' => 'user_perms', 'id' => $u['id']]) ?>"><i class="bi bi-shield-check"></i></a>
                                <a class="btn btn-sm btn-outline-primary" title="Editar"
                                   href="<?= core_module_url('admin', ['a' => 'user_form', 'id' => $u['id']]) ?>"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Usuários', (string) ob_get_clean(), 'users');
        break;

    case 'user_form':
        $id     = (int) ($_GET['id'] ?? 0);
        $user   = $id ? DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]) : null;
        $groups = DB::query('SELECT * FROM user_groups ORDER BY name');
        $memberOf = $id
            ? array_map('intval', array_column(DB::query('SELECT group_id FROM user_group_members WHERE user_id = ?', [$id]), 'group_id'))
            : [];
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-person-gear me-2"></i><?= $user ? 'Editar usuário' : 'Novo usuário' ?></h1>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'user_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) ($user['id'] ?? 0) ?>">
            <div class="row g-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Dados do usuário</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input class="form-control" name="name" value="<?= core_e($user['name'] ?? '') ?>" required>
                            </div>
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Usuário *</label>
                                    <input class="form-control" name="username" value="<?= core_e($user['username'] ?? '') ?>" required>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">E-mail *</label>
                                    <input type="email" class="form-control" name="email" value="<?= core_e($user['email'] ?? '') ?>" required>
                                </div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Senha <?= $user ? '(em branco = não alterar)' : '*' ?></label>
                                <input type="password" class="form-control" name="password" minlength="8" <?= $user ? '' : 'required' ?>>
                                <?php if (($user['auth_source'] ?? '') === 'moodle'): ?>
                                    <div class="form-text"><i class="bi bi-mortarboard me-1"></i>Conta vinculada ao Moodle — senha local é opcional.</div>
                                <?php endif; ?>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="force_password_change" id="fpc" value="1" <?= !empty($user['force_password_change']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="fpc">Exigir troca de senha no próximo login</label>
                            </div>
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" name="active" id="act" value="1" <?= ($user['active'] ?? 1) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="act">Ativo</label>
                            </div>
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" name="is_admin" id="adm" value="1" <?= !empty($user['is_admin']) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="adm">Administrador global <span class="text-muted small">(todas as permissões em todos os módulos)</span></label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Grupos</div>
                        <div class="card-body">
                            <p class="small text-muted">O usuário herda todas as permissões dos grupos marcados.
                                Ajustes finos são feitos em <strong>Permissões</strong> após salvar.</p>
                            <?php if (!$groups): ?>
                                <p class="text-muted small">Nenhum grupo criado ainda —
                                    <a href="<?= core_module_url('admin', ['a' => 'group_form']) ?>">criar grupo</a>.</p>
                            <?php endif; ?>
                            <?php foreach ($groups as $g): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="groups[]" value="<?= (int) $g['id'] ?>"
                                           id="g<?= (int) $g['id'] ?>" <?= in_array((int) $g['id'], $memberOf, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="g<?= (int) $g['id'] ?>">
                                        <?= core_e($g['name']) ?>
                                        <?php if ($g['description']): ?><span class="text-muted small">— <?= core_e($g['description']) ?></span><?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($user): ?>
                                <hr>
                                <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('admin', ['a' => 'user_perms', 'id' => $user['id']]) ?>">
                                    <i class="bi bi-shield-check me-1"></i>Editar permissões individuais
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="mt-3 d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($user && (int) $user['id'] !== (int) Auth::id()): ?>
            <form method="post" action="<?= core_module_url('admin', ['a' => 'user_delete']) ?>" class="mt-2"
                  onsubmit="return confirm('Desativar este usuário?')">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-person-x me-1"></i>Desativar usuário</button>
            </form>
        <?php endif; ?>
        <?php
        admin_render($user ? 'Editar usuário' : 'Novo usuário', (string) ob_get_clean(), 'users');
        break;

    case 'user_save':
        Csrf::check();
        $id       = (int) ($_POST['id'] ?? 0);
        $name     = trim((string) ($_POST['name'] ?? ''));
        $username = trim((string) ($_POST['username'] ?? ''));
        $email    = trim((string) ($_POST['email'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $isAdmin  = !empty($_POST['is_admin']) ? 1 : 0;
        $active   = !empty($_POST['active']) ? 1 : 0;
        $forcePw  = !empty($_POST['force_password_change']) ? 1 : 0;

        if ($name === '' || $username === '' || $email === '') {
            Flash::set('error', 'Preencha nome, usuário e e-mail.');
            core_redirect('index.php?m=admin&a=users');
        }

        $dupe = DB::queryOne('SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?', [$username, $email, $id]);
        if ($dupe) {
            Flash::set('error', 'Já existe um usuário com esse e-mail ou nome de usuário.');
            core_redirect('index.php?m=admin&a=' . ($id ? 'user_form&id=' . $id : 'user_form'));
        }

        if ($id) {
            DB::execute(
                'UPDATE users SET name = ?, username = ?, email = ?, is_admin = ?, active = ?, force_password_change = ? WHERE id = ?',
                [$name, $username, $email, $isAdmin, $active, $forcePw, $id]
            );
            if ($password !== '') {
                DB::execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $id]);
            }
            Audit::log('user.update', 'users', (string) $id, null, null, 'admin');
        } else {
            if ($password === '') {
                Flash::set('error', 'Defina uma senha inicial.');
                core_redirect('index.php?m=admin&a=user_form');
            }
            DB::execute(
                'INSERT INTO users (name, username, email, password_hash, is_admin, active, force_password_change) VALUES (?, ?, ?, ?, ?, ?, ?)',
                [$name, $username, $email, password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $isAdmin, $active, $forcePw]
            );
            $id = DB::lastId();
            Audit::log('user.create', 'users', (string) $id, null, null, 'admin');
        }

        // Grupos
        $wanted = array_map('intval', (array) ($_POST['groups'] ?? []));
        DB::execute('DELETE FROM user_group_members WHERE user_id = ?', [$id]);
        foreach (array_unique($wanted) as $gid) {
            if ($gid > 0 && DB::queryOne('SELECT id FROM user_groups WHERE id = ?', [$gid])) {
                DB::execute('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$gid, $id]);
            }
        }
        Perms::flush();

        Flash::set('success', 'Usuário salvo.');
        core_redirect('index.php?m=admin&a=' . ((int) ($_POST['id'] ?? 0) === 0 ? 'user_perms&id=' . $id : 'users'));
        break;

    case 'user_delete':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id && $id !== (int) Auth::id()) {
            DB::execute('UPDATE users SET active = 0 WHERE id = ?', [$id]);
            Audit::log('user.deactivate', 'users', (string) $id, null, null, 'admin');
            Flash::set('success', 'Usuário desativado.');
        }
        core_redirect('index.php?m=admin&a=users');
        break;

    case 'user_perms':
        $id   = (int) ($_GET['id'] ?? 0);
        $user = DB::queryOne('SELECT * FROM users WHERE id = ?', [$id]);
        if (!$user) {
            core_redirect('index.php?m=admin&a=users');
        }
        $checked = $inherited = [];
        foreach (array_keys(Modules::all()) as $slug) {
            $checked[$slug]   = Perms::effective($id, $slug);
            $inherited[$slug] = Perms::inheritedFor($id, $slug);
        }
        $groupNames = array_column(
            DB::query('SELECT g.name FROM user_groups g JOIN user_group_members m ON m.group_id = g.id WHERE m.user_id = ?', [$id]),
            'name'
        );
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-shield-check me-2"></i>Permissões — <?= core_e($user['name']) ?></h1>
            <a class="btn btn-outline-secondary btn-sm" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Voltar</a>
        </div>
        <?php if (!empty($user['is_admin'])): ?>
            <div class="alert alert-info"><i class="bi bi-info-circle me-1"></i>
                Este usuário é <strong>administrador global</strong> e possui todas as permissões automaticamente —
                a matriz abaixo não se aplica enquanto essa opção estiver ativa.</div>
        <?php endif; ?>
        <?php if ($groupNames): ?>
            <p class="small text-muted">Grupos do usuário:
                <?php foreach ($groupNames as $gn): ?><span class="badge text-bg-light border"><?= core_e($gn) ?></span><?php endforeach; ?>
            </p>
        <?php endif; ?>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'user_perms_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) $user['id'] ?>">
            <?= admin_perm_tree($checked, $inherited, true) ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar permissões</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'users']) ?>">Cancelar</a>
            </div>
        </form>
        <?php
        admin_render('Permissões do usuário', (string) ob_get_clean(), 'users');
        break;

    case 'user_perms_save':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if (!DB::queryOne('SELECT id FROM users WHERE id = ?', [$id])) {
            core_redirect('index.php?m=admin&a=users');
        }
        foreach (admin_read_perms_post() as $slug => $keys) {
            Perms::setUserGrants($id, $slug, $keys, Auth::id());
        }
        Audit::log('perms.user_update', 'users', (string) $id, null, null, 'admin');
        Flash::set('success', 'Permissões do usuário atualizadas.');
        core_redirect('index.php?m=admin&a=user_perms&id=' . $id);
        break;

    // ================= GRUPOS =================

    case 'groups':
        $groups = DB::query(
            'SELECT g.*, (SELECT COUNT(*) FROM user_group_members m WHERE m.group_id = g.id) AS members,
                    (SELECT COUNT(*) FROM permission_grants p WHERE p.subject_type = "group" AND p.subject_id = g.id AND p.allowed = 1) AS grants_n
             FROM user_groups g ORDER BY g.name'
        );
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-diagram-3 me-2"></i>Grupos de permissões</h1>
            <a class="btn btn-primary" href="<?= core_module_url('admin', ['a' => 'group_form']) ?>"><i class="bi bi-plus-lg me-1"></i>Novo grupo</a>
        </div>
        <p class="text-muted small">Defina as permissões uma vez no grupo e todos os membros as herdam.
            Exceções individuais podem ser feitas na tela de permissões de cada usuário.</p>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Grupo</th><th class="text-center">Membros</th><th class="text-center">Permissões</th><th></th></tr></thead>
                    <tbody>
                    <?php if (!$groups): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Nenhum grupo criado.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($groups as $g): ?>
                        <tr>
                            <td>
                                <div class="fw-semibold"><?= core_e($g['name']) ?></div>
                                <div class="small text-muted"><?= core_e($g['description'] ?? '') ?></div>
                            </td>
                            <td class="text-center"><span class="badge text-bg-secondary"><?= (int) $g['members'] ?></span></td>
                            <td class="text-center"><span class="badge text-bg-light border"><?= (int) $g['grants_n'] ?></span></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary" href="<?= core_module_url('admin', ['a' => 'group_form', 'id' => $g['id']]) ?>"><i class="bi bi-pencil"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Grupos', (string) ob_get_clean(), 'groups');
        break;

    case 'group_form':
        $id    = (int) ($_GET['id'] ?? 0);
        $group = $id ? DB::queryOne('SELECT * FROM user_groups WHERE id = ?', [$id]) : null;
        $checked = [];
        if ($group) {
            foreach (Perms::grantsOf('group', $id) as $slug => $keys) {
                foreach ($keys as $key => $allowed) {
                    if ($allowed) {
                        $checked[$slug][$key] = true;
                    }
                }
            }
        }
        $allUsers = DB::query('SELECT id, name, email FROM users WHERE active = 1 ORDER BY name');
        $memberIds = $group
            ? array_map('intval', array_column(DB::query('SELECT user_id FROM user_group_members WHERE group_id = ?', [$id]), 'user_id'))
            : [];
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-diagram-3 me-2"></i><?= $group ? 'Editar grupo' : 'Novo grupo' ?></h1>
        <form method="post" action="<?= core_module_url('admin', ['a' => 'group_save']) ?>">
            <?= Csrf::field() ?>
            <input type="hidden" name="id" value="<?= (int) ($group['id'] ?? 0) ?>">
            <div class="row g-3 mb-3">
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header">Dados do grupo</div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input class="form-control" name="name" value="<?= core_e($group['name'] ?? '') ?>" required
                                       placeholder="ex.: Gestores de RH">
                            </div>
                            <div class="mb-0">
                                <label class="form-label">Descrição</label>
                                <input class="form-control" name="description" value="<?= core_e($group['description'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-6">
                    <div class="card h-100">
                        <div class="card-header d-flex align-items-center">
                            Membros
                            <input class="form-control form-control-sm ms-auto" style="max-width:220px" placeholder="filtrar..."
                                   oninput="var f=this.value.toLowerCase();document.querySelectorAll('#memberList .form-check').forEach(function(d){d.style.display=d.textContent.toLowerCase().indexOf(f)!==-1?'':'none';});">
                        </div>
                        <div class="card-body" id="memberList" style="max-height: 240px; overflow-y: auto">
                            <?php foreach ($allUsers as $u): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="members[]" value="<?= (int) $u['id'] ?>"
                                           id="mu<?= (int) $u['id'] ?>" <?= in_array((int) $u['id'], $memberIds, true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="mu<?= (int) $u['id'] ?>">
                                        <?= core_e($u['name']) ?> <span class="text-muted small"><?= core_e($u['email']) ?></span>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <h2 class="h6 text-uppercase text-muted">Permissões do grupo</h2>
            <?= admin_perm_tree($checked) ?>
            <div class="d-flex gap-2">
                <button class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Salvar grupo</button>
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'groups']) ?>">Cancelar</a>
            </div>
        </form>
        <?php if ($group): ?>
            <form method="post" action="<?= core_module_url('admin', ['a' => 'group_delete']) ?>" class="mt-2"
                  onsubmit="return confirm('Excluir este grupo? Os membros perdem as permissões herdadas dele.')">
                <?= Csrf::field() ?>
                <input type="hidden" name="id" value="<?= (int) $group['id'] ?>">
                <button class="btn btn-outline-danger btn-sm"><i class="bi bi-trash me-1"></i>Excluir grupo</button>
            </form>
        <?php endif; ?>
        <?php
        admin_render($group ? 'Editar grupo' : 'Novo grupo', (string) ob_get_clean(), 'groups');
        break;

    case 'group_save':
        Csrf::check();
        $id   = (int) ($_POST['id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $desc = trim((string) ($_POST['description'] ?? ''));
        if ($name === '') {
            Flash::set('error', 'Informe o nome do grupo.');
            core_redirect('index.php?m=admin&a=groups');
        }
        $dupe = DB::queryOne('SELECT id FROM user_groups WHERE name = ? AND id <> ?', [$name, $id]);
        if ($dupe) {
            Flash::set('error', 'Já existe um grupo com esse nome.');
            core_redirect('index.php?m=admin&a=groups');
        }
        if ($id) {
            DB::execute('UPDATE user_groups SET name = ?, description = ? WHERE id = ?', [$name, $desc, $id]);
        } else {
            DB::execute('INSERT INTO user_groups (name, description) VALUES (?, ?)', [$name, $desc]);
            $id = DB::lastId();
        }

        // Membros
        DB::execute('DELETE FROM user_group_members WHERE group_id = ?', [$id]);
        foreach (array_unique(array_map('intval', (array) ($_POST['members'] ?? []))) as $uid) {
            if ($uid > 0 && DB::queryOne('SELECT id FROM users WHERE id = ?', [$uid])) {
                DB::execute('INSERT INTO user_group_members (group_id, user_id) VALUES (?, ?)', [$id, $uid]);
            }
        }

        // Permissões
        foreach (admin_read_perms_post() as $slug => $keys) {
            Perms::setGroupGrants($id, $slug, $keys, Auth::id());
        }

        Audit::log('perms.group_save', 'user_groups', (string) $id, ['name' => $name], null, 'admin');
        Flash::set('success', 'Grupo salvo.');
        core_redirect('index.php?m=admin&a=group_form&id=' . $id);
        break;

    case 'group_delete':
        Csrf::check();
        $id = (int) ($_POST['id'] ?? 0);
        if ($id) {
            DB::execute("DELETE FROM permission_grants WHERE subject_type = 'group' AND subject_id = ?", [$id]);
            DB::execute('DELETE FROM user_groups WHERE id = ?', [$id]);
            Perms::flush();
            Audit::log('perms.group_delete', 'user_groups', (string) $id, null, null, 'admin');
            Flash::set('success', 'Grupo excluído.');
        }
        core_redirect('index.php?m=admin&a=groups');
        break;

    // ================= MÓDULOS / CONFIG / AUDITORIA =================

    case 'modules':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            foreach ((array) ($_POST['mod'] ?? []) as $slug => $data) {
                // label/custom_icon vazios significam "usar o do manifesto".
                $label = trim((string) ($data['label'] ?? ''));
                $icon  = trim((string) ($data['icon'] ?? ''));
                DB::execute(
                    'UPDATE modules
                        SET active = ?, sort_order = ?, show_in_topbar = ?, label = ?, custom_icon = ?
                      WHERE slug = ?',
                    [
                        !empty($data['active']) ? 1 : 0,
                        max(0, min(9999, (int) ($data['sort'] ?? 0))),
                        !empty($data['topbar']) ? 1 : 0,
                        $label !== '' ? mb_substr($label, 0, 100) : null,
                        $icon !== '' && preg_match('/^bi-[a-z0-9-]{1,50}$/', $icon) ? $icon : null,
                        (string) $slug,
                    ]
                );
            }
            Flash::set('success', 'Módulos atualizados.');
            core_redirect('index.php?m=admin&a=modules');
        }
        // Garante que módulos presentes em disco existam na tabela
        foreach (Modules::all() as $slug => $m) {
            DB::execute(
                'INSERT INTO modules (slug, name, icon, sort_order, active) VALUES (?, ?, ?, ?, 1)
                 ON DUPLICATE KEY UPDATE name = VALUES(name), icon = VALUES(icon)',
                [$slug, $m['name'], $m['icon'] ?? null, (int) ($m['sort_order'] ?? 999)]
            );
        }
        $rows = DB::query('SELECT * FROM modules ORDER BY sort_order');
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-grid me-2"></i>Módulos</h1>
        <form method="post">
            <?= Csrf::field() ?>
            <div class="card">
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead><tr>
                            <th>Módulo</th>
                            <th style="width:200px">Nome no sistema</th>
                            <th style="width:140px">Ícone</th>
                            <th style="width:90px">Ordem</th>
                            <th class="text-center" style="width:90px">No topo</th>
                            <th class="text-center" style="width:80px">Ativo</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($rows as $r): $m = Modules::manifest((string) $r['slug']); ?>
                            <tr>
                                <td><i class="bi <?= core_e($r['custom_icon'] ?: ($r['icon'] ?? '')) ?> me-2"></i><?= core_e($r['label'] ?: $r['name']) ?>
                                    <?php if (!$m): ?><span class="badge text-bg-warning">arquivos ausentes</span><?php endif; ?>
                                    <?php if ($m && !empty($m['description'])): ?><div class="small text-muted"><?= core_e($m['description']) ?></div><?php endif; ?>
                                </td>
                                <td>
                                    <input class="form-control form-control-sm" name="mod[<?= core_e($r['slug']) ?>][label]"
                                           value="<?= core_e($r['label'] ?? '') ?>" maxlength="100"
                                           placeholder="<?= core_e($m['name'] ?? $r['name']) ?>">
                                </td>
                                <td>
                                    <input class="form-control form-control-sm" name="mod[<?= core_e($r['slug']) ?>][icon]"
                                           value="<?= core_e($r['custom_icon'] ?? '') ?>" maxlength="60"
                                           placeholder="<?= core_e($m['icon'] ?? 'bi-app') ?>">
                                </td>
                                <td><input type="number" class="form-control form-control-sm" min="0" max="9999" name="mod[<?= core_e($r['slug']) ?>][sort]" value="<?= (int) $r['sort_order'] ?>"></td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" name="mod[<?= core_e($r['slug']) ?>][topbar]" value="1" <?= ($r['show_in_topbar'] ?? 1) ? 'checked' : '' ?>>
                                </td>
                                <td class="text-center">
                                    <input type="checkbox" class="form-check-input" name="mod[<?= core_e($r['slug']) ?>][active]" value="1" <?= $r['active'] ? 'checked' : '' ?>>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <button class="btn btn-primary mt-3">Salvar</button>
        </form>
        <?php
        admin_render('Módulos', (string) ob_get_clean(), 'modules');
        break;

    case 'settings':
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::check();
            Settings::set('org_name', trim((string) ($_POST['org_name'] ?? '')));
            Settings::set('default_hospital_id', (string) max(1, (int) ($_POST['default_hospital_id'] ?? 1)));
            // Notificações: os limites evitam tanto o "tempo real" que
            // multiplica a carga quanto o atraso de minutos.
            Settings::set('notifications.poll_active', (string) max(2, min(120, (int) ($_POST['notif_poll_active'] ?? 5))));
            Settings::set('notifications.poll_idle',   (string) max(10, min(600, (int) ($_POST['notif_poll_idle'] ?? 20))));
            Flash::set('success', 'Configurações salvas.');
            core_redirect('index.php?m=admin&a=settings');
        }
        ob_start(); ?>
        <h1 class="h4 mb-3"><i class="bi bi-sliders me-2"></i>Configurações</h1>
        <div class="row g-3">
            <div class="col-12 col-lg-6">
                <div class="card">
                    <div class="card-header">Geral</div>
                    <div class="card-body">
                        <form method="post">
                            <?= Csrf::field() ?>
                            <div class="mb-3">
                                <label class="form-label">Nome da organização (exibido no topo)</label>
                                <input class="form-control" name="org_name" value="<?= core_e(Settings::get('org_name', core_config('app.name', ''))) ?>">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ID da unidade padrão (módulos multi-unidade legados)</label>
                                <input type="number" class="form-control" name="default_hospital_id" value="<?= core_e(Settings::get('default_hospital_id', '1')) ?>">
                                <div class="form-text">Usado pelos módulos Documentos e Manutenção, que herdaram estrutura multi-unidade.</div>
                            </div>
                            <hr>
                            <h2 class="h6"><i class="bi bi-bell me-1"></i>Notificações</h2>
                            <p class="small text-muted">
                                De quanto em quanto tempo o sino procura novidades. Valores menores deixam a
                                notificação mais imediata e aumentam o número de consultas; a aba em segundo
                                plano usa o intervalo maior.
                            </p>
                            <div class="row g-3">
                                <div class="col-6">
                                    <label class="form-label" for="notifAtivo">Aba à frente (segundos)</label>
                                    <input type="number" class="form-control" id="notifAtivo" name="notif_poll_active"
                                           min="2" max="120" value="<?= core_e(Settings::get('notifications.poll_active', '5')) ?>">
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="notifOculto">Aba em segundo plano (segundos)</label>
                                    <input type="number" class="form-control" id="notifOculto" name="notif_poll_idle"
                                           min="10" max="600" value="<?= core_e(Settings::get('notifications.poll_idle', '20')) ?>">
                                </div>
                            </div>
                            <div class="form-text mb-3">Padrão: 5 s e 20 s (a mesma ordem de grandeza do chat).</div>

                            <button class="btn btn-primary">Salvar</button>
                        </form>
                    </div>
                </div>
            </div>
            <div class="col-12 col-lg-6">
                <div class="card mb-3">
                    <div class="card-header">E-mail (SMTP)</div>
                    <div class="card-body">
                        <?php if (core_config('mail.enabled')): ?>
                            <p><span class="badge text-bg-success">Habilitado</span>
                                <span class="small text-muted"><?= core_e(core_config('mail.host', '')) ?>:<?= core_e((string) core_config('mail.port', '')) ?> · de <?= core_e(core_config('mail.from', '')) ?></span></p>
                        <?php else: ?>
                            <p><span class="badge text-bg-secondary">Desabilitado</span></p>
                            <p class="small text-muted mb-0">Habilite em <code>config/config.php</code> (bloco <code>mail</code>) para o envio de comunicados,
                                pesquisas, alertas de vencimento e redefinição de senha. Os envios ficam na
                                <a href="<?= core_module_url('admin', ['a' => 'mail', 'tab' => 'queue']) ?>">fila de e-mails</a>.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="card">
                    <div class="card-header">Integração Moodle</div>
                    <div class="card-body">
                        <?php if (core_config('moodle.enabled')): ?>
                            <p><span class="badge text-bg-success">Habilitada</span></p>
                            <dl class="row small mb-0">
                                <dt class="col-4">URL</dt><dd class="col-8"><?= core_e(core_config('moodle.url')) ?></dd>
                                <dt class="col-4">Serviço</dt><dd class="col-8"><?= core_e(core_config('moodle.service')) ?></dd>
                                <dt class="col-4">Token admin</dt><dd class="col-8"><?= core_config('moodle.admin_token') ? 'configurado' : '—' ?></dd>
                            </dl>
                        <?php else: ?>
                            <p><span class="badge text-bg-secondary">Desabilitada</span></p>
                            <p class="small text-muted mb-0">Habilite em <code>config/config.php</code> (bloco <code>moodle</code>) para permitir login com credenciais do Moodle e auto-provisionamento de usuários.</p>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php
        admin_render('Configurações', (string) ob_get_clean(), 'settings');
        break;

    case 'audit':
        $module = trim((string) ($_GET['module'] ?? ''));
        $sql    = 'SELECT a.*, u.name AS user_name FROM audit_log a LEFT JOIN users u ON u.id = a.user_id';
        $par    = [];
        if ($module !== '') {
            $sql .= ' WHERE a.module = ?';
            $par[] = $module;
        }
        $rows = DB::query($sql . ' ORDER BY a.id DESC LIMIT 300', $par);
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-journal-text me-2"></i>Log de auditoria</h1>
            <form method="get" class="d-flex gap-2">
                <input type="hidden" name="m" value="admin"><input type="hidden" name="a" value="audit">
                <select class="form-select form-select-sm" name="module" onchange="this.form.submit()">
                    <option value="">Todos os módulos</option>
                    <?php foreach (array_merge(['core', 'admin'], array_keys(Modules::all())) as $slug): ?>
                        <option value="<?= core_e($slug) ?>" <?= $module === $slug ? 'selected' : '' ?>><?= core_e($slug) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        </div>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead><tr><th>Quando</th><th>Usuário</th><th>Módulo</th><th>Ação</th><th>Entidade</th><th>IP</th></tr></thead>
                    <tbody>
                    <?php foreach ($rows as $r): ?>
                        <tr>
                            <td class="text-nowrap"><?= core_e(date('d/m/Y H:i:s', strtotime((string) $r['created_at']))) ?></td>
                            <td><?= core_e($r['user_name'] ?? '—') ?></td>
                            <td><span class="badge text-bg-light border"><?= core_e($r['module'] ?? 'core') ?></span></td>
                            <td><?= core_e($r['action']) ?></td>
                            <td class="small text-muted"><?= core_e(trim(($r['entity'] ?? '') . ' #' . ($r['entity_id'] ?? ''), ' #')) ?></td>
                            <td class="small text-muted"><?= core_e($r['ip_address']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Auditoria', (string) ob_get_clean(), 'audit');
        break;

    // ================= ATUALIZAÇÕES DE BANCO (migrações) =================

    case 'migrations':
        $files   = Migrations::files();
        $applied = Migrations::applied();
        $pending = array_values(array_filter($files, fn ($f) => !isset($applied[$f])));
        $results = $_SESSION['_mig_results'] ?? null;
        unset($_SESSION['_mig_results']);
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-database-up me-2"></i>Atualizações de banco de dados</h1>
            <a class="btn btn-outline-primary" href="<?= core_module_url('admin', ['a' => 'health']) ?>">
                <i class="bi bi-heart-pulse me-1"></i>Checkup do sistema
            </a>
            <?php if ($pending): ?>
                <form method="post" action="<?= core_module_url('admin', ['a' => 'migrations_apply']) ?>"
                      onsubmit="return confirm('Aplicar <?= count($pending) ?> atualização(ões) pendente(s) no banco de dados agora?')">
                    <?= Csrf::field() ?>
                    <button class="btn btn-warning"><i class="bi bi-play-fill me-1"></i>Aplicar pendentes (<?= count($pending) ?>)</button>
                </form>
            <?php endif; ?>
        </div>
        <p class="text-muted small">Cada versão do sistema pode trazer alterações de estrutura (novas tabelas e colunas) em
            <code>sql/migrations/</code>. Os scripts são idempotentes: podem ser reaplicados com segurança. Recomenda-se um
            backup do banco antes de aplicar. Alternativa por linha de comando: <code>php scripts/migrate.php</code>.</p>
        <?php if ($results): ?>
            <?php foreach ($results as $r): ?>
                <div class="alert <?= $r['ok'] ? 'alert-success' : 'alert-danger' ?> py-2">
                    <strong><?= core_e($r['file']) ?></strong>: <?= $r['ok'] ? 'aplicada' : 'FALHOU' ?>
                    (<?= (int) $r['ran'] ?> comandos<?= $r['skipped'] ? ', ' . count($r['skipped']) . ' já existiam' : '' ?>)
                    <?php foreach ($r['errors'] as $e): ?><div class="small font-monospace mt-1"><?= core_e($e) ?></div><?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover mb-0 align-middle">
                    <thead><tr><th>Arquivo</th><th class="text-center">Status</th><th>Aplicada em</th></tr></thead>
                    <tbody>
                    <?php if (!$files): ?><tr><td colspan="3" class="text-center text-muted py-4">Nenhum arquivo de migração.</td></tr><?php endif; ?>
                    <?php foreach ($files as $f): ?>
                        <tr>
                            <td class="font-monospace small"><?= core_e($f) ?></td>
                            <td class="text-center"><?= isset($applied[$f]) ? '<span class="badge text-bg-success">aplicada</span>' : '<span class="badge text-bg-warning">pendente</span>' ?></td>
                            <td class="small text-muted"><?= isset($applied[$f]) ? core_e(date('d/m/Y H:i', strtotime((string) $applied[$f]))) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        admin_render('Atualizações de banco', (string) ob_get_clean(), 'migrations');
        break;

    case 'health':
        $grupos = Core\HealthCheck::all();
        $resumo = Core\HealthCheck::resumo($grupos);
        $cores  = ['ok' => 'success', 'aviso' => 'warning', 'erro' => 'danger', 'info' => 'secondary'];
        $icones = ['ok' => 'bi-check-circle-fill', 'aviso' => 'bi-exclamation-triangle-fill',
                   'erro' => 'bi-x-octagon-fill', 'info' => 'bi-info-circle-fill'];
        ob_start(); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <h1 class="h4 mb-0"><i class="bi bi-heart-pulse me-2"></i>Checkup de saúde do sistema</h1>
            <div class="d-flex gap-2">
                <a class="btn btn-outline-secondary" href="<?= core_module_url('admin', ['a' => 'migrations']) ?>">
                    <i class="bi bi-database-up me-1"></i>Atualizações de banco
                </a>
                <a class="btn btn-outline-primary" href="<?= core_module_url('admin', ['a' => 'health']) ?>">
                    <i class="bi bi-arrow-clockwise me-1"></i>Verificar de novo
                </a>
            </div>
        </div>

        <div class="alert alert-<?= $resumo['erro'] > 0 ? 'danger' : ($resumo['aviso'] > 0 ? 'warning' : 'success') ?> d-flex flex-wrap align-items-center gap-3">
            <div class="fs-2">
                <i class="bi <?= $resumo['erro'] > 0 ? 'bi-x-octagon' : ($resumo['aviso'] > 0 ? 'bi-exclamation-triangle' : 'bi-check-circle') ?>"></i>
            </div>
            <div class="flex-grow-1">
                <h2 class="h5 mb-1">
                    <?php if ($resumo['erro'] > 0): ?>
                        <?= (int) $resumo['erro'] ?> item(ns) precisam de atenção
                    <?php elseif ($resumo['aviso'] > 0): ?>
                        Nada impede o funcionamento, mas há <?= (int) $resumo['aviso'] ?> recomendação(ões)
                    <?php else: ?>
                        Tudo certo
                    <?php endif; ?>
                </h2>
                <div class="small">
                    <span class="badge text-bg-success"><?= (int) $resumo['ok'] ?> ok</span>
                    <span class="badge text-bg-warning"><?= (int) $resumo['aviso'] ?> atenção</span>
                    <span class="badge text-bg-danger"><?= (int) $resumo['erro'] ?> problema</span>
                    <span class="badge text-bg-secondary"><?= (int) $resumo['info'] ?> informação</span>
                    <span class="text-muted ms-2">verificado em <?= core_e(date('d/m/Y H:i')) ?></span>
                </div>
            </div>
        </div>

        <p class="text-muted small">
            Todos os itens são apenas leitura: esta página não altera nada no sistema. O que estiver marcado como
            problema vem com o que fazer a respeito.
        </p>

        <?php foreach ($grupos as $chave => $itens):
            $meta = Core\HealthCheck::GRUPOS[$chave] ?? ['label' => $chave, 'icon' => 'bi-dot'];
            $piores = ['erro' => 0, 'aviso' => 0];
            foreach ($itens as $i) { if (isset($piores[$i['nivel']])) { $piores[$i['nivel']]++; } }
        ?>
        <div class="card mb-3">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <i class="bi <?= core_e($meta['icon']) ?>"></i>
                <strong><?= core_e($meta['label']) ?></strong>
                <span class="ms-auto small">
                    <?php if ($piores['erro']): ?><span class="badge text-bg-danger"><?= $piores['erro'] ?> problema(s)</span><?php endif; ?>
                    <?php if ($piores['aviso']): ?><span class="badge text-bg-warning"><?= $piores['aviso'] ?> atenção</span><?php endif; ?>
                    <?php if (!$piores['erro'] && !$piores['aviso']): ?><span class="badge text-bg-success">tudo ok</span><?php endif; ?>
                </span>
            </div>
            <ul class="list-group list-group-flush">
                <?php foreach ($itens as $i): ?>
                <li class="list-group-item d-flex gap-3">
                    <div class="text-<?= $cores[$i['nivel']] ?? 'secondary' ?> pt-1">
                        <i class="bi <?= $icones[$i['nivel']] ?? 'bi-dot' ?>"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="fw-semibold"><?= core_e($i['titulo']) ?></div>
                        <div class="small text-body-secondary"><?= core_e($i['detalhe']) ?></div>
                        <?php if (!empty($i['acao'])): ?>
                            <div class="small mt-1">
                                <i class="bi bi-arrow-right-short"></i><strong>O que fazer:</strong> <?= core_e($i['acao']) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endforeach; ?>
        <?php
        admin_render('Checkup do sistema', (string) ob_get_clean(), 'migrations');
        break;

    case 'migrations_apply':
        Csrf::check();
        $results = Migrations::applyAll();
        $_SESSION['_mig_results'] = $results;
        $failed = array_filter($results, fn ($r) => !$r['ok']);
        Audit::log('migrations.apply', 'schema_migrations', null, ['files' => array_column($results, 'file'), 'failed' => count($failed)], null, 'admin');
        Flash::set($failed ? 'error' : 'success', $failed ? 'Uma atualização falhou — veja os detalhes abaixo.' : (count($results) . ' atualização(ões) aplicada(s).'));
        core_redirect('index.php?m=admin&a=migrations');
        break;

    // ================= FILA DE E-MAILS =================

    case 'mailqueue':
        // A fila virou uma aba da tela de E-mail (onde também ficam a
        // configuração, o teste de entrega e o diagnóstico). A rota antiga
        // continua valendo para links salvos e para o alerta da visão geral.
        core_redirect('index.php?m=admin&a=mail&tab=queue');
        break;

    case 'mailqueue_process':
        Csrf::check();
        $s = MailQueue::process(200);
        Flash::set('success', "Processado: {$s['sent']} enviado(s), {$s['failed']} falha(s), {$s['retried']} reagendado(s).");
        core_redirect('index.php?m=admin&a=mail&tab=queue');
        break;

    case 'mailqueue_retry':
        Csrf::check();
        $n = MailQueue::retryFailed();
        Flash::set('success', "{$n} e-mail(s) reenfileirado(s).");
        core_redirect('index.php?m=admin&a=mail&tab=queue');
        break;

    default:
        Layout::renderError(404, 'Ação não encontrada.');
}
